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
	 * @param VariantFolder|null $variantFolder Folds writing-system variants, or null to
	 *   sort on the text exactly as written.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $collationName,
		public readonly bool $isDefault,
		?Collation $collation,
		string $error = '',
		private readonly ?VariantFolder $variantFolder = null
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
	 * @param VariantFolder|null $variantFolder
	 */
	public static function create(
		string $id,
		string $collationName,
		bool $isDefault,
		callable $factory,
		?VariantFolder $variantFolder = null
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

		return new self( $id, $collationName, $isDefault, $collation, '', $variantFolder );
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
	 * Binary sort key for one category link, using this mode's collation.
	 *
	 * Deliberately mirrors maintenance/updateCollation.php: the input is the page's
	 * category sort key (Title::getCategorySortkey() with the human readable
	 * cl_sortkey_prefix), and the result is truncated to 230 bytes.
	 *
	 * Both halves of that input are folded to the canonical writing-system variant
	 * first, so that 体 and 體 end up adjacent rather than however the collation
	 * happens to rank their shapes. See VariantFolder.
	 *
	 * @param string $sortKeyPrefix cl_sortkey_prefix equivalent, i.e. what the user
	 *   wrote in {{DEFAULTSORT:}} or after the pipe in [[Category:Foo|...]].
	 */
	public function getSortKey( Title $title, string $sortKeyPrefix ): string {
		if ( $this->collation === null ) {
			throw new \LogicException( "Sort mode '{$this->id}' is not available" );
		}

		// Title::getCategorySortkey() combines the prefix, a separator and the title
		// text, and reads its own Title.  Both halves have to be in the canonical
		// variant, and folding the combined string handles the prefix and the title
		// in one pass without building a synthetic Title (which would also re-run
		// title normalisation on the folded text).
		$sortKeyInput = $title->getCategorySortkey( $sortKeyPrefix );
		if ( $this->variantFolder !== null ) {
			$sortKeyInput = $this->variantFolder->fold( $sortKeyInput );
		}

		return substr(
			$this->collation->getSortKey( $sortKeyInput ),
			0,
			self::MAX_SORTKEY_BYTES
		);
	}

	/**
	 * Grouping letter for the category page headings.
	 *
	 * Folded like the sort key, so that a page cannot be grouped under a heading that
	 * does not match the order it was sorted into.
	 */
	public function getFirstLetter( string $humanSortKey ): string {
		if ( $this->collation === null ) {
			return '';
		}
		if ( $this->variantFolder !== null ) {
			$humanSortKey = $this->variantFolder->fold( $humanSortKey );
		}
		return $this->collation->getFirstLetter( $humanSortKey );
	}

	/**
	 * The canonical variant sort keys are folded to, or null when this wiki has none.
	 */
	public function getCanonicalVariant(): ?string {
		return $this->variantFolder?->getCanonicalVariant();
	}
}
