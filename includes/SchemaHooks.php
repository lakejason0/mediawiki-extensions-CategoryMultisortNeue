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

		$updater->addExtensionTable(
			SortKeyStore::TABLE,
			"$dir/$dbType/tables-generated.sql"
		);
	}
}
