<?php
/**
 * Category page offset encoding.
 *
 * @license GPL-2.0-or-later
 * @file
 */

namespace MediaWiki\Extension\CategoryMultisortNeue;

use Wikimedia\Rdbms\IReadableDatabase;

/**
 * Encodes a paging cursor so it can travel through a query parameter.
 *
 * Core avoids this problem by keeping the human readable cl_sortkey_prefix in the
 * URL and re-deriving the binary key from it.  That only works while the prefix maps
 * to a stable position, which it does not for a mode whose collation differs from the
 * site one: the same prefix would be looked up under the wrong collation.  Carrying
 * the key itself keeps the offset exact and makes a page of results reproducible.
 *
 * A cursor carries the page id as well as the sort key.  The member query orders by
 * (sort key, page id), so a sort key alone does not identify a row: several members can
 * share one key — variant twins do, once per-variant keys are built — and a cursor that
 * named only the key would serve the first of them again on the next page.
 *
 * Layout, base64url without padding: [4-byte big-endian key length][key][8-byte
 * big-endian page id].  The explicit length keeps the two parts unambiguous, which
 * matters because ICU sort keys are arbitrary bytes and may contain anything.
 */
class OffsetCodec {

	private const LENGTH_BYTES = 4;
	private const PAGE_ID_BYTES = 8;

	/**
	 * Marks a cursor that continues a page ordered ascending (walking forwards).
	 */
	public const DIR_FORWARD = 'f';

	/**
	 * Marks a cursor that continues a page ordered descending (walking backwards).
	 */
	public const DIR_BACKWARD = 'b';

	/** Comparison operators a cursor can carry, each encoded as one byte. */
	private const OPERATOR_CODES = [ '>' => 'g', '>=' => 'G', '<' => 'l', '<=' => 'L' ];

	/**
	 * @param string $sortKey Binary sort key
	 * @param int $pageId page_id of the row the cursor points at, used to break ties
	 *   between rows sharing a sort key, which variant twins do
	 * @param string $direction Which way the rows should come back, one of the DIR_*
	 *   constants — the query's ORDER BY, and nothing else
	 * @param string $keyOperator Comparison against the sort key, one of `>`, `>=`, `<`,
	 *   `<=`. Carried explicitly because it is not derivable from the direction: a page
	 *   can be ordered descending while still wanting the rows *after* its boundary row,
	 *   which is exactly what a "previous page" link needs when the walk runs backwards.
	 * @param string|null $idOperator Tie-break comparison, or null to follow $keyOperator
	 * @return string URL safe token
	 */
	public static function encode(
		string $sortKey,
		int $pageId,
		string $direction,
		string $keyOperator,
		?string $idOperator = null
	): string {
		if ( $sortKey === '' ) {
			return '';
		}

		$idOperator ??= $keyOperator;
		if ( !isset( self::OPERATOR_CODES[$keyOperator] )
			|| !isset( self::OPERATOR_CODES[$idOperator] )
		) {
			throw new \InvalidArgumentException( "Unsupported cursor operator: $keyOperator / $idOperator" );
		}

		// Fixed width prefix, so decoding never has to guess where a field ends:
		// [direction][key operator][id operator][key length 4][key][page id 8].
		$binary = $direction
			. self::OPERATOR_CODES[$keyOperator]
			. self::OPERATOR_CODES[$idOperator]
			. pack( 'N', strlen( $sortKey ) )
			. $sortKey
			. pack( 'J', $pageId );

		return rtrim( strtr( base64_encode( $binary ), '+/', '-_' ), '=' );
	}

