<?php
/**
 * Sort mode control in the page subtitle.
 *
 * @license GPL-2.0-or-later
 * @file
 */

namespace MediaWiki\Extension\CategoryMultisortNeue;

use MediaWiki\Context\IContextSource;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\Output\OutputPage;
use MediaWiki\Skin\Skin;
use MediaWiki\Title\Title;

/**
 * Offers the sort modes as one line of links under the page title.
 *
 * Why the subtitle and not the sidebar
 * ------------------------------------
 * The sidebar portlet (SortModePortlet) is the skin-native way to offer this, but it is
 * not always *seen*: on Vector 2022 — the default skin on most wikis — SidebarBeforeOutput
 * lands in the page-tools dropdown, one click away; on Minerva it is not rendered at all;
 * on third-party skins, which are the ones most likely to fold the sidebar away, there is
 * no general guarantee. The subtitle is the one region every skin renders under the page
 * title, on every viewport, without JavaScript, and it is where "which view of this page am
 * I looking at" information already lives (redirect notices, cached-copy notices).
 *
 * The portlet is kept as the skin-native secondary: where a sidebar is visible it is the
 * more natural home, and it costs nothing to keep both. This module therefore adds a
 * second, always-rendered channel rather than replacing the first.
 *
 * Plain anchors, no JavaScript: the current mode is marked with aria-current server-side,
 * so the control works for a reader with JavaScript disabled, which is also the reader who
 * would otherwise see the in-body fallback duplicated.
 */
class SortModeSubtitle {

	/** Wrapper span, so a skin or gadget can restyle the line. */
	public const CONTAINER_CLASS = 'cms-subtitle';

	/** Class on each mode link. */
	public const LINK_CLASS = 'cms-subtitle-link';

	/** Class on the link for the mode in effect. */
	public const CURRENT_CLASS = 'cms-subtitle-link-current';

	/**
	 * @param OutputPage $out
	 * @param Skin $skin
	 * @return void This hook must not abort.
	 */
	public static function onBeforePageDisplay( OutputPage $out, Skin $skin ): void {
		$services = MediaWikiServices::getInstance();

		/** @var CategoryMultisortNeueConfig $config */
		$config = $services->get( 'CategoryMultisortNeue.Config' );
		if ( !$config->showSubtitle() || !$config->showSelector() ) {
			return;
		}

		$title = $out->getTitle();
		if ( !$title instanceof Title || !$title->inNamespace( NS_CATEGORY ) ) {
			return;
		}

		// Only on the reading view: a sort-order control on a history, diff or edit page
		// would offer to reorder something that is not being listed.
		if ( !$out->isArticle() ) {
			return;
		}

		$request = $out->getRequest();

		// A category ordered by when its members were added has no collation to switch.
		if ( CoreCategoryOrder::isTimestampOrdering( $title, $request ) ) {
			return;
		}

		$modes = SortModePortlet::getUsableModes();
		if ( $modes === null ) {
			return;
		}

		/** @var SortModeSelector $selector */
		$selector = $services->get( 'CategoryMultisortNeue.SortModeSelector' );
		$currentId = $selector->resolveModeId( $request, $out->getUser() );

		$items = SortModePortlet::buildItems( $title, $modes, $currentId, $out->getContext() );
		if ( count( $items ) < 2 ) {
			return;
		}

		$out->addSubtitle( self::render( $items, $out->getContext() ) );
	}

	/**
	 * Render the sidebar-shaped items as one compact line.
	 *
	 * Takes the items rather than building its own links, so the subtitle, the portlet and
	 * the in-body list cannot drift apart.
	 *
	 * @param array[] $items As built by SortModePortlet::buildItems()
	 * @param IContextSource $context
	 */
	private static function render( array $items, IContextSource $context ): string {
		$links = [];
		foreach ( $items as $item ) {
			$attribs = [
				'class' => self::LINK_CLASS,
				'href' => $item['href'],
			];
			if ( !empty( $item['title'] ) ) {
				$attribs['title'] = $item['title'];
			}
			if ( !empty( $item['aria-label'] ) ) {
				$attribs['aria-label'] = $item['aria-label'];
			}
			if ( !empty( $item['active'] ) ) {
				$attribs['class'] .= ' ' . self::CURRENT_CLASS;
				$attribs['aria-current'] = 'true';
			}
			$links[] = Html::element( 'a', $attribs, $item['text'] );
		}

		// Punctuation is not written in code. The colon after the label comes from core's
		// colon-separator: the colon and the space around it differ by language (French wants
		// a narrow no-break space, Chinese adds a zero-width space after its fullwidth form).
		// The separator *between* modes is this extension's own message rather than core's
		// comma-separator, so a wiki can change this control's separator without changing
		// every list on the wiki. The default is a middle dot, which reads naturally in most
		// languages; the message carries the spacing, so a language can drop it or change it.
		$separator = Html::element(
			'span',
			[ 'class' => 'cms-subtitle-sep' ],
			$context->msg( 'categorymultisortneue-subtitle-separator' )->text()
		);
		$label = Html::element(
			'span',
			[ 'class' => 'cms-subtitle-label' ],
			$context->msg( 'categorymultisortneue-subtitle-label' )->text()
		) . $context->msg( 'colon-separator' )->escaped();

		// The separator itself carries the spacing, so only insert it between links: the
		// first mode follows the label's colon directly.
		return Html::rawElement(
			'span',
			[ 'class' => self::CONTAINER_CLASS ],
			$label . implode( $separator, $links )
		);
	}
}
