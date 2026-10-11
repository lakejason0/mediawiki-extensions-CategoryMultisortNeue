<?php
/**
 * Schema registration for CategoryMultisortNeue.
 *
 * The table itself is declared once, in sql/categorylinks_multisort.json (MediaWiki
 * abstract schema), and the per-DBMS SQL under sql/<dbms>/ is generated from it.
 * This handler only tells the updater which file to run.
 *
 * It is registered through the HookHandlers mechanism rather than as a plain
 * "Class::method" string on purpose.  ExtensionProcessor sends a hook whose value is
 * a bare class method to $wgHooks, but the extension-schema part of the updater
 * (includes/Installer/Task/WebUpgradeExtensionsProvider.php) builds its HookContainer
 * from the registry's attributes only, so a legacy-style handler would never run and
 * the table would never be created.
 *
 * @license GPL-2.0-or-later
 * @file
 */

namespace MediaWiki\Extension\CategoryMultisortNeue;

use MediaWiki\Installer\DatabaseUpdater;
use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

class SchemaHooks implements LoadExtensionSchemaUpdatesHook {

	/**
	 * @param DatabaseUpdater $updater
	 */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$dbType = $updater->getDB()->getType();
		$dir = dirname( __DIR__ ) . '/sql';
		$table = SortKeyStore::TABLE;

		// 1.1.0 added cms_variant and made it part of the primary key, which means the
		// old (cms_from, cms_target_id, cms_mode) primary key and the
		// cms_target_mode_sortkey index both have to be replaced. Rather than assemble
		// per-DBMS ALTERs for a four-column primary key, the table is dropped and
		// recreated: every row in it is a derived sort key that rebuildSortKeys can
		// regenerate, and that command has to be run after this upgrade anyway.
		$hasVariant = $updater->getDB()->fieldExists( $table, 'cms_variant', __METHOD__ );
		if ( $hasVariant ) {
			$updater->addExtensionTable( $table, "$dir/$dbType/tables-generated.sql" );
			return;
		}

		$isNewTable = !$updater->getDB()->tableExists( $table, __METHOD__ );
		if ( $isNewTable ) {
			// Fresh install: nothing to migrate.
			$updater->addExtensionTable( $table, "$dir/$dbType/tables-generated.sql" );
			return;
		}

		$updater->output(
			"\nCategoryMultisortNeue: upgrading categorylinks_multisort to per-variant sort " .
			"keys. The table is a derived cache and will be recreated empty; run " .
			"\"php maintenance/run.php CategoryMultisortNeue:rebuildSortKeys\" afterwards.\n"
		);
		$updater->dropExtensionTable( $table );
		$updater->addExtensionTable( $table, "$dir/$dbType/tables-generated.sql" );
	}
}
