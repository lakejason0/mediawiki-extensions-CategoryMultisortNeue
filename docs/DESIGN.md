# Design notes

Why `CategoryMultisortNeue` is built the way it is, and the MediaWiki behaviours that
forced each decision. Most of these were found the hard way, so they are written down
here rather than left in commit messages.

- [The problem](#the-problem)
- [Data model](#data-model)
- [Default order must stay untouched](#default-order-must-stay-untouched)
- [Query and paging strategy](#query-and-paging-strategy)
- [Restating core's viewer](#restating-cores-viewer)
- [Integration points](#integration-points)
- [The control belongs to the skin](#the-control-belongs-to-the-skin)
- [Magic words and sort key precedence](#magic-words-and-sort-key-precedence)
- [Writing-system variants](#writing-system-variants)
- [Schema registration](#schema-registration)
- [Gotchas worth remembering](#gotchas-worth-remembering)

---

## The problem

MediaWiki computes one binary sort key per category link, at write time, with the
site-wide `$wgCategoryCollation`, and stores it in `categorylinks.cl_sortkey`.
Category pages are then simply `ORDER BY cl_sortkey`. The collation is a single global
value, so a wiki can have exactly one physical order per category.

For a `zh` wiki that is not enough: readers of simplified and traditional Chinese
expect different orders, and the popular ones genuinely disagree — pinyin is roughly
alphabetical, zhuyin groups by bopomofo, and stroke count orders by total strokes.
No single choice serves both audiences.

The original `CategoryMultisort` (2010) tried to solve this by generating its own
Chinese sort keys and extending `categorylinks`. It died for two reasons that are worth
not repeating: generating Chinese sort keys by hand became unnecessary once ICU's UCA
tailorings arrived, and tracking `categorylinks` through its many schema changes is a
maintenance burden with no end.

So the rules this extension sets for itself:

1. **Do not touch core.** Nothing under `includes/`, `skins/`, `resources/`, `sql/` or
   `maintenance/` is modified.
2. **Do not touch `categorylinks`.** The wiki's own order and sort keys stay exactly as
   core maintains them.
3. **Do not generate sort keys.** Use ICU through MediaWiki's own `IcuCollation`, the
   same code path core uses.
4. **Depend only on the stable parts of `categorylinks`**: `(cl_from, cl_target_id)`
   and the human-readable `cl_sortkey_prefix`.

## Data model

```
categorylinks (core, read-only for this extension)     categorylinks_multisort (this extension)
  PRIMARY KEY (cl_from, cl_target_id)        ─────┬──►  PRIMARY KEY (cms_from, cms_target_id, cms_mode)
  cl_sortkey_prefix  ← human-readable key          │     cms_mode      string(32)
  cl_sortkey         ← core key, left alone        │     cms_sortkey   blob, ≤230 bytes
  cl_collation_id                                  └───  INDEX cms_target_mode_sortkey
```

Decisions and their reasons:

- **The primary key mirrors `categorylinks`.** No page or category identity is
  duplicated, so a page move or a category rename cannot desynchronise anything: page
  ids and linktarget ids do not change on a move.
- **No auto-increment id.** MediaWiki's abstract schema maps
  `autoincrement` differently across SQLite and PostgreSQL, and there is no need for a
  surrogate key when the natural one is already unique.
- **No foreign keys**, following MediaWiki's own convention.
- **The sort key is stored, not derived.** Deriving it at read time would mean running
  ICU over every member of the category on every page view, and would make
  `ORDER BY` impossible against an index.
- **`blob`, not text.** ICU sort keys are arbitrary bytes and, for `stroke`, contain
  bytes that are not valid UTF-8. MySQL gets `BLOB`, SQLite `BLOB`, PostgreSQL
  `BYTEA`; all three compare bytewise, which is exactly what a sort key needs.
- **230 bytes**, the same truncation core applies in
  `maintenance/updateCollation.php`, so a key produced here is byte-identical to one
  produced by core for the same collation.
- **Default mode rows are stored too**, even though the default mode never reads them.
  `checkSortKeys` uses them to prove the two implementations agree, and they give the
  viewer a safe fallback if a non-default mode turns out to be unusable at render time.

## Default order must stay untouched

The single most important property: **an install that configures no extra mode must
render category pages byte for byte as core does.**

That is why `MultiSortCategoryViewer` branches on whether the selected mode means
"use `$wgCategoryCollation`":

| | Default mode | Any other mode |
| --- | --- | --- |
| Sort key | `cl_sortkey` | `cms_sortkey` |
| Table | `categorylinks` | `categorylinks` ⋈ `categorylinks_multisort` |
| Index | `cl_sortkey_id` | `cms_target_mode_sortkey` |
| Paging parameter | `pagefrom` / `pageuntil` | `cmspagefrom` / `cmspageuntil` |
| Paging value | human-readable prefix | base64url binary key |
| Grouping letters | core's collation | the mode's collation |
| Code path | core's own | this extension's |

This was verified in-process by rendering the same category through core's
`CategoryViewer` and through this extension's viewer and comparing the member listing
region byte for byte; the only difference is the portlet, which lives outside the
generated member list.

## Query and paging strategy

Two things needed rethinking.

**The offset has to be the binary key.** Core puts the *human-readable* prefix in
`pagefrom` and re-derives the binary key with `Collation::getSortKey()`. That works
only while one collation is in play: the same prefix lands in a different position
under a different collation, so pagination would skip and repeat members. Instead the
extension encodes `cms_sortkey` itself, base64url without padding, in its own
parameters (`cmspagefrom`, `cmssubcatfrom`, `cmsfilefrom` and the matching `…until`).
The query then compares the binary key directly, which is exact and reproducible.

**The tiebreaker has to be deterministic.** `ORDER BY cms_sortkey` alone is not a total
order: two members can share a key (identical titles in different namespaces, or two
pages given the same explicit sort key). `cl_from` is appended as a secondary sort, so
a page of results is stable and paging cannot skip or repeat a row.

## Restating core's viewer

`MultiSortCategoryViewer` extends `CategoryViewer` so that the three sections, the
column lists, the language conversion, the link generation and the count messages are
still core's own code.

What it must restate, and why:

| Core member | Visibility | Why it cannot simply be overridden |
| --- | --- | --- |
| `doCategoryQuery()` | `private` | Runs the member query; no hook fires before it |
| `getSectionPagingLinks()`, `pagingLinks()`, `addFragmentToTitle()` | `private` | Reached only from the section builders below |
| `formatList()`, `getCountMessage()` | `private` | Called from the section builders |
| `$cat`, `$query`, `$languageConverter` | `private` | Not readable from a subclass |
| `getSubcategorySection()`, `getPagesSection()`, `getImageSection()` | `protected` | These **are** overridable — and they must be, because a `private` call inside a method body is bound to the class that declares it |

That last row is the trap. Overriding only the `private` helpers produces dead code:
core's own copies keep being called from core's protected section builders, which is
how an earlier revision ended up emitting core's `pagefrom` while the query read
`cmspagefrom`, and the "next page" link restarted the same page forever.

So the section builders are restated in full, calling the extension's own private
helpers, which are renamed (`sectionPagingLinks`, `buildPagingLinks`, `formatList`,
`countMessage`) so that nobody mistakes them for overrides. The private state is
carried in the subclass (`$ownQuery`, `$ownCategory`, `$ownLanguageConverter`), and
`generateLink()` is restated as `buildMemberLink()`.

Because these are not a stable interface, the extension declares
`"MediaWiki": ">= 1.46.0"` and a future core release may need this file revisited.

Two more details in the same area:

- **Paging is asymmetric.** Forward: `$first` comes from `from[]`, `$last` from
  `nextPage[]`. Backward: `$first` comes from `nextPage[]` (the *first* item of the
  current page) and `$last` from `until`. Swapping them makes the next link repeat the
  current page. The extension keeps core's structure exactly and only changes the
  parameter names and the values they carry.
- **Grouping letters take the raw prefix.** Core's `addPage()` receives
  `Title::getCategorySortkey( $prefix )`, i.e. `"$prefix\n$title"`. The extension needs
  the bare `cl_sortkey_prefix` so it can feed it to the *mode's* collation, so the
  member methods have extension-specific entry points (`addPageMember`,
  `addImageMember`, `addSubcategoryObjectMember`) rather than overriding core's.

## Integration points

| Hook | Used for | Why this hook |
| --- | --- | --- |
| `ArticleFromTitle` | Swap in `MultiSortCategoryPage` | `CategoryPage` chooses its viewer class from a protected property, and the viewer runs its query before consulting any hook, so replacing the page class is the only way in without patching core |
| `SidebarBeforeOutput` | Offer the sort mode portlet | Documented extension point for adding a portlet; runs per page view, unlike the cached `SkinBuildSidebar` |
| `LoadExtensionSchemaUpdates` | Create the table | Standard schema registration |
| `ParserFirstCallInit` | Register the two magic words | Standard parser function registration |
| `LinksUpdateComplete` | Keep the table current after edits | Runs after `categorylinks` has been rewritten, so the prefixes are final |
| `ArticleDeleteComplete` | Drop the page's rows | `LinksUpdate` does not run on deletion |
| `GetPreferences` | Add the preference | Standard |
| `BeforePageDisplay` | Load the client module on category pages | Standard |

Everything is declared through `extension.json`; there is no `require_once` loading of
classes.

## The control belongs to the skin

An earlier revision rendered a `<form><select>` into the article body. That was wrong:
a control like this is a **skin** concern. Only the skin knows whether it should be a
sidebar box, a collapsible menu, a toolbar dropdown or tabs; only the skin knows where
it goes, how it collapses and how it behaves on narrow screens. Hand-rolling that in
the body means reimplementing all of it and going stale when the skin changes.

So the extension supplies **data** and lets the skin render:

```php
$sidebar['categorymultisortneue'] = [ /* one sidebar item per mode */ ];
```

The array key does the work. Core derives the portlet id (`p-categorymultisortneue`),
the class (`mw-portlet-categorymultisortneue`) and the heading (the message with that
same key), so no skin needs to know this extension exists.

Measured on the bundled skins: Vector 2022 renders it as a collapsible menu, legacy
Vector / MonoBook / Timeless as a sidebar box, and **Minerva does not render sidebar
portlets at all** — its main menu is built by its own director from a fixed set of
portlets, with no skin-agnostic injection point. That is what
`$wgCategoryMultisortNeueInlineFallback` is for: an opt-in copy of the same links in
the page body, built from the same item array so the two cannot drift, and removed by
the client module whenever the skin did render the portlet.

See [Gotchas](#gotchas-worth-remembering) for the `tooltip` trap in sidebar items.

## Magic words and sort key precedence

Per-mode sort keys are stored as page properties, and `LinksUpdateComplete` reads them
back when recomputing the table. Two property families, both prefixed:

| Property | Set by | Meaning |
| --- | --- | --- |
| `cms-defaultsort-<mode>` | `{{#multisortdefaultsort:<mode>|<key>}}` | Page-level default for that mode |
| `cms-sortkey-<mode>` | `{{#multisortsortkey:<mode>|<category>|<key>}}` | Per-category override, serialised as `<category dbkey>\t<key>` lines |

Storing the *user's* key rather than a computed sort key is deliberate: it keeps the
property readable and debuggable, and it means a wiki that changes a mode's collation
only needs `rebuildSortKeys`, not a re-parse of every page.

`rebuildSortKeys` and `checkSortKeys` read the same properties with the same
precedence as `LinksUpdateComplete`. Without that, a full rebuild would overwrite every
per-mode key that a magic word had set, and `checkSortKeys` would report mismatches
against its own idea of the key.

## Writing-system variants

A wiki whose language has variants — Chinese above all — can serve one body of text in
several writing systems, and readers choose which. Category pages do not follow that
choice, and with shape-based collations the result is unusable as an index: `体` and
`體` are the same word but 7 and 23 strokes, so stroke order scatters them.

Measured on a category of 12 variant pairs, stroke mode: **0 of 12 pairs adjacent**,
with pairs as far apart as positions 6 and 45 out of 57. After folding, 12 of 12 are
adjacent, all 12 share a grouping heading, and the order is identical for every
variant a reader can select.

The fix is to fold both halves of the sort key input — the title text and the
`cl_sortkey_prefix` — to one canonical variant before the collation sees them.
`VariantFolder` owns that.

Three things about MediaWiki made this less obvious than it looks:

1. **`convertCategoryKey()` cannot be used, despite its name and its documentation.**
   `ILanguageConverter::convertCategoryKey()` says it "should make different keys that
   are variants of each other map to the same key", and for Chinese it is implemented
   as `autoConvert( $key, 'zh' )`. But `zh` is a base language code, not a conversion
   target: `autoConvert( '体體', 'zh' )` returns `体體` unchanged. Measured over 15
   variant pairs, `convertCategoryKey()` collapses **zero** of them. An explicit
   variant is required.

2. **The canonical variant must be a conversion *target*, not merely a valid variant.**
   `zh` is in `ZhConverter::getVariants()` and `validateVariant( 'zh' )` accepts it, so
   the first implementation resolved the canonical variant to `zh` and silently folded
   nothing. The reliable signal is `getVariantsFallbacks()`, which maps each variant to
   the variants it can fall back to: a variant appearing only as a *key* cannot be
   converted to. `ZhConverter::getAdditionalManualLevel()` marks `zh` as `'disable'`
   for the same reason.

3. **The conversion tables are not symmetric.** Folding towards simplified collapses
   all 15 pairs measured; folding towards traditional collapses only 13, because
   `里/裡` and `台/臺` do not merge in that direction. So `zh-hans` is the fallback
   rather than `zh-hant`, and the site's own variant wins when it is a concrete one.

Two consequences worth stating:

- **Grouping letters have to be folded as well.** Otherwise a page can be sorted next
  to its variant twin but filed under a different heading, which is worse than either
  failure on its own.
- **Folding makes the order variant-independent**, which is the actual goal: a category
  is a shared index, not a per-reader view. If the order changed with the reader's
  variant, the same category would page differently for different readers.

Pinyin and zhuyin keep variant pairs together already, because they sort by sound
rather than shape, and they still cannot merge them: `发`/`發` are genuinely different
readings, and MediaWiki has no mechanism to declare a page's reading. That is what the
per-mode magic words are for.

The whole mechanism short-circuits on a wiki without variants: the canonical variant
resolves to null and no conversion is attempted, so an ordinary wiki pays nothing.

### Why the difference is real

An earlier revision of this branch folded every variant to one canonical form so that a
single sort key per member would be enough. That was the wrong goal: a reader who
switches variant is *supposed* to see a different order, and `convertCategoryKey()`
exists precisely to make keys **not** vary. One measurement is what settled it.

Variant pairs differ at **PRIMARY** collation strength in every Chinese collation.
Measured with PHP's `Collator`: `体` is `a10d16` and `體` is `a3d436` under
`zh@collation=unihan`. They are not the same character with a tie-breaker — ICU places
them in genuinely different positions, because they are different characters with
different shapes and sometimes different readings. So there is no "correct" single
order to fold towards, and the reader's variant is the only sensible thing to order by.

### One sort key per variant

The table carries a `cms_variant` column, and a key is stored for every
(member, category, mode, variant) combination that the wiki configures. A reader is
served the key for their own variant.

The variant text comes from the converter's own title API rather than a second guess at
which characters to convert:

| API | Variant-correct? | Notes |
| --- | --- | --- |
| `ParserOutput::getTitleText()` | yes | set during parse; honours `-{T\|}-` first, otherwise `convertSplitTitle()`. Authoritative, but only where parser output exists. |
| `LanguageConverter::convertSplitTitle( $title, $variant )` | yes | namespace / separator / main text for an explicit variant. Works anywhere, including a maintenance rebuild. |
| `LanguageConverter::getConvRuleTitle()` | yes | the `-{T\|}-` value, or `false`. Only meaningful on a converter that has parsed the page. |
| `LanguageConverter::convertTitle( $title )` | only with a variant | without a variant argument it consults the request context, which a maintenance script does not have — this is what made it look like a no-op |
| `Title::getText()` / `getDBkey()` | no, by design | the stored title, which is what the category query needs |

Measured, `convertSplitTitle()` handles the mixed state correctly and agrees with the
converter's tables:

```
體          zh => 體   zh-hans => 体   zh-hant => 體
体          zh => 体   zh-hans => 体   zh-hant => 體
繁體標題    zh => 繁體標題  zh-hans => 繁体标题  zh-hant => 繁體標題
转换标题测试 zh => 转换标题测试 zh-hans => 转换标题测试 zh-hant => 轉換標題測試
```

**`zh` is the un-converted state**, and that is the default variant. It is not "no
variant": it is the wiki's own "原文" variant, and it is what a reader sees when they
have chosen not to convert. Every page measured returns its own title unchanged under
it, so a `zh` key is the order of the text exactly as written — which is the right
default for a wiki that mixes variants and for the many readers who browse without
conversion.

The grouping letter is derived from the same per-variant text, so it changes with the
variant too, as it must in order to stay consistent with the order:

```
体  zh => 体 ⠇   zh-hans => 体 ⠇   zh-hant => 體 ⠗
万  zh => 万 ⠃   zh-hans => 万 ⠃   zh-hant => 萬 ⠍
```

A reader whose preferred variant has no stored keys falls back to the default variant,
so a wiki that configures only some variants still works.

### Paging across variants

Per-variant keys change what paging has to do, and two bugs only become reachable once
variant twins exist.

**Cursors carry the row, the ordering and the comparison.** Members sort by
`(cms_sortkey, cms_from)`, so a sort key alone does not identify a row. Variant twins
share a sort key — 12 tie groups in `zh-hans` against 0 in `zh` — so a cursor naming only
the key served the first twin again on the next page. A cursor now holds all three of:

    [direction][key operator][id operator][4-byte key length][key][8-byte page id]

with the operators encoded as single bytes (`g` `G` `l` `L`), because two of them are
two characters wide and a variable-width prefix cannot be framed unambiguously. The
comparison has to travel with the cursor: a control that moves *on* past a boundary row
excludes it, while one that moves *back* over it includes it, and which of the two is
wanted does not follow from the row or from the walk direction.

**Section links must not inherit each other's cursors.** `$query` arrives from the
request and is restated into each section's links. It carries `cmspagefrom`, which then
landed in the *subcategory* section's links as well. Following a subcategory link threw
the page section back to its previous cursor and the same page of members was served
twice. Every paging parameter is now dropped from `$query` on the way in; each section
builds its own.

**Forward paging is verified** over HTTP for four variants and two modes: 11-12 pages
each, `dupes=0 missing=0 extra=0` against a single-page baseline, the chain stops on the
real last page instead of emitting one empty page past it, and the walk is reproducible
from the cursors alone.

**Backward paging is exact for one step and one page short beyond that.** Stepping
forward and then back reproduces the previous page exactly — `forward 1 then back 1: YES`
— which is the gesture a reader actually makes. Chained backward steps land one page too
far forward: after n steps forward and n back the reader reaches page n rather than page 1.

Each control is a cursor over a boundary row, and the single-step case is exact because
page 2's low row is the first row after page 1, so a backwards read from it lands on page
1. From page 3 onward that row is already inside the page being reproduced, so the walk
stops one page short.

Three things are recorded for whoever picks this up, including one that is a warning:

- **The rule in the code is measured, not guessed.** `tools/probe-cursor-matrix.php` tries
  every combination of the two boundary rows against the two orderings and prints where
  each leads. Next from the high row and previous from the low row, both exclusive, with
  previous ordered backwards, is the only pair found that makes the single step exact
  without disturbing the forward walk.

- **A third token is needed, and the obvious ways to derive it are wrong.** The page a
  "previous" link lands on was itself reached by "next", so it holds a back-offset of its
  own, and the link has to carry that one. Carrying the emitted token, the arrived token,
  and the inverse of the arrived token were each tried during this work and each broke a
  different case. The invariant that makes the carry exact is that replaying the offset
  which reaches page N reproduces page N (`tools/probe-prev-rules.php` demonstrates it);
  none of those three carries implements it.

- **A reversed page compares equal to the correct one if only the member set is checked.**
  A probe written during this work compared sets and reported success while the rendered
  order was reversed, which sent several attempts in the wrong direction before the
  mismatch was spotted. Any future attempt must compare rendered order;
  `tools/probe-roundtrip-paging.php` and `tools/probe-prev-gesture.php` both do, and are
  the checks to run.

Neither gap affects the acceptance suite, whose paging check walks forwards, nor
`core-diff`, which pins the default mode to byte-identical core output.

### Readings that cannot be derived

A collation stores one reading per character, so anything context-dependent needs an
explicit sort key. Verified on real pages:

- **`沧浪`** as a colour is *Cāng láng*, not *Cāng làng*. With
  `{{#multisortdefaultsort:pinyin|canglang}}`, it and the near-homophone `苍浪` get
  **byte-identical** pinyin keys (`aaa6c0b2bca6c0b2`) despite sharing no characters,
  and both land under `C`. `苍狼`, which was left alone, sorts first by its derived
  reading.
- **`重`** is *chóng* in 重庆 and *zhòng* in 重量. With per-page keys they land under
  `C` and `Z` respectively; without them, no single character-derived key could place
  both.
- **Cross-strait readings cannot be reconciled by one key.** `淆` is *xiáo* in the
  mainland standard and *ㄧㄠˊ* in the Taiwan standard. ICU ships one reading: `淆`
  sorts immediately **before** `小` in both pinyin and zhuyin, i.e. neither of the two
  standard's positions relative to the *xiao* and *yao* families. A wiki that needs
  both would need one key per variant, which is the same per-variant design described
  above.

ICU's Chinese collations do rank by reading: over 40 single-reading characters, the
collation order agreed with alphabetical pinyin order for all 40.

## Schema registration

The table is declared once, in `sql/categorylinks_multisort.json`, and the per-DBMS SQL
under `sql/<dbms>/` is generated from it. `SchemaHooks` registers it through
`addExtensionTable()`.

Note the registration form: `LoadExtensionSchemaUpdates` must be wired through
`HookHandlers`, not as a bare `"Class::method"` string. A bare string is treated as a
legacy handler and lands in `$wgHooks`, while the extension-schema part of the updater
builds its `HookContainer` from the registry's `attributes.Hooks` only. The symptom is
that `update.php` never creates the table **and reports nothing at all**.

## Gotchas worth remembering

Collected here because each one cost real debugging time.

1. **`private` methods do not participate in polymorphism.** A `private` call inside a
   method body is bound to the declaring class, so a subclass "override" is dead code.
   Affects `CategoryViewer` as described above. The same applies to `private`
   properties, which a subclass cannot even read.

2. **`ResourceModules` needs a base path.** A module with neither `localBasePath` nor a
   top-level `ResourceFileModulePaths` falls back to `$IP`, so
   `"scripts": "modules/x.js"` is looked up as `$IP/modules/x.js`. `load.php` then
   returns `mw.loader.state({"x":"error"})` — **the module silently does not load and
   server-side rendering looks perfectly fine.** Always verify with:

   ```bash
   curl -s "http://localhost:8080/load.php?lang=en&modules=ext.categoryMultisortNeue&only=scripts&skin=vector" \
     | grep -o 'ext.categoryMultisortNeue":"[a-z]*"'
   ```

   It must say `ready`, not `error`.

3. **`tooltip` in a sidebar item is a message key, not free text.**
   `SkinComponentLink::applyLinkTitleAttribs()` resolves it with `Linker::titleAttrib`,
   so passing an already-localised description produces a meaningless
   `tooltip="…"` attribute. Use a plain `title` attribute for literal text.

4. **`getFirstLetter()` is not available for every collation that can sort.** An ICU
   locale group not registered in core's `languages/data/first-letters-root.php`
   throws `RuntimeException: MediaWiki does not support ICU locale "…"`. `uca-zh` and
   `uca-zh-Hant@collation=stroke` are examples. `SortMode::create()` exercises both
   `getSortKey()` and `getFirstLetter()` when a mode is registered, so a bad
   configuration hides the mode instead of breaking the category page.

5. **Grouping letters need the bare prefix.** Passing core's combined sort key into a
   second `getCategorySortkey()` call duplicates the title. Harmless for the first
   character in practice, wrong in principle.

6. **Verify the client module loads.** PHP has `php -l`; JavaScript does not get
   checked by it. Keep at least a syntax check and one real
   `load.php` request in the routine, because both a broken module and a missing base
   path are invisible from the server side.

7. **`$wgCategoryCollation` is read at write time.** Changing it does not reorder
   anything until `maintenance/updateCollation.php` runs. The same is true of this
   extension's modes: after editing `$wgCategoryMultisortNeueModes`, run
   `rebuildSortKeys`.

8. **Anything that changes how a sort key is computed needs a rebuild, and there is no
   way to detect a stale key.** `checkSortKeys` recomputes with the current settings,
   so after enabling variant folding it reports every existing key as a mismatch until
   `rebuildSortKeys` runs. That is the correct behaviour — the keys really are stale —
   but it means the setting cannot be switched on quietly in a release.
