<?php
/**
 * Category page offset encoding.
 *
 * @license GPL-2.0-or-later
 * @file
 */

namespace MediaWiki\Extension\CategoryMultisortNeue;

/**
 * Encodes a binary sort key so it can travel through a query parameter.
 *
 * Core avoids this problem by keeping the human readable cl_sortkey_prefix in the
 * URL and re-deriving the binary key from it.  That only works while the prefix maps
 * to a stable position, which it does not for a mode whose collation differs from the
 * site one: the same prefix would be looked up under the wrong collation.  Carrying
 * the key itself keeps the offset exact and makes a page of results reproducible.
 *
 * base64url without padding, because ICU sort keys are arbitrary bytes and may
 * contain characters that would otherwise need escaping (and, for stroke order,
 * do contain them).
 */
class OffsetCodec {

	/**
	 * @param string $sortKey Binary sort key
	 * @return string URL safe token
	 */
	public static function encode( string $sortKey ): string {
		if ( $sortKey === '' ) {
			return '';
		}
		return rtrim( strtr( base64_encode( $sortKey ), '+/', '-_' ), '=' );
	}

	/**
	 * @param string $token
	 * @return string|null The binary sort key, or null when the token is malformed
	 */
	public static function decode( string $token ): ?string {
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

		$decoded = base64_decode( $padded, true );
		return $decoded === false ? null : $decoded;
	}
}
