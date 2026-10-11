<?php
/**
 * Language variants that sort keys are built for.
 *
 * @license GPL-2.0-or-later
 * @file
 */

namespace MediaWiki\Extension\CategoryMultisortNeue;

use MediaWiki\Language\ILanguageConverter;
use MediaWiki\Language\Language;
use MediaWiki\Language\LanguageConverter;
use MediaWiki\Language\LanguageConverterFactory;
use MediaWiki\Title\Title;
use MediaWiki\WikiMap\WikiMap;

/**
 * Decides which language variants the wiki stores sort keys for, and which one a
 * reader is served.
 *
 * Why keys are per variant
 * ------------------------
 * A reader who switches writing system is supposed to see a different order, and the
 * variants genuinely occupy different positions: 体 is a 人部 character with 7 strokes
 * and 體 is a 骨部 character with 23, and they differ even at primary collation
 * strength. MediaWiki's own convertCategoryKey() goes the other way — it exists to
 * make keys *not* vary — so it is deliberately not used here; that is what core does
 * for the single site-wide collation, and it is the opposite of what this extension is
 * for.
 *
 * The variant text comes from the converter's own title API,
 * LanguageConverter::convertSplitTitle( $title, $variant ), which is variant-correct
 * for an explicit variant and therefore works in a maintenance rebuild as well as
 * during an edit, unlike ParserOutput::getTitleText() which only exists where a page
 * has been parsed.
 *
 * The mixed variant
 * -----------------
 * 'zh' is not "no variant": it is the wiki's own un-converted variant, the one a
 * reader gets when they have chosen not to convert. convertSplitTitle() returns every
 * title unchanged under it, so a 'zh' key is the order of the text exactly as written.
 * It is the default and always the fallback, because a wiki that mixes writing systems
 * needs one order that reflects what is actually there.
 */
class VariantRegistry {

	/**
	 * The mixed / un-converted state, used when no variant applies and as the fallback
	 * when a reader's variant has no stored keys.
	 */
	public const MIXED_VARIANT = 'zh';

	/** Longest variant code accepted. Must fit categorylinks_multisort.cms_variant. */
	public const MAX_VARIANT_LENGTH = 32;

	private ?ILanguageConverter $converter = null;

	/** @var string[]|null */
	private ?array $variants = null;

	private ?string $readerVariant = null;

	/**
	 * @param CategoryMultisortNeueConfig $config
	 * @param LanguageConverterFactory $converterFactory
	 * @param Language $contentLanguage
	 */
	public function __construct(
		private readonly CategoryMultisortNeueConfig $config,
		private readonly LanguageConverterFactory $converterFactory,
		private readonly Language $contentLanguage
	) {
	}

	private function getConverter(): ILanguageConverter {
		if ( $this->converter === null ) {
			$this->converter = $this->converterFactory->getLanguageConverter( $this->contentLanguage );
		}
		return $this->converter;
	}

	/**
	 * Whether this wiki's language has variants at all.
	 */
	public function hasVariants(): bool {
		return $this->getConverter()->hasVariants();
	}

	/**
	 * Variants configured for key generation, in a stable order, always including the
	 * mixed variant.
	 *
	 * @return string[]
	 */
	public function getVariants(): array {
		if ( $this->variants !== null ) {
			return $this->variants;
		}

		$converter = $this->getConverter();

		if ( !$converter->hasVariants() || !$this->config->perVariantSortKeys() ) {
			// A wiki without variants, or one that deliberately keeps a single order:
			// one set of keys, in the mixed state.
			return $this->variants = [ self::MIXED_VARIANT ];
		}

		$configured = $this->config->getVariants();
		$variants = [];
		if ( $configured === [] ) {
			// Every variant the wiki has, in the converter's own order.
			foreach ( $converter->getVariants() as $variant ) {
				$validated = $this->normalize( $variant );
				if ( $validated !== null ) {
					$variants[$validated] = true;
				}
			}
		} else {
			foreach ( $configured as $variant ) {
				$validated = $this->normalize( $variant );
				if ( $validated === null ) {
					wfLogWarning(
						'CategoryMultisortNeue: ignoring variant ' . var_export( $variant, true ) .
						": not a variant of {$this->contentLanguage->getCode()}"
					);
					continue;
				}
				$variants[$validated] = true;
			}
		}

		// The mixed variant must always be present: it is the fallback, and it is what
		// readers who have not chosen a variant are served.
		$variants[self::MIXED_VARIANT] = true;

		return $this->variants = array_keys( $variants );
	}

