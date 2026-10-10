# CategoryMultisortNeue

`CategoryMultisortNeue` lets **one category page be read under several different
collations at once**, while the wiki keeps a single `$wgCategoryCollation`.

MediaWiki stores exactly one binary sort key per category link
(`categorylinks.cl_sortkey`), computed with the site-wide collation at write time, so
a category page can only ever have one physical order. That is a real problem for a
`zh` wiki: simplified and traditional Chinese readers expect different orders
(pinyin, zhuyin, stroke count, …), and one of them will always lose.

This extension keeps `categorylinks` untouched and stores the extra sort keys in a
side table, then offers the available orders to the skin as an ordinary
[portlet](#the-sort-mode-portlet) that readers can pick from — or set once in their
preferences.

|  |  |
| --- | --- |
| **MediaWiki** | 1.46.0 (developed and tested against 1.46.0; see [Compatibility](#compatibility)) |
| **Database** | MySQL/MariaDB, SQLite, PostgreSQL (uses MediaWiki's abstract schema) |
| **Licence** | [GPL-2.0-or-later](COPYING) |
| **Status** | Working; verified end to end on a 1.46.0 wiki with four collations |

> The extension is not registered on mediawiki.org yet. To add it there, create
> `Extension:CategoryMultisortNeue` with the usual `{{Extension}}` infobox pointing at
> this repository; nothing in the code needs to change first.

---

## Table of contents

- [What it does](#what-it-does)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
  - [`$wgCategoryMultisortNeueModes`](#wgcategorymultisortneuemodes)
  - [`$wgCategoryMultisortNeueDefaultMode`](#wgcategorymultisortneuedefaultmode)
  - [`$wgCategoryMultisortNeueShowSelector`](#wgcategorymultisortneueshowselector)
  - [`$wgCategoryMultisortNeuePreference`](#wgcategorymultisortneuepreference)
  - [`$wgCategoryMultisortNeueWriteOnEdit`](#wgcategorymultisortneuewriteonedit)
  - [`$wgCategoryMultisortNeueInlineFallback`](#wgcategorymultisortneueinlinefallback)
- [Choosing collations](#choosing-collations)
- [Magic words](#magic-words)
- [The sort mode portlet](#the-sort-mode-portlet)
- [User preference](#user-preference)
- [Maintenance scripts](#maintenance-scripts)
- [Database schema](#database-schema)
- [How it works](#how-it-works)
- [System messages](#system-messages)
- [Troubleshooting](#troubleshooting)
- [Compatibility](#compatibility)
- [Development](#development)

---

## What it does

Given a configuration such as

```php
$wgCategoryMultisortNeueModes = [
	'default' => '',
	'pinyin'  => 'uca-zh@collation=pinyin',
	'zhuyin'  => 'uca-zh@collation=zhuyin',
	'stroke'  => 'uca-zh@collation=stroke',
];
```

the same category page renders in four different orders:

| Mode | Group headings on the page | Member order (excerpt) |
| --- | --- | --- |
| `default` (`$wgCategoryCollation`) | `1 A C R ⼀ ⼁ ⼆ ⼈ …` | 12345 … 一 上海 中 中国 … |
| `pinyin` | `1 A B C D E G H M N P R S X Y Z` | 12345 爱 安 … 水 小 一 中 中国 |
| `zhuyin` | `1 ㄅ ㄆ ㄇ ㄉ ㄋ ㄍ ㄏ ㄒ ㄓ ㄕ ㄖ ㄘ ㄞ ㄢ ㄦ ㄧ A C R` | 12345 白 北京 … 才 爱 安 … 二 一 |
| `stroke` | `1 ⠁ ⠂ ⠃ ⠄ ⠅ ⠆ ⠇ ⠈ ⠊ ⠋ A C R` | 12345 一 二 人 十 上海 … 你 的 爱 |

The order is applied to the **whole category**, not just the visible page: paging
walks the members in the selected order with no gaps and no repeats.

Three things follow from the design:

- **The site default order is untouched.** When the mode that means "use
  `$wgCategoryCollation`" is selected, the query, the sort key and the paging
  offsets are exactly the core ones. A wiki that installs this extension and never
  configures a second mode renders category pages byte for byte as before.
- **Pinyin/zhuyin/stroke order comes from ICU**, through MediaWiki's own
  `IcuCollation` — the same algorithm core uses. This extension generates no sort
  keys of its own.
- **Nothing in MediaWiki core is modified.**

## Requirements

- MediaWiki 1.46.0 (see [Compatibility](#compatibility))
- PHP 8.3 or later (whatever your MediaWiki requires) with the `intl` extension,
  which MediaWiki already requires for `IcuCollation`
- A database supported by MediaWiki's abstract schema
- For the Chinese collations below, an ICU build that ships the `zh` collation
  tailorings — the standard ICU data files do

## Installation

1. Put the extension in your `extensions/` directory and add to
   `LocalSettings.php`:

   ```php
   wfLoadExtension( 'CategoryMultisortNeue' );
   ```

2. Configure the sort modes you want (see
   [`$wgCategoryMultisortNeueModes`](#wgcategorymultisortneuemodes)). Without any
   configuration the extension adds a single mode, `default`, which is the wiki's own
   collation, so nothing changes until you add more.

3. Create the extension table and populate it:

   ```bash
   php maintenance/run.php update --quick
   php maintenance/run.php CategoryMultisortNeue:rebuildSortKeys
   ```

4. Check that the stored keys agree with their collations:

   ```bash
   php maintenance/run.php CategoryMultisortNeue:checkSortKeys
   ```

   It exits non-zero when something is wrong, so it can be used as a deploy gate.

After that, every edit keeps the table up to date on its own (see
[`$wgCategoryMultisortNeueWriteOnEdit`](#wgcategorymultisortneuewriteonedit)). You
only need `rebuildSortKeys` again after changing the configured modes.

## Configuration

All settings go in `LocalSettings.php`, after `wfLoadExtension( 'CategoryMultisortNeue' )`.

### `$wgCategoryMultisortNeueModes`

| | |
| --- | --- |
| **Type** | `array<string, string>` |
| **Default** | `[ 'default' => '' ]` |

The map of sort modes offered on category pages.

- **Key** — the mode id. It is what appears in the `?cmsmode=` URL parameter, in the
  magic words, and in the system message key that names the mode
  (`categorymultisortneue-mode-<id>`). Must match `[A-Za-z0-9_-]{1,32}`; anything else
  is ignored with a warning in the `CategoryMultisortNeue` log channel.
- **Value** — the collation name, as understood by MediaWiki's `CollationFactory`.
  An **empty string means "use `$wgCategoryCollation`"**, which is how the wiki's own
  order is represented.

Modes appear on the page in configuration order.

```php
$wgCategoryMultisortNeueModes = [
	// The wiki's own order, always worth keeping so readers can get back to it.
	'default' => '',
	'pinyin'  => 'uca-zh@collation=pinyin',
	'zhuyin'  => 'uca-zh@collation=zhuyin',
	'stroke'  => 'uca-zh@collation=stroke',
];
```

Any collation MediaWiki can build works, not only Chinese ones — `uppercase`,
`numeric`, `identity`, `uca-default`, `uca-<locale>`, `pinyin-<locale>`, and so on.
A mode whose collation cannot be used is **hidden rather than fatal**: see
[Troubleshooting](#troubleshooting).

### `$wgCategoryMultisortNeueDefaultMode`

| | |
| --- | --- |
| **Type** | `string` |
| **Default** | `'default'` |

The mode id selected for a reader who has neither chosen one in the URL nor set a
preference. If it does not name an available mode, the first available mode in
`$wgCategoryMultisortNeueModes` is used instead.

### `$wgCategoryMultisortNeueShowSelector`

| | |
| --- | --- |
| **Type** | `bool` |
| **Default** | `true` |

Whether to offer the [sort mode portlet](#the-sort-mode-portlet). Setting this to
`false` leaves the reader with the URL parameter and the preference only.

### `$wgCategoryMultisortNeuePreference`

| | |
| --- | --- |
| **Type** | `bool` |
| **Default** | `true` |

Whether to add the **"Sorting of category pages"** preference on
`Special:Preferences` (Appearance → Advanced options), which stores a preferred mode
per user.

Because a preference is not part of the URL, pages rendered that way are marked
non-shareable (`disableClientCache()`), so a proxy cannot hand one reader's order to
another. Requests that carry `?cmsmode=` stay cacheable, and anonymous readers, who
have no preference, keep shareable responses.

### `$wgCategoryMultisortNeueWriteOnEdit`

| | |
| --- | --- |
| **Type** | `bool` |
| **Default** | `true` |

Whether edits maintain the extension table incrementally through the
`LinksUpdateComplete` hook.

- `true` (default) — every edit that changes a page's categories recomputes that
  page's rows. Measured at 12 SQL statements regardless of how many rows the page
  has (1 select + 1 delete + per mode: 1 select + 1 multi-row insert).
- `false` — the extension table is written only by
  [`rebuildSortKeys`](#categorymultisortneuerebuildsortkeys). Use this if you would
  rather run the rebuild on a schedule, or if edits must stay as cheap as possible.

Either way, page deletion still removes the page's rows
(`ArticleDeleteComplete`).

### `$wgCategoryMultisortNeueInlineFallback`

| | |
| --- | --- |
| **Type** | `bool` |
| **Default** | `false` |

Whether to **also** render the sort choices in the page body, as a small list of
links above the member list.

The portlet is the intended control and is what skins style and collapse. This
fallback exists because **Minerva does not render sidebar portlets at all** — its
main menu is assembled by Minerva's own builders from a fixed set of portlets, so
there is no skin-agnostic way to inject into it. On a wiki whose readers use
Minerva, turn this on:

```php
$wgCategoryMultisortNeueInlineFallback = true;
```

The fallback is built from the same items as the portlet, so the two cannot drift
apart, and the JavaScript module **removes the fallback whenever the skin did render
the portlet** — no reader ever sees the choice twice. Without JavaScript both copies
are present on skins that render portlets; that is the price of covering Minerva, and
it is why this is opt-in.

## Choosing collations

Values are passed straight to `CollationFactory::makeCollation()`. Useful ones:

| Collation name | What it does |
| --- | --- |
| `''` (empty) | Use `$wgCategoryCollation` |
| `uca-zh@collation=pinyin` | Chinese by Hanyu Pinyin; also `zhuyin`, `stroke`, `unihan` |
| `uca-zh-u-co-unihan-kr-digit-latn-hani` | Unihan (Kangxi radical) order, a common `zh` default |
| `pinyin-zh` | MediaWiki's built-in Pinyin collation (since 1.46) |
| `uca-default`, `uca-default-u-kn` | Root ICU order, optionally with numeric sorting |
| `uppercase`, `numeric`, `identity` | Simple core collations |
| `uca-<locale>` | Any ICU locale, e.g. `uca-de-u-co-phonebk` |

Two things to know:

- **Every mode's collation is validated at load time**, by calling both
  `getSortKey()` and `getFirstLetter()` on it. A collation that throws is hidden from
  the portlet and never used for rendering, so a typo in the configuration cannot
  take category pages down.
- **Some ICU locale groups are not usable for grouping letters.** For example
  `uca-zh` and `uca-zh-Hant@collation=stroke` can produce sort keys but not first
  letters, because their locale group is not registered in core's
  `languages/data/first-letters-root.php`. Those modes are hidden by the validation
  above; write `uca-zh@collation=pinyin` (or `pinyin-zh`) instead.

## Magic words

Two parser functions let a page override its sort keys **for one mode without
affecting the others**, which is the point: one page can be sorted one way for pinyin
readers and another for zhuyin readers.

### `{{#multisortdefaultsort:<mode>|<sortkey>}}`

Sets the page's default sort key for one mode, the way `{{DEFAULTSORT:}}` does for
the site collation.

```
{{#multisortdefaultsort:pinyin|Beijing}}
{{#multisortdefaultsort:zhuyin|ㄅㄟˇ}}
[[Category:Cities]]
```

### `{{#multisortsortkey:<mode>|<category>|<sortkey>}}`

Sets the sort key of **one category** for one mode, the way a pipe does in
`[[Category:Cities|Beijing]]`.

```
{{#multisortsortkey:stroke|Category:Cities|一}}
```

Precedence, highest first:

1. `{{#multisortsortkey:<mode>|…}}` for that category and mode;
2. the explicit key in `[[Category:X|key]]` / `{{DEFAULTSORT:key}}`;
3. `{{#multisortdefaultsort:<mode>|…}}`;
4. the page title.

Notes:

- Both are ordinary parser functions, so they work from templates and are attributed
  to the page that *transcludes* them, exactly like `{{DEFAULTSORT:}}`.
- Passing an empty key (`{{#multisortdefaultsort:pinyin|}}`) clears the override.
- An unknown mode, an empty mode, a missing argument or a non-category name produces
  a localised inline error and sets nothing.
- `{{#multisortdefaultsort:default|…}}` only changes the extension table's `default`
  row. The `default` mode renders through core's `cl_sortkey`, so the page itself will
  not look different — use `{{DEFAULTSORT:}}` for that.

## The sort mode portlet

The control is a **skin portlet**, not a widget in the article body. The extension
only supplies data through the `SidebarBeforeOutput` hook; the skin decides what it
looks like and where it goes.

Expressed in the standard sidebar item shape, one link per mode:

```php
$sidebar['categorymultisortneue'] = [
	[
		'text' => 'Pinyin',
		'href' => '/wiki/Category:Cities?cmsmode=pinyin',
		'id' => 'cms-mode-pinyin',
		'class' => 'cms-sortmode-link',
		'title' => 'Sort Chinese by Hanyu Pinyin, A to Z.',
		'aria-label' => 'Sort by Pinyin',
		'active' => true,          // marks the mode in effect
		'aria-current' => 'true',
	],
	// …
];
```

Because the array key becomes the portlet name, core derives everything else:

| Derived from the key `categorymultisortneue` | Value |
| --- | --- |
| Portlet id | `p-categorymultisortneue` |
| Portlet class | `mw-portlet mw-portlet-categorymultisortneue` |
| Heading | the message `categorymultisortneue` |

Rendered by Vector 2022:

```html
<div id="p-categorymultisortneue" class="vector-menu mw-portlet mw-portlet-categorymultisortneue">
  <div class="vector-menu-heading">Sort category by</div>
  <ul class="vector-menu-content-list">
    <li id="cms-mode-pinyin" class="cms-sortmode-link mw-list-item active">
      <a href="…&amp;cmsmode=pinyin" aria-current="true" title="…"><span>Pinyin</span></a>
    </li>
    …
  </ul>
</div>
```

Measured behaviour on the bundled skins:

| Skin | Portlet rendered? | Where |
| --- | --- | --- |
| Vector 2022 | yes | collapsible menu in the main menu |
| Vector (legacy) | yes | sidebar box |
| MonoBook | yes | sidebar box |
| Timeless | yes | sidebar box |
| Minerva | **no** | use [`$wgCategoryMultisortNeueInlineFallback`](#wgcategorymultisortneueinlinefallback) |

The portlet only appears on category pages, and only when at least two modes are
available and `$wgCategoryMultisortNeueShowSelector` is on. The links are plain
anchors, so they work without JavaScript; the small client module only mirrors the
current mode to `aria-current` and removes the inline fallback when the portlet is
present.

## User preference

With `$wgCategoryMultisortNeuePreference` enabled, `Special:Preferences` gains a
**"Sorting of category pages"** selector under Appearance → Advanced options, one
option per available mode plus "Wiki default". The preference is stored as the mode
id in the `categorymultisortneue-sortmode` user option.

Resolution order for the mode a request is rendered in:

1. the `cmsmode` query parameter, when it names an available mode;
2. the reader's preference, when it names an available mode;
3. `$wgCategoryMultisortNeueDefaultMode`, else the first available mode.

## Maintenance scripts

Run them through `run.php` with the extension prefix, or directly by path:

```bash
php maintenance/run.php CategoryMultisortNeue:rebuildSortKeys [options]
php extensions/CategoryMultisortNeue/maintenance/rebuildSortKeys.php [options]
```

The `CategoryMultisortNeue:` prefix is required when going through `run.php`;
without it the runner only looks in core's own `maintenance/` directory.

### `CategoryMultisortNeue:rebuildSortKeys`

Recomputes the extension table. Everything is recomputed from `categorylinks`, so it
is safe and idempotent: already-correct rows are left alone.

| Option | Meaning |
| --- | --- |
| `--mode=<id>` | Rebuild only this mode |
| `--since=<timestamp>` | Only process pages changed since then |
| `--force` | Recompute even rows that look correct |
| `--dry-run` | Report what would change, write nothing |
| `--batch-size=<n>` | Rows per batch (default 200) |

### `CategoryMultisortNeue:checkSortKeys`

Cross-checks the table against what each collation computes, and reports four kinds
of problem: **missing** rows, **mismatched** keys, **orphan** rows (no matching
`categorylinks` row) and **stale** rows (belonging to a mode that is no longer
configured). It also exercises `getFirstLetter()` for every configured mode.

| Option | Meaning |
| --- | --- |
| `--verbose` | Print sample differences |
| `--batch-size=<n>` | Rows per batch |

Exits non-zero when anything is wrong.

### `CategoryMultisortNeue:deleteSortKeys`

Deletes rows that are no longer wanted.

| Option | Meaning |
| --- | --- |
| `--dry-run` | Report only |
| `--orphans-only` | Only rows whose `categorylinks` row is gone |
| `--stale-modes-only` | Only rows for unconfigured modes |
| `--mode=<id>` | Restrict to one mode |
| `--limit=<n>` | Stop after n rows |

## Database schema

One table, declared once in [`sql/categorylinks_multisort.json`](sql/categorylinks_multisort.json)
(MediaWiki abstract schema) with per-DBMS SQL generated from it:

| Column | Type | Meaning |
| --- | --- | --- |
| `cms_from` | integer, unsigned | `categorylinks.cl_from` — the member page |
| `cms_target_id` | bigint, unsigned | `categorylinks.cl_target_id` — the category |
| `cms_mode` | string(32) | Mode id from `$wgCategoryMultisortNeueModes` |
| `cms_sortkey` | blob | Binary sort key, truncated to 230 bytes |

- Primary key: `(cms_from, cms_target_id, cms_mode)`
- Index `cms_target_mode_sortkey`: `(cms_target_id, cms_mode, cms_sortkey, cms_from)`
  — serves the category page query directly
- Index `cms_mode`: `(cms_mode)`

Rows mirror `categorylinks` rows one-to-many, so the table holds no page or
category identity of its own. No foreign keys, per MediaWiki convention. The schema
is created by `update.php`, not by the extension at runtime.

## How it works

```
categorylinks (core, read-only for this extension)      categorylinks_multisort (this extension)
  PRIMARY KEY (cl_from, cl_target_id)         ─────┬──►  PRIMARY KEY (cms_from, cms_target_id, cms_mode)
  cl_sortkey_prefix  ← human-readable key           │     cms_mode
  cl_sortkey         ← core key, left alone         │     cms_sortkey  ← this mode's key
  cl_collation_id                                   └───  INDEX cms_target_mode_sortkey
```

- The site default mode is served entirely by core: same query shape, same
  `cl_sortkey`, same `cl_sortkey_idx`, same paging parameters. That is why an
  unconfigured install is byte-for-byte unchanged.
- Every other mode reads `categorylinks_multisort`, ordered by `cms_sortkey` with
  `cl_from` as a deterministic tiebreaker.
- Sort keys are computed exactly as core's `maintenance/updateCollation.php` does:
  `substr( $collation->getSortKey( $title->getCategorySortkey( $prefix ) ), 0, 230 )`.
- Paging offsets carry **the binary key itself**, base64url-encoded, in
  `cmspagefrom` / `cmssubcatfrom` / `cmsfilefrom` (and the matching `…until`). Core's
  approach of putting the human-readable prefix in the URL cannot work here, because
  the same prefix maps to a different position under a different collation.
- `MultiSortCategoryViewer` extends core's `CategoryViewer`, so the three sections,
  the column lists, the language conversion, the link generation and the count
  messages are all core's own code. It overrides the member query, the grouping
  letters and the paging links — the parts that must know about collations.

Hooks used: `ArticleFromTitle`, `LoadExtensionSchemaUpdates`, `ParserFirstCallInit`,
`LinksUpdateComplete`, `ArticleDeleteComplete`, `GetPreferences`,
`SidebarBeforeOutput`, `BeforePageDisplay`.

## System messages

Every message is documented in [`i18n/qqq.json`](i18n/qqq.json). The ones a wiki is
likely to want to translate or override:

| Message | Purpose |
| --- | --- |
| `categorymultisortneue` | Heading of the portlet. **The key is the portlet name**, so it cannot be renamed. |
| `categorymultisortneue-mode-<id>` | Display name of a mode. Missing → the raw id is shown. |
| `categorymultisortneue-mode-<id>-desc` | Optional tooltip for a mode. |
| `categorymultisortneue-link-aria` | `aria-label` of a mode link; `$1` is the mode name. |
| `categorymultisortneue-pref` | Preference label. |
| `categorymultisortneue-pref-help` | Preference help text. |
| `categorymultisortneue-pref-default` | The "Wiki default" preference option. |
| `categorymultisortneue-unavailable-notice` | Notice when a requested mode could not be used. |
| `categorymultisortneue-magic-missing-mode` | Magic word error: no mode given. |
| `categorymultisortneue-magic-unknown-mode` | Magic word error: unknown mode. |
| `categorymultisortneue-magic-missing-arg` | Magic word error: missing argument. |
| `categorymultisortneue-magic-invalid-category` | Magic word error: not a category. |

To name a mode, either translate `categorymultisortneue-mode-<id>` on-wiki
(`MediaWiki:Categorymultisortneue-mode-pinyin`) or ship it in `i18n/`.

## Troubleshooting

**A mode does not show up in the portlet.**
Its collation failed validation. Check the `CategoryMultisortNeue` log channel — the
warning names the mode and the reason. The usual cause is an ICU locale group that
core cannot produce first letters for, such as `uca-zh` or
`uca-zh-Hant@collation=stroke`; use `uca-zh@collation=…` or `pinyin-zh` instead.

**Category pages are empty in a non-default mode.**
The extension table has not been populated for it. Run
`rebuildSortKeys`, then `checkSortKeys`.

**Category pages show an empty portlet, or none at all.**
The portlet needs at least two available modes. Check
`$wgCategoryMultisortNeueShowSelector` and that
`$wgCategoryMultisortNeueModes` really has two entries with usable collations.

**Readers on Minerva have no control.**
Expected: Minerva renders no sidebar portlets. Set
`$wgCategoryMultisortNeueInlineFallback = true`.

**The portlet heading shows the raw key.**
`categorymultisortneue` is not translated on your wiki and no fallback was found.
Add it as a system message.

**Order looks wrong after changing `$wgCategoryMultisortNeueModes`.**
Removing a mode leaves its rows behind; they are harmless but reported as *stale*.
Run `deleteSortKeys --stale-modes-only`.

**Paging repeats a page forever.**
Should not happen; if it does, the query is ignoring the extension's offsets. Check
that `$wgCategoryMultisortNeueModes` ids match `[A-Za-z0-9_-]{1,32}` and report it.

## Compatibility

Developed and verified against **MediaWiki 1.46.0** only. The extension states
`>= 1.46.0` in `extension.json` because
[`MultiSortCategoryViewer`](includes/MultiSortCategoryViewer.php) restates several
parts of core's `CategoryViewer` — the member query, the three section builders, the
paging links and the grouping letters. Those are deliberately marked `private` in
core and are not a stable interface, so a different MediaWiki release may need
adjustments even though nothing here patches core. Everything else (the table, the
hooks, the magic words, the portlet) uses public APIs.

MediaWiki itself must have the `intl` extension and ICU data that includes the
collations you configure.

## Development

The extension is a normal MediaWiki extension: one `extension.json`, PSR-4 classes
under `includes/`, an abstract schema under `sql/`, messages under `i18n/`,
ResourceLoader modules under `modules/`, and `Maintenance` subclasses under
`maintenance/`.

Useful commands while working on it:

```bash
# Create or update the table
php maintenance/run.php update --quick

# Recompute and verify the stored keys
php maintenance/run.php CategoryMultisortNeue:rebuildSortKeys
php maintenance/run.php CategoryMultisortNeue:checkSortKeys --verbose

# Syntax checks
for f in $(find . -name '*.php'); do php -l "$f"; done
node --check modules/ext.categoryMultisortNeue.js
```

Code style follows the MediaWiki conventions (tabs, PSR-4, `@license
GPL-2.0-or-later` headers). The extension intentionally contains no PHPUnit suite
because the table, the collations and the skin integration all need a real wiki; the
checks that were run against it, and the reasoning behind the less obvious design
decisions, are written up in [`docs/DESIGN.md`](docs/DESIGN.md).

## Licence

[GPL-2.0-or-later](COPYING).
