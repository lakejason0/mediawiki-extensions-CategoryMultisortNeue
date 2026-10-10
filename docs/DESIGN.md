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