	private function normalize( string $variant ): ?string {
		$variant = trim( $variant );
		if ( $variant === '' || strlen( $variant ) > self::MAX_VARIANT_LENGTH ) {
			return null;
		}

		// validateVariant lower-cases and maps deprecated codes. Unlike the canonical
		// variant that an earlier revision needed, the mixed variant *is* accepted here:
		// this is about which orderings to store, not about conversion targets.
		$converter = $this->getConverter();
		if ( $variant === self::MIXED_VARIANT ) {
			return $variant;
		}

		return $converter->validateVariant( $variant );
	}

	/**
	 * The variant this request should be served, given what the reader has selected.
	 *
	 * Falls back to the mixed variant when the reader's variant is not one the wiki
	 * stores keys for, so a wiki that configures only some variants still works.
	 */
	public function getReaderVariant(): string {
		if ( $this->readerVariant !== null ) {
			return $this->readerVariant;
		}

		if ( !$this->hasVariants() || !$this->config->perVariantSortKeys() ) {
			return $this->readerVariant = self::MIXED_VARIANT;
		}

		$preferred = $this->getConverter()->getPreferredVariant();
		$available = $this->getVariants();
		if ( $preferred !== null && in_array( $preferred, $available, true ) ) {
			return $this->readerVariant = $preferred;
		}

		return $this->readerVariant = self::MIXED_VARIANT;
	}

	/**
	 * The variant title text for one page, as the reader of that variant would see it.
	 *
	 * Uses the converter's own API rather than re-deriving which characters to convert:
	 * convertSplitTitle() is the same function the parser uses to build the displayed
	 * title, so it already accounts for the converter's tables and any in-page
	 * conversion rules. Deliberately takes the namespace-separated main text only,
	 * because a sort key is over the page name.
	 */
	public function getTitleTextForVariant( Title $title, string $variant ): string {
		$converter = $this->getConverter();

		if ( $variant === self::MIXED_VARIANT ) {
			// The un-converted state: the title exactly as stored. Spelled out rather
			// than relying on convertSplitTitle(), so the mixed variant stays correct
			// even if a converter's identity conversion ever changes.
			return $title->getText();
		}

		if ( !$converter->hasVariants() ) {
			return $title->getText();
		}

		[ , , $mainText ] = $converter->convertSplitTitle( $title, $variant );

		return $mainText === '' ? $title->getText() : $mainText;
	}

	/**
	 * Variants that text can be converted to, for diagnostics.
	 *
	 * getVariantsFallbacks() maps every variant to the variants it falls back to, so
	 * anything appearing as a value is a conversion target; a variant appearing only as
	 * a key (such as 'zh') cannot be converted to but is still a usable ordering.
	 *
	 * @return string[]
	 */
	public function getConversionTargets(): array {
		$converter = $this->getConverter();
		if ( !$converter instanceof LanguageConverter ) {
			return [];
		}

		$targets = [];
		foreach ( $converter->getVariantsFallbacks() as $fallbacks ) {
			foreach ( $fallbacks as $fallback ) {
				$targets[$fallback] = true;
			}
		}
		return array_keys( $targets );
	}

	/**
	 * A short description of the configuration, for maintenance script output.
	 */
	public function describe(): string {
		$variants = $this->getVariants();
		if ( $variants === [ self::MIXED_VARIANT ] ) {
			return $this->hasVariants()
				? self::MIXED_VARIANT . ' (per-variant sort keys disabled)'
				: self::MIXED_VARIANT . " (this wiki's language has no variants)";
		}
		return implode( ', ', $variants );
	}

	/**
	 * Wiki id, for logging.
	 */
	public function getWikiId(): string {
		return WikiMap::getCurrentWikiId();
	}
}
