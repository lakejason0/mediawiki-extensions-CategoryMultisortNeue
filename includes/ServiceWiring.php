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
use MediaWiki\Extension\CategoryMultisortNeue\VariantRegistry;
use MediaWiki\MediaWikiServices;

return [
	'CategoryMultisortNeue.Config' => static function ( MediaWikiServices $services ) {
		return new CategoryMultisortNeueConfig( $services->getMainConfig() );
	},

	'CategoryMultisortNeue.VariantRegistry' => static function ( MediaWikiServices $services ) {
		return new VariantRegistry(
			$services->get( 'CategoryMultisortNeue.Config' ),
			$services->getLanguageConverterFactory(),
			$services->getContentLanguage()
		);
	},

	'CategoryMultisortNeue.SortModeRegistry' => static function ( MediaWikiServices $services ) {
		return new SortModeRegistry(
			$services->get( 'CategoryMultisortNeue.Config' ),
			$services->getCollationFactory(),
			$services->get( 'CategoryMultisortNeue.VariantRegistry' )
		);
	},

	'CategoryMultisortNeue.SortKeyStore' => static function ( MediaWikiServices $services ) {
		return new SortKeyStore(
			$services->get( 'CategoryMultisortNeue.SortModeRegistry' ),
			$services->getConnectionProvider(),
			$services->get( 'CategoryMultisortNeue.VariantRegistry' )
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
