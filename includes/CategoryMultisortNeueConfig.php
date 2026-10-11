<?php
/**
 * Configuration reader for CategoryMultisortNeue.
 *
 * @license GPL-2.0-or-later
 * @file
 */

namespace MediaWiki\Extension\CategoryMultisortNeue;

use MediaWiki\Config\Config;
use MediaWiki\MainConfigNames;

/**
 * Wraps the four CategoryMultisortNeue* configuration variables and does the
 * syntactic validation that has to happen before anything else trusts a mode id:
 * ids must be safe as a URL parameter, as a message key suffix and as a database
 * value, and the collation name must not be empty unless it means "site default".
 */
class CategoryMultisortNeueConfig {

	/** Longest mode id we accept. Must fit categorylinks_multisort.cms_mode. */
	public const MAX_MODE_ID_LENGTH = 32;

	/** Mode ids are used in URLs, message keys and SQL values. */
	private const MODE_ID_PATTERN = '/^[A-Za-z0-9_-]{1,32}$/';

	public function __construct(
		private readonly Config $config
	) {
	}

	/**
	 * The map exactly as configured, in configuration order.
	 *
	 * @return array<string,string> mode id => collation name ('' means site default)
	 */
	public function getRawModes(): array {
		$modes = $this->config->get( 'CategoryMultisortNeueModes' );
		if ( !is_array( $modes ) ) {
			return [];
		}

		$result = [];
		foreach ( $modes as $id => $collation ) {
			$id = (string)$id;
			if ( self::isValidModeId( $id ) ) {
				$result[$id] = is_string( $collation ) ? trim( $collation ) : '';
			}
		}
		return $result;
	}

	/**
	 * Mode ids that were configured but thrown away, with the reason.
	 *
	 * @return array<string,string> raw id => reason
	 */
	public function getInvalidModes(): array {
		$modes = $this->config->get( 'CategoryMultisortNeueModes' );
		if ( !is_array( $modes ) ) {
			return [ '__config__' => 'CategoryMultisortNeueModes is not an array' ];
		}

		$invalid = [];
		foreach ( $modes as $id => $collation ) {
			$id = (string)$id;
			if ( !self::isValidModeId( $id ) ) {
				$invalid[$id] = 'mode id must match ' . self::MODE_ID_PATTERN;
			} elseif ( !is_string( $collation ) ) {
				$invalid[$id] = 'collation name must be a string';
			}
		}
		return $invalid;
	}

	public static function isValidModeId( string $id ): bool {
		return (bool)preg_match( self::MODE_ID_PATTERN, $id );
	}

	/**
	 * The configured default mode id, or null when it is missing or unusable.
	 */
	public function getConfiguredDefaultModeId(): ?string {
		$id = $this->config->get( 'CategoryMultisortNeueDefaultMode' );
		if ( !is_string( $id ) ) {
			return null;
		}
		return $id;
	}

	public function showSelector(): bool {
		return (bool)$this->config->get( 'CategoryMultisortNeueShowSelector' );
	}

	public function writeOnEdit(): bool {
		return (bool)$this->config->get( 'CategoryMultisortNeueWriteOnEdit' );
	}

	/**
	 * Whether to also emit the control in the page body.
	 *
	 * Off by default: the sidebar portlet is the skinned control and duplicating it
	 * in the content would be noise.  It exists for skins that never render sidebar
	 * portlets at all, Minerva above all.
	 */
	public function inlineFallback(): bool {
		return (bool)$this->config->get( 'CategoryMultisortNeueInlineFallback' );
	}

	public function offerPreference(): bool {
		return (bool)$this->config->get( 'CategoryMultisortNeuePreference' );
	}

	/**
	 * Whether sort keys are stored per language variant, so that a reader's chosen
	 * variant produces a different category order.
	 */
	public function perVariantSortKeys(): bool {
		return (bool)$this->config->get( 'CategoryMultisortNeuePerVariantSortKeys' );
	}

	/**
	 * Variants to build sort keys for, or [] for all of the language's variants.
	 *
	 * @return string[]
	 */
	public function getVariants(): array {
		$value = $this->config->get( 'CategoryMultisortNeueVariants' );
		if ( !is_array( $value ) ) {
			return [];
		}

		$variants = [];
		foreach ( $value as $variant ) {
			if ( is_string( $variant ) && trim( $variant ) !== '' ) {
				$variants[] = trim( $variant );
			}
		}
		return $variants;
	}

	/**
	 * The collation the wiki uses when a mode leaves the collation name empty.
	 */
	public function getSiteCollationName(): string {
		return (string)$this->config->get( MainConfigNames::CategoryCollation );
	}

	/** Message key holding the human readable name of a mode. */
	public static function modeMessageKey( string $id ): string {
		return 'categorymultisortneue-mode-' . strtolower( $id );
	}

	/** Message key holding the optional long description of a mode. */
	public static function modeDescriptionKey( string $id ): string {
		return 'categorymultisortneue-mode-' . strtolower( $id ) . '-desc';
	}
}
