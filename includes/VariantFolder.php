<?php
/**
 * Canonical variant folding for sort keys.
 *
 * @license GPL-2.0-or-later
 * @file
 */

namespace MediaWiki\Extension\CategoryMultisortNeue;

use MediaWiki\Language\ILanguageConverter;
use MediaWiki\Language\Language;
use MediaWiki\Language\LanguageConverter;
use MediaWiki\Language\LanguageConverterFactory;

/**
 * Folds text to one canonical writing-system variant before a sort key is built.
 *
 * Why this is needed
 * ------------------
 * On a wiki whose language has variants, a page title may be written in any of them:
 * 体 and 體 are the same word, and readers expect to find them together in a category.
 * Collations that rank by shape rather than by sound cannot do that on their own,
 * because the variants are different characters with different properties:
 *
 *   stroke order, raw     : 大 小 体 国 國 體      <- 體 has 23 strokes, 体 has 7
 *   stroke order, folded  : 大 小 体 体 国 国
 *
 * The same applies to radical (Unihan) order, and it is what makes those modes
 * unusable as an index on a mixed-variant wiki. Collations that rank by sound
 * (pinyin, zhuyin) already keep most variant pairs together, but folding is harmless
 * there and keeps every mode consistent.
 *
 * How the canonical variant is chosen
 * -----------------------------------
 * MediaWiki can convert towards an explicit variant, and the tables are not
 * symmetric. Measured over 15 common variant pairs, folding to `zh-hans` collapses
 * all 15, while folding to `zh-hant` collapses only 13: `里/裡` and `台/臺` do not
 * merge in the traditional direction. The simplified direction is therefore the
 * better canonical form, and it is also the direction the conversion tables are most
 * complete in.
 *
 * Note that `convertCategoryKey()` is *not* usable here, despite its name. For Chinese
 * it is `autoConvert( $key, 'zh' )`, and `zh` is a base language code rather than a
 * conversion target, so it returns its input unchanged. An explicit variant is
 * required.
 *
 * What is folded
 * --------------
 * The page-title part of the key and the sort key prefix, both through the same
 * function, so a default sort key written in one variant and a title written in the
 * other still land in the same place. Folding is idempotent, and text without any
 * variant mapping (Latin, Cyrillic, digits, Hangul, …) is returned unchanged.
 */
class VariantFolder {

	/** Canonical variant used when the configured one cannot be resolved. */
	public const FALLBACK_VARIANT = 'zh-hans';

	private ?ILanguageConverter $converter = null;

	private ?string $canonicalVariant = null;

	/**
	 * @param LanguageConverterFactory $converterFactory
	 * @param CategoryMultisortNeueConfig $config
	 * @param Language $contentLanguage
	 */
	public function __construct(
		private readonly LanguageConverterFactory $converterFactory,
		private readonly CategoryMultisortNeueConfig $config,
		private readonly Language $contentLanguage
	) {
	}

	/**
	 * The variant every sort key is folded to, or null when this wiki needs no folding.
	 */
	public function getCanonicalVariant(): ?string {
		if ( !$this->config->foldVariants() ) {
			return null;
		}

		if ( $this->canonicalVariant === null ) {
			$this->canonicalVariant = $this->resolveCanonicalVariant();
		}

		return $this->canonicalVariant;
	}

	private function resolveCanonicalVariant(): ?string {
		$converter = $this->getConverter();
		if ( !$converter->hasVariants() ) {
			// No variants on this wiki (an ordinary language, or a converter that does
			// not implement them). Nothing to fold, and no cost either.
			return null;
		}

		// Only variants that something can be converted *to* are usable as a canonical
		// form.  For Chinese, `zh` is in getVariants() but is the "no conversion"
		// marker: validateVariant() accepts it happily and autoConvert() then returns
		// its input unchanged, so folding towards it silently does nothing.
		$targets = $this->getConversionTargets();

		$configured = $this->config->getCanonicalVariant();
		if ( $configured !== '' ) {
			// validateVariant is lenient: it lower-cases and maps deprecated codes.
			$validated = $converter->validateVariant( $configured );
			if ( $validated !== null && in_array( $validated, $targets, true ) ) {
				return $validated;
			}
			wfLogWarning(
				'CategoryMultisortNeue: $wgCategoryMultisortNeueCanonicalVariant ' .
				"\"$configured\" is not a usable conversion target for " .
				"{$this->contentLanguage->getCode()}; using " . self::FALLBACK_VARIANT . ' instead.'
			);
		}

		// Prefer the site's own variant when it is a conversion target, so a wiki whose
		// content is predominantly traditional folds towards traditional.
		$siteVariant = $converter->validateVariant( $this->contentLanguage->getCode() );
		if ( $siteVariant !== null && in_array( $siteVariant, $targets, true ) ) {
			return $siteVariant;
		}

		foreach ( [ self::FALLBACK_VARIANT, $converter->getDefaultVariant() ] as $candidate ) {
			$validated = $converter->validateVariant( $candidate );
			if ( $validated !== null && in_array( $validated, $targets, true ) ) {
				return $validated;
			}
		}

		// Last resort: any target at all, in the converter's own order.
		return $targets[0] ?? null;
	}

	/**
	 * Variants that text can be converted to.
	 *
	 * getVariantsFallbacks() maps every variant to the variants it falls back to, so
	 * anything appearing as a value is a conversion target.  Variants that appear only
	 * as keys cannot be converted to.
	 *
	 * @return string[]
	 */
	private function getConversionTargets(): array {
		$converter = $this->getConverter();
		if ( !$converter instanceof LanguageConverter ) {
			// A converter that does not implement the fallback table (the trivial one
			// does not) has no usable targets; hasVariants() would already have been
			// false for it, so this is only a safety net.
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

	private function getConverter(): ILanguageConverter {
		if ( $this->converter === null ) {
			$this->converter = $this->converterFactory->getLanguageConverter( $this->contentLanguage );
		}
		return $this->converter;
	}

	/**
	 * Fold a string to the canonical variant.
	 *
	 * Returns the input unchanged when this wiki needs no folding, when the canonical
	 * variant cannot be resolved, or when the string has no variant mapping.
	 *
	 * @param string $text
	 * @return string
	 */
	public function fold( string $text ): string {
		if ( $text === '' ) {
			return $text;
		}

		$variant = $this->getCanonicalVariant();
		if ( $variant === null ) {
			return $text;
		}

		$folded = $this->extractPlainText( $this->getConverter()->autoConvert( $text, $variant ) );

		return $folded === '' ? $text : $folded;
	}

	/**
	 * Extract the plain text of possibly markup-wrapped output.
	 *
	 * The converter flags text it could not decide about with markup such as
	 * `-{H|zh-hans:体;zh-hant:體}-` or `-{R|…}-`. A sort key must be plain text, so
	 * everything that looks like conversion markup is removed rather than sorted on.
	 */
	private function extractPlainText( string $text ): string {
		if ( !str_contains( $text, '-{' ) ) {
			return $text;
		}

		// -{R|text}-  and  -{text}-  both mean "this is the text"; keep the inner part.
		$text = preg_replace( '/-\{[A-Za-z-]*\|/', '', $text ) ?? $text;
		return str_replace( [ '-{', '}-' ], '', $text );
	}

	/**
	 * Fold a sort key prefix, for the callers that also need the folded prefix.
	 *
	 * @param string $prefix
	 * @return string
	 */
	public function foldSortKeyPrefix( string $prefix ): string {
		return $this->fold( $prefix );
	}
}
