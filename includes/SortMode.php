<?php
/**
 * One configured sort mode.
 *
 * @license GPL-2.0-or-later
 * @file
 */

namespace MediaWiki\Extension\CategoryMultisortNeue;

use Collation;
use MediaWiki\Message\Message;
use MediaWiki\Title\Title;

/**
 * A single entry of $wgCategoryMultisortNeueModes, together with the Collation
 * object it resolves to.
 *
 * The collation is usability-checked at construction time, because core's
 * IcuCollation happily builds a collator for locales it cannot later produce a
 * first letter for.  REPORT.md documents the failure: getFirstLetter() throws
 * RuntimeException "MediaWiki does not support ICU locale \"zh\"" for uca-zh,
 * which would turn every category page into a 500.  Failing here instead means
 * the registry can simply hide or skip the mode.
 */
class SortMode {

	/** Core truncates sort keys to 230 bytes to stay inside the column. */
	public const MAX_SORTKEY_BYTES = 230;

	private readonly ?Collation $collation;

	private readonly string $error;

	/**
	 * @param string $id Mode id, already validated by CategoryMultisortNeueConfig.
	 * @param string $collationName Resolved collation name ('' already replaced by the site default).
	 * @param bool $isDefault True when this mode means "use $wgCategoryCollation".
	 * @param Collation|null $collation The constructed collation, or null when unusable.
	 * @param string $error Empty when usable, otherwise a short human readable reason.
	 * @param VariantRegistry|null $variants Provides per-variant title text, or null to
	 *   always sort on the title as stored.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $collationName,
		public readonly bool $isDefault,
		?Collation $collation,
		string $error = '',
		private readonly ?VariantRegistry $variants = null
	) {
		$this->collation = $collation;
		$this->error = $error;
	}

	/**
	 * Build a mode and verify the collation can actually be used for categories.
	 *
	 * @param string $id
	 * @param string $collationName
	 * @param bool $isDefault
	 * @param callable(string):Collation $factory
	 * @param VariantRegistry|null $variants
	 */
	public static function create(
		string $id,
		string $collationName,
		bool $isDefault,
		callable $factory,
		?VariantRegistry $variants = null
	): self {
		try {
			$collation = $factory( $collationName );
		} catch ( \Throwable $e ) {
			return new self( $id, $collationName, $isDefault, null,
				sprintf( 'cannot construct collation "%s": %s', $collationName, $e->getMessage() ) );
		}

		$error = self::checkUsable( $collation );
		if ( $error !== '' ) {
			return new self( $id, $collationName, $isDefault, null, $error );
		}

		return new self( $id, $collationName, $isDefault, $collation, '', $variants );
	}

	/**
	 * Exercise both methods the category viewer depends on.  getFirstLetter() is
	 * the one that throws for unregistered ICU locale groups.
	 */
	private static function checkUsable( Collation $collation ): string {
		try {
			$key = $collation->getSortKey( 'MediaWiki' );
		} catch ( \Throwable $e ) {
			return 'getSortKey() failed: ' . $e->getMessage();
		}
		if ( $key === '' ) {
			return 'getSortKey() returned an empty key';
		}

		try {
			$collation->getFirstLetter( 'MediaWiki' );
		} catch ( \Throwable $e ) {
			return 'getFirstLetter() failed: ' . $e->getMessage();
		}

		return '';
	}

	public function isAvailable(): bool {
		return $this->collation !== null;
	}

	public function getCollation(): ?Collation {
		return $this->collation;
	}

	/**
	 * Why this mode cannot be used.  Empty string when it can.
	 */
	public function getError(): string {
		return $this->error;
	}

	/**
	 * Localised display name.  Falls back to the raw mode id so that a wiki which
	 * has not translated a newly configured mode still gets a usable dropdown.
	 */
	public function getLabel( ?\MediaWiki\Context\IContextSource $context = null ): string {
		$key = CategoryMultisortNeueConfig::modeMessageKey( $this->id );
		$msg = $context ? $context->msg( $key ) : wfMessage( $key );
		if ( $msg->isDisabled() ) {
			return $this->id;
		}
		return $msg->text();
	}

	/**
	 * Optional longer description, or '' when none is translated.
	 */
	public function getDescription( ?\MediaWiki\Context\IContextSource $context = null ): string {
		$key = CategoryMultisortNeueConfig::modeDescriptionKey( $this->id );
		$msg = $context ? $context->msg( $key ) : wfMessage( $key );
		if ( $msg->isDisabled() ) {
			return '';
		}
		return $msg->text();
	}

	/**
	 * Binary sort key for one category link, using this mode's collation and one
	 * language variant.
	 *
	 * The variant is part of the input on purpose. A reader who switches writing system
	 * is meant to get a different order, and the variants really are in different
	 * places: 体 is 人部 7 画 and 體 is 骨部 23 画, and they differ even at primary
	 * collation strength. Core's convertCategoryKey() does the opposite — it makes keys
	 * variant-independent — which is right for a single site-wide collation and wrong
	 * here.
	 *
	 * @param string $variant Language variant to build the key for, or '' for the
	 *   title as stored.
	 * @param string $sortKeyPrefix cl_sortkey_prefix equivalent, i.e. what the user
	 *   wrote in {{DEFAULTSORT:}} or after the pipe in [[Category:Foo|...]].
	 */
	public function getSortKey( Title $title, string $sortKeyPrefix, string $variant = '' ): string {
		if ( $this->collation === null ) {
			throw new \LogicException( "Sort mode '{$this->id}' is not available" );
		}

		return substr(
			$this->collation->getSortKey( $this->buildSortKeyInput( $title, $sortKeyPrefix, $variant ) ),
			0,
			self::MAX_SORTKEY_BYTES
		);
	}

	/**
	 * The string a sort key is computed from: the variant title text plus the
	 * human-readable prefix, combined the way Title::getCategorySortkey() combines them.
	 *
	 * The prefix is not variant-converted. It is authored text — a {{DEFAULTSORT:}} or a
	 * pipe — and converting it would move a page the editor deliberately placed. Core
	 * converts it once at write time for the single site-wide collation; per variant
	 * there is no single correct conversion, so it is sorted as written.
	 */
	private function buildSortKeyInput( Title $title, string $sortKeyPrefix, string $variant ): string {
		$titleText = ( $variant === '' || $this->variants === null )
			? $title->getText()
			: $this->variants->getTitleTextForVariant( $title, $variant );

		if ( $sortKeyPrefix === '' ) {
			return $titleText;
		}

		// Same separator as Title::getCategorySortkey(): a line feed, so the prefix only
		// acts as a tiebreaker, and tab/newline stripped from the prefix because tab is
		// the one character that can sort above LF.
		$prefix = strtr( $sortKeyPrefix, "\n\t", '  ' );

		return "$prefix\n$titleText";
	}

	/**
	 * Grouping letter for the category page headings, for one variant.
	 *
	 * Derived from the same variant text as the sort key, so a page cannot be grouped
	 * under a heading that disagrees with the order it was sorted into.
	 */
	public function getFirstLetter( Title $title, string $sortKeyPrefix, string $variant = '' ): string {
		if ( $this->collation === null ) {
			return '';
		}
		return $this->collation->getFirstLetter(
			$this->buildSortKeyInput( $title, $sortKeyPrefix, $variant )
		);
	}

	/**
	 * Variants this mode computes keys for, or null when it always uses the stored title.
	 *
	 * @return string[]|null
	 */
	public function getVariants(): ?array {
		return $this->variants?->getVariants();
	}
}
