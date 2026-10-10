<?php
/**
 * CategoryMultisortNeue — the two parser function magic words.
 *
 * Registers parser functions which let a page declare sort keys that apply to
 * one single sort mode only:
 *
 *   {{#multisortdefaultsort:<mode>|<key>}}
 *   {{#multisortsortkey:<mode>|Category:<name>|<key>}}
 *
 * Both store their result as page properties on the ParserOutput, which
 * LinksUpdateHooks reads back afterwards to maintain the
 * categorylinks_multisort table.  The property names are part of the frozen
 * interface contract (docs/BLUEPRINT.md §4.5):
 *
 *   cms-defaultsort-<mode>  raw sort key text; "" means "not set"
 *   cms-sortkey-<mode>      one "<category dbkey>\t<key>" line per category
 *
 * @file
 * @ingroup Extensions
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\CategoryMultisortNeue;

use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Parser\PPFrame;
use MediaWiki\Title\Title;

class Magics {

	/** Magic word id of {{#multisortdefaultsort:...}}. */
	public const FUNC_DEFAULTSORT = 'multisortdefaultsort';

	/** Magic word id of {{#multisortsortkey:...}}. */
	public const FUNC_SORTKEY = 'multisortsortkey';

	/** Page property name prefix for the per-mode page-wide default sort key. */
	public const PROP_DEFAULTSORT_PREFIX = 'cms-defaultsort-';

	/** Page property name prefix for the per-mode, per-category sort keys. */
	public const PROP_SORTKEY_PREFIX = 'cms-sortkey-';

	/** Field separator inside one cms-sortkey-<mode> line. */
	private const FIELD_SEPARATOR = "\t";

	/** Line separator inside a cms-sortkey-<mode> value. */
	private const LINE_SEPARATOR = "\n";

	/**
	 * ParserFirstCallInit handler; see extension.json.
	 *
	 * Note: Parser::setFunctionHook() requires the id to be a registered magic
	 * word, otherwise MagicWord::load() throws.  The ids below therefore have
	 * to exist in i18n/magicWords.php, which extension.json exposes through
	 * ExtensionMessagesFiles.
	 */
	public static function onParserFirstCallInit( Parser $parser ): void {
		$parser->setFunctionHook(
			self::FUNC_DEFAULTSORT,
			[ self::class, 'renderDefaultSort' ],
			Parser::SFH_OBJECT_ARGS
		);
		$parser->setFunctionHook(
			self::FUNC_SORTKEY,
			[ self::class, 'renderSortKey' ],
			Parser::SFH_OBJECT_ARGS
		);
	}

	/**
	 * {{#multisortdefaultsort:<mode>|<key>}}
	 *
	 * Sets the page-wide default sort key for one mode.  An empty <key> clears
	 * the property again, so a later call can undo an earlier one.
	 *
	 * @param Parser $parser
	 * @param PPFrame $frame
	 * @param array $args As delivered with Parser::SFH_OBJECT_ARGS:
	 *   $args[0] is the already expanded and trimmed text after the colon,
	 *   $args[1] and up are PPNode objects for the pipe-separated arguments.
	 *   (The PPFrame is the separate $frame parameter, not $args[0].)
	 * @return string Wikitext (always empty, or an inline error).
	 */
	public static function renderDefaultSort( Parser $parser, PPFrame $frame, array $args ): string {
		$mode = self::resolveMode( $parser, $frame, $args, self::FUNC_DEFAULTSORT );
		if ( is_string( $mode ) ) {
			return $mode;
		}

		$rawKey = self::expandArgument( $frame, $args, 1 );
		if ( $rawKey === null ) {
			return self::errorMessage( $parser, 'categorymultisortneue-magic-missing-arg', self::FUNC_DEFAULTSORT );
		}

		$key = self::cleanSortKey( $rawKey );
		$property = self::PROP_DEFAULTSORT_PREFIX . $mode->id;

		if ( $key === '' ) {
			// 空值 = 不设置
			$parser->getOutput()->unsetPageProperty( $property );
		} else {
			$parser->getOutput()->setPageProperty( $property, $key );
		}

		return '';
	}

	/**
	 * {{#multisortsortkey:<mode>|Category:<name>|<key>}}
	 *
	 * Sets a per-category sort key for one mode; equivalent to
	 * [[Category:<name>|<key>]] but only for that mode.  An empty <key> drops
	 * the entry for that category again.
	 *
	 * @param Parser $parser
	 * @param PPFrame $frame
	 * @param array $args See renderDefaultSort().
	 * @return string Wikitext (always empty, or an inline error).
	 */
	public static function renderSortKey( Parser $parser, PPFrame $frame, array $args ): string {
		$mode = self::resolveMode( $parser, $frame, $args, self::FUNC_SORTKEY );
		if ( is_string( $mode ) ) {
			return $mode;
		}

		$categoryText = self::expandArgument( $frame, $args, 1 );
		if ( $categoryText === null || $categoryText === '' ) {
			return self::errorMessage( $parser, 'categorymultisortneue-magic-missing-arg', self::FUNC_SORTKEY );
		}
		$category = self::parseCategoryTitle( $categoryText );
		if ( $category === null ) {
			return self::errorMessage( $parser, 'categorymultisortneue-magic-invalid-category', $categoryText );
		}

		$rawKey = self::expandArgument( $frame, $args, 2 );
		if ( $rawKey === null ) {
			return self::errorMessage( $parser, 'categorymultisortneue-magic-missing-arg', self::FUNC_SORTKEY );
		}

		$key = self::cleanSortKey( $rawKey );
		$property = self::PROP_SORTKEY_PREFIX . $mode->id;
		$output = $parser->getOutput();
		$current = $output->getPageProperty( $property );
		$map = self::parseSortKeyLines( is_string( $current ) ? $current : '' );

		$dbKey = $category->getDBkey();
		if ( $key === '' ) {
			unset( $map[$dbKey] );
		} else {
			// 同一分类后写覆盖先写
			$map[$dbKey] = $key;
		}

		if ( $map === [] ) {
			$output->unsetPageProperty( $property );
		} else {
			$output->setPageProperty( $property, self::serializeSortKeyLines( $map ) );
		}

		return '';
	}

	/**
	 * Resolve the <mode> argument of either magic word.
	 *
	 * @param Parser $parser
	 * @param PPFrame $frame
	 * @param array $args
	 * @param string $functionName Magic word name, used in error messages.
	 * @return SortMode|string A SortMode, or the wikitext of an error message.
	 */
	private static function resolveMode(
		Parser $parser,
		PPFrame $frame,
		array $args,
		string $functionName
	) {
		$modeId = self::expandArgument( $frame, $args, 0 );
		$registry = self::getRegistry();

		if ( $modeId === null || $modeId === '' ) {
			return self::errorMessage(
				$parser,
				'categorymultisortneue-magic-missing-mode',
				$registry->getAvailableModeIdList()
			);
		}

		$mode = $registry->getMode( $modeId );
		if ( $mode === null ) {
			return self::errorMessage(
				$parser,
				'categorymultisortneue-magic-unknown-mode',
				$modeId,
				$registry->getAvailableModeIdList()
			);
		}

		return $mode;
	}

	/**
	 * Read back every cms-sortkey-<mode> page property of a ParserOutput.
	 *
	 * @param ParserOutput $output
	 * @return array<string,array<string,string>> [ modeId => [ categoryDbKey => sortKey ] ]
	 */
	public static function readPerModeSortKeys( ParserOutput $output ): array {
		$result = [];
		foreach ( $output->getPageProperties() as $name => $value ) {
			$name = (string)$name;
			if ( !str_starts_with( $name, self::PROP_SORTKEY_PREFIX ) || !is_string( $value ) ) {
				continue;
			}
			$modeId = substr( $name, strlen( self::PROP_SORTKEY_PREFIX ) );
			if ( $modeId === '' ) {
				continue;
			}
			$result[$modeId] = self::parseSortKeyLines( $value );
		}
		return $result;
	}

	/**
	 * Read back every cms-defaultsort-<mode> page property of a ParserOutput.
	 *
	 * @param ParserOutput $output
	 * @return array<string,string> [ modeId => sortKey ]
	 */
	public static function readPerModeDefaultSort( ParserOutput $output ): array {
		$result = [];
		foreach ( $output->getPageProperties() as $name => $value ) {
			$name = (string)$name;
			if ( !str_starts_with( $name, self::PROP_DEFAULTSORT_PREFIX )
				|| !is_string( $value )
				|| $value === ''
			) {
				continue;
			}
			$modeId = substr( $name, strlen( self::PROP_DEFAULTSORT_PREFIX ) );
			if ( $modeId === '' ) {
				continue;
			}
			$result[$modeId] = $value;
		}
		return $result;
	}

	/**
	 * Expand one wikitext argument out of the array handed to a
	 * Parser::SFH_OBJECT_ARGS callback.
	 *
	 * $args[0] is technically already expanded (a plain string) while the rest
	 * are PPNode objects; expanding both through the frame keeps this working
	 * either way, as the documentation of Parser::setFunctionHook() asks.
	 *
	 * @param PPFrame $frame
	 * @param array $args
	 * @param int $index
	 * @return string|null Trimmed text, or null when the argument is absent.
	 */
	private static function expandArgument( PPFrame $frame, array $args, int $index ): ?string {
		if ( !array_key_exists( $index, $args ) ) {
			return null;
		}
		return trim( $frame->expand( $args[$index] ) );
	}

	/**
	 * Normalise a user supplied sort key.
	 *
	 * The values are stored one per line inside a single page property, so
	 * newlines, tabs and other control characters absolutely cannot survive.
	 * Apart from that this mirrors CoreParserFunctions::defaultsort(), which
	 * only trims.
	 *
	 * @param string $key
	 * @return string
	 */
	private static function cleanSortKey( string $key ): string {
		$key = preg_replace( '/[\x00-\x1f\x7f]+/', ' ', $key ) ?? '';
		return trim( $key );
	}

	/**
	 * Turn a user supplied category reference into a Title in NS_CATEGORY.
	 *
	 * "Category:Name" is the documented form; a bare "Name" is accepted too.
	 *
	 * @param string $text
	 * @return Title|null
	 */
	private static function parseCategoryTitle( string $text ): ?Title {
		$title = Title::newFromText( $text );
		if ( $title !== null && $title->getNamespace() === NS_CATEGORY ) {
			return $title;
		}
		$title = Title::newFromText( $text, NS_CATEGORY );
		if ( $title !== null && $title->getNamespace() === NS_CATEGORY ) {
			return $title;
		}
		return null;
	}

	/**
	 * @param string $value
	 * @return array<string,string> [ categoryDbKey => sortKey ]
	 */
	private static function parseSortKeyLines( string $value ): array {
		$map = [];
		if ( $value === '' ) {
			return $map;
		}
		foreach ( explode( self::LINE_SEPARATOR, $value ) as $line ) {
			if ( $line === '' ) {
				continue;
			}
			$position = strpos( $line, self::FIELD_SEPARATOR );
			if ( $position === false ) {
				continue;
			}
			$category = substr( $line, 0, $position );
			if ( $category === '' ) {
				continue;
			}
			$map[$category] = substr( $line, $position + 1 );
		}
		return $map;
	}

	/**
	 * @param array<string,string> $map [ categoryDbKey => sortKey ]
	 * @return string
	 */
	private static function serializeSortKeyLines( array $map ): string {
		$lines = [];
		foreach ( $map as $category => $key ) {
			$lines[] = $category . self::FIELD_SEPARATOR . $key;
		}
		return implode( self::LINE_SEPARATOR, $lines );
	}

	/**
	 * @return SortModeRegistry
	 */
	private static function getRegistry(): SortModeRegistry {
		return MediaWikiServices::getInstance()
			->getService( 'CategoryMultisortNeue.SortModeRegistry' );
	}

	/**
	 * @param Parser $parser
	 * @param string $messageKey
	 * @param mixed ...$params
	 * @return string
	 */
	private static function errorMessage( Parser $parser, string $messageKey, ...$params ): string {
		return '<span class="error">' . $parser->msg( $messageKey, ...$params )->escaped() . '</span>';
	}
}
