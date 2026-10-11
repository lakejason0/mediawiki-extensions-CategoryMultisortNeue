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
	 * @param string $sortKey Binary sort key
	 * @param int $pageId page_id of the row the cursor points at, the query's tiebreaker
	 * @return string URL safe token
	 */
	public static function encode( string $sortKey, int $pageId ): string {
		if ( $sortKey === '' ) {
			return '';
		}

		$binary = pack( 'N', strlen( $sortKey ) ) . $sortKey . pack( 'J', $pageId );

		return rtrim( strtr( base64_encode( $binary ), '+/', '-_' ), '=' );
	}

	/**
	 * @param string $token
	 * @return array{sortkey:string,pageId:int}|null The cursor, or null when malformed
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

		$minimum = self::LENGTH_BYTES + self::PAGE_ID_BYTES;
		if ( strlen( $binary ) < $minimum ) {
			return null;
		}

		/** @var array{1:int} $header */
		$header = unpack( 'N', substr( $binary, 0, self::LENGTH_BYTES ) );
		$keyLength = $header[1];
		if ( $keyLength === 0 || strlen( $binary ) !== $minimum + $keyLength ) {
			return null;
		}

		$sortKey = substr( $binary, self::LENGTH_BYTES, $keyLength );
		/** @var array{1:int} $id */
		$id = unpack( 'J', substr( $binary, self::LENGTH_BYTES + $keyLength ) );

		return [ 'sortkey' => $sortKey, 'pageId' => $id[1] ];
	}

	/**
	 * The condition that selects the rows after a cursor, in the query's own order.
	 *
	 * Rows sort by (sort key, page id), so "after the cursor" is either a greater sort
	 * key, or an equal sort key with a greater page id. Spelled out rather than compared
	 * as a row value, because the two columns have different types.
	 *
	 * @param IReadableDatabase $db
	 * @param array{sortkey:string,pageId:int} $cursor
	 * @param bool $forward True for the next page, false for the previous one
	 */
	public static function cursorCondition(
		IReadableDatabase $db,
		array $cursor,
		bool $forward
	): string {
		$operator = $forward ? '>' : '<';

		return $db->makeList( [
			$db->expr( 'cms_sortkey', $operator, $cursor['sortkey'] ),
			$db->makeList( [
				$db->expr( 'cms_sortkey', '=', $cursor['sortkey'] ),
				$db->expr( 'cms_from', $operator, $cursor['pageId'] ),
			], IReadableDatabase::LIST_AND ),
		], IReadableDatabase::LIST_OR );
	}
}
