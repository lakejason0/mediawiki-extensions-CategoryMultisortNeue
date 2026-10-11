<?php
/**
 * Service wiring for CategoryMultisortNeue.
 *
 * @license GPL-2.0-or-later
 * @file
 */

use MediaWiki\Extension\CategoryMultisortNeue\CategoryMultisortNeueConfig;
use MediaWiki\Extension\CategoryMultisortNeue\SortKeyStore;
use MediaWiki\Extension\CategoryMultisortNeue\SortModeRegistry;
use MediaWiki\Extension\CategoryMultisortNeue\SortModeSelector;
use MediaWiki\Extension\CategoryMultisortNeue\VariantFolder;
use MediaWiki\MediaWikiServices;

return [
	'CategoryMultisortNeue.Config' => static function ( MediaWikiServices $services ) {
		return new CategoryMultisortNeueConfig( $services->getMainConfig() );
	},

	'CategoryMultisortNeue.VariantFolder' => static function ( MediaWikiServices $services ) {
		return new VariantFolder(
			$services->getLanguageConverterFactory(),
			$services->get( 'CategoryMultisortNeue.Config' ),
			$services->getContentLanguage()
		);
	},

	'CategoryMultisortNeue.SortModeRegistry' => static function ( MediaWikiServices $services ) {
		return new SortModeRegistry(
			$services->get( 'CategoryMultisortNeue.Config' ),
			$services->getCollationFactory(),
			$services->get( 'CategoryMultisortNeue.VariantFolder' )
		);
	},

	'CategoryMultisortNeue.SortKeyStore' => static function ( MediaWikiServices $services ) {
		return new SortKeyStore(
			$services->get( 'CategoryMultisortNeue.SortModeRegistry' ),
			$services->getConnectionProvider()
		);
	},

	'CategoryMultisortNeue.SortModeSelector' => static function ( MediaWikiServices $services ) {
		return new SortModeSelector(
			$services->get( 'CategoryMultisortNeue.SortModeRegistry' ),
			$services->get( 'CategoryMultisortNeue.Config' ),
			$services->getUserOptionsLookup()
		);
	},
];
