# Contributing

Thanks for taking a look. This is a small extension, so the process is informal.

## Reporting a bug

Please include:

- the MediaWiki version and the collations you have configured
  (`$wgCategoryCollation` and `$wgCategoryMultisortNeueModes`);
- which sort mode was selected, and how (URL parameter, preference, or default);
- the skin in use, since the control is a skin portlet;
- the output of

  ```bash
  php maintenance/run.php CategoryMultisortNeue:checkSortKeys --verbose
  ```

- anything relevant from the `CategoryMultisortNeue` log channel.

## Making a change

1. Fork and branch from `main`.
2. Keep the existing style: MediaWiki conventions, tabs for indentation, PSR-4 under
   `includes/`, `@license GPL-2.0-or-later` headers, English identifiers with comments
   allowed in any language.
3. If you change the database schema, edit `sql/categorylinks_multisort.json` and
   commit the generated `sql/<dbms>/tables-generated.sql` alongside it. Do not edit
   the generated SQL by hand.
4. If you add a system message, add it to `i18n/en.json` **and** `i18n/qqq.json`. CI
   checks that every message key is documented.
5. Run, at minimum:

   ```bash
   find . -name '*.php' -exec php -l {} \;
   node --check modules/ext.categoryMultisortNeue.js
   php maintenance/run.php update --quick
   php maintenance/run.php CategoryMultisortNeue:rebuildSortKeys
   php maintenance/run.php CategoryMultisortNeue:checkSortKeys --verbose
   ```

   and load a category page in a browser, in more than one sort mode, in more than one
   skin if you touched the portlet.

## Things to be careful about

`docs/DESIGN.md` ends with a list of behaviours that are easy to get wrong — private
methods in core's `CategoryViewer`, the ResourceLoader base path, the sidebar
`tooltip` key, collations whose grouping letters are unavailable. Please read it
before changing the viewer or the portlet.

## Licence

By contributing you agree that your changes are licensed under
GPL-2.0-or-later, the licence of this repository.