	/**
	 * @param string $token
	 * @return array{sortkey:string,pageId:int,direction:string,keyOperator:string,idOperator:string}|null
	 *   The cursor, or null when malformed
	 */
	public static function decode( string $token ): ?array {
		if ( $token === '' ) {
			return null;
		}
		if ( !preg_match( '/^[A-Za-z0-9_-]+$/', $token ) ) {
			return null;
		}

		$padded = strtr( $token, '-_', '+/' );
		$remainder = strlen( $padded ) % 4;
		if ( $remainder === 1 ) {
			// Impossible length for base64; reject rather than guess.
			return null;
		}
		if ( $remainder > 0 ) {
			$padded .= str_repeat( '=', 4 - $remainder );
		}

		$binary = base64_decode( $padded, true );
		if ( $binary === false ) {
			return null;
		}

		$prefixLength = 3 + self::LENGTH_BYTES;
		if ( strlen( $binary ) < $prefixLength + self::PAGE_ID_BYTES ) {
			return null;
		}

		$direction = $binary[0];
		if ( $direction !== self::DIR_FORWARD && $direction !== self::DIR_BACKWARD ) {
			return null;
		}
		$keyOperator = array_search( $binary[1], self::OPERATOR_CODES, true );
		$idOperator = array_search( $binary[2], self::OPERATOR_CODES, true );
		if ( $keyOperator === false || $idOperator === false ) {
			return null;
		}

		/** @var array{1:int} $header */
		$header = unpack( 'N', substr( $binary, 3, self::LENGTH_BYTES ) );
		$keyLength = $header[1];
		if ( $keyLength === 0 || strlen( $binary ) !== $prefixLength + $keyLength + self::PAGE_ID_BYTES ) {
			return null;
		}

		$sortKey = substr( $binary, $prefixLength, $keyLength );
		/** @var array{1:int} $id */
		$id = unpack( 'J', substr( $binary, $prefixLength + $keyLength ) );

		return [
			'sortkey' => $sortKey,
			'pageId' => $id[1],
			'direction' => $direction,
			'keyOperator' => $keyOperator,
			'idOperator' => $idOperator,
		];
	}

	/**
	 * The condition that selects the rows a cursor points at.
	 *
	 * Both operators come straight from the cursor, so this does not have to reason about
	 * walks or directions at all — which is the point: the pair (key operator, id
	 * operator) is exactly what a page needs to ask for, and anything cleverer than that
	 * is how the previous revision kept losing or repeating the boundary member.
	 *
	 * The id comparison always breaks ties between rows sharing a sort key, never
	 * selects on its own: it is only consulted where the keys are equal.
	 *
	 * Assembled as quoted SQL rather than through `expr()`, because the key side is an
	 * arbitrary expression (the variant-fallback COALESCE) and `expr()` only accepts a
	 * plain field name there — it rejects anything else as a possible injection. The
	 * values are quoted with the database's own escaper, and the operators come from a
	 * fixed set, so nothing here is interpolated unchecked.
	 *
	 * @param IReadableDatabase $db
	 * @param array{sortkey:string,pageId:int,direction:string,keyOperator:string,idOperator:string} $cursor
	 * @param string $keyExpression SQL expression yielding the effective sort key. Must
	 *   be the same expression the query orders by, otherwise the filter and the
	 *   ordering disagree about where a boundary row is and paging skips or repeats.
	 *   Defaults to the bare column for callers that have no fallback to apply.
	 * @param string $idColumn Column holding the page id, which breaks ties between
	 *   rows sharing a key. Qualified by the caller, because a query that joins the
	 *   extension table twice has more than one `cms_from` in scope.
	 */
	public static function cursorCondition(
		IReadableDatabase $db,
		array $cursor,
		string $keyExpression = 'cms_sortkey',
		string $idColumn = 'cms_from'
	): string {
		$keyOperator = self::assertOperator( $cursor['keyOperator'] );
		$idOperator = self::assertOperator( $cursor['idOperator'] );
		$quotedKey = $db->addQuotes( $cursor['sortkey'] );
		$pageId = (int)$cursor['pageId'];

		$pastKey = "$keyExpression $keyOperator $quotedKey";
		$atKeyPastId = "( $keyExpression = $quotedKey AND $idColumn $idOperator $pageId )";

		return "( $pastKey OR $atKeyPastId )";
	}

	/**
	 * @param string $operator
	 */
	private static function assertOperator( string $operator ): string {
		if ( !isset( self::OPERATOR_CODES[$operator] ) ) {
			throw new \InvalidArgumentException( "Unsupported cursor operator: $operator" );
		}
		return $operator;
	}
}
