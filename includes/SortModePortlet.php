<?php
/**
 * Sort mode portlet for the skin.
 *
 * @license GPL-2.0-or-later
 * @file
 */

namespace MediaWiki\Extension\CategoryMultisortNeue;

use MediaWiki\MediaWikiServices;
use MediaWiki\Skin\Skin;
use MediaWiki\Title\Title;

/**
 * Offers the available sort modes to the skin as a sidebar portlet.
 *
 * This is deliberately not a widget rendered into the page body.  A control like
 * this belongs to the skin: only the skin knows whether it should be a sidebar box,
 * a collapsible menu, a dropdown in the page toolbar or a set of tabs, and only the
 * skin knows how to fold it away on narrow screens.  Handing it the data and letting
 * it render is also the only approach that keeps working when the skin changes.
 *
 * The channel is SidebarBeforeOutput, the documented way for an extension to add a
 * portlet.  Everything is expressed in the standard sidebar item shape, so the skin
 * needs to know nothing about this extension:
 *
 *   - the array key becomes the portlet name, and from it core derives the id
 *     (p-categorymultisortneue), the class (mw-portlet-categorymultisortneue) and
 *     the label, which is looked up as the message with that same key;
 *   - each mode is one ordinary sidebar link with an id, a class, a tooltip and an
 *     aria-label, and the mode in effect is flagged active plus aria-current, which
 *     is what skin styles and assistive technology look for.
 *
 * SidebarBeforeOutput runs on every page view, after the cached sidebar has been
 * built, which is required here because the selected mode is per request.  The
 * cheap namespace check comes first for that reason.
 *
 * Skin coverage, measured on this wiki with all bundled skins (see
 * docs/CategoryMultisortNeue.md §2.6):
 *
 *   vector-2022, vector, monobook, timeless   the portlet is rendered
 *   minerva                                   not rendered
 *
 * Minerva is the exception because it does not render sidebar portlets at all: its
 * main menu is assembled by Minerva's own MainMenuDirector from hard-coded builders
 * (includes/Menu/Main/DefaultMainMenuBuilder.php) and it only ever reads the
 * data-user-menu / data-actions / data-views / data-languages portlets.  There is no
 * skin-agnostic way to inject into that; a wiki whose readers use Minerva should set
 * $wgCategoryMultisortNeueInlineFallback = true to get a control in the page body.
 */
class SortModePortlet {

	/**
	 * Portlet name.  Doubles as the message key holding its label, the same
	 * convention core's own navigation / toolbox / lang portlets follow.
	 */
	public const PORTLET_NAME = 'categorymultisortneue';

	/** Item id prefix, so a skin or gadget can target one mode. */
	public const ITEM_ID_PREFIX = 'cms-mode-';

	/** Class on every mode link. */
	public const ITEM_CLASS = 'cms-sortmode-link';

	/**
	 * @param Skin $skin
	 * @param array &$sidebar
	 * @return void This hook must not abort.
	 */
	public static function onSidebarBeforeOutput( $skin, &$sidebar ): void {
		$title = $skin->getTitle();
		if ( !$title instanceof Title || !$title->inNamespace( NS_CATEGORY ) ) {
			return;
		}

		$services = MediaWikiServices::getInstance();

		/** @var CategoryMultisortNeueConfig $config */
		$config = $services->get( 'CategoryMultisortNeue.Config' );
		if ( !$config->showSelector() ) {
			return;
		}

		// A category can ask core to list it by when members were added (MediaWiki 1.47's
		// {{CATEGORYSORT:TIMESTAMP}} / {{CATEGORYSORT:RTIMESTAMP}}). That ordering is not a
		// collation, so there is no mode to offer: the two answers would contradict each
		// other. Checked after the cheap configuration test, because resolving it costs a
		// page-properties lookup. See CoreCategoryOrder.
		if ( CoreCategoryOrder::isTimestampOrdering( $title, $skin->getRequest() ) ) {
			return;
		}

		$modes = self::getUsableModes();
		if ( $modes === null ) {
			return;
		}

		/** @var SortModeSelector $selector */
		$selector = $services->get( 'CategoryMultisortNeue.SortModeSelector' );
		$currentId = $selector->resolveModeId( $skin->getRequest(), $skin->getUser() );

		$sidebar[self::PORTLET_NAME] = self::buildItems(
			$title,
			$modes,
			$currentId,
			$skin->getContext()
		);
	}

	/**
	 * The modes worth offering, or null when a control should not be shown at all.
	 *
	 * @return array<string,SortMode>|null
	 */
	private static function getUsableModes(): ?array {
		/** @var SortModeRegistry $registry */
		$registry = MediaWikiServices::getInstance()->get( 'CategoryMultisortNeue.SortModeRegistry' );
		$modes = $registry->getAvailableModes();
		return count( $modes ) < 2 ? null : $modes;
	}

	/**
	 * One sidebar-shaped item per mode.
	 *
	 * Shared with MultiSortCategoryViewer's optional in-body fallback, so the two
	 * renderings cannot drift apart: same hrefs, same ids, same classes, same
	 * current-mode flag -- only the surrounding markup differs.
	 *
	 * @param Title $title Category page being viewed
	 * @param array<string,SortMode> $modes
	 * @param string $currentId
	 * @param \MediaWiki\Context\IContextSource $context
	 * @return array[]
	 */
	public static function buildItems( Title $title, array $modes, string $currentId, $context ): array {
		$items = [];
		foreach ( $modes as $mode ) {
			$label = $mode->getLabel( $context );
			$description = $mode->getDescription( $context );

			$item = [
				// Sidebar link text is plain text here; SortMode already falls back to
				// the raw mode id when no message is translated.
				'text' => $label,
				'href' => $title->getLocalURL( [ SortModeSelector::URL_PARAM => $mode->id ] ),
				'id' => self::ITEM_ID_PREFIX . $mode->id,
				'class' => self::ITEM_CLASS,
				'aria-label' => $context->msg( 'categorymultisortneue-link-aria' )
					->params( $label )->text(),
			];

			if ( $description !== '' ) {
				// Note: core's sidebar 'tooltip' key is looked up as a system message
				// (Linker::titleAttrib), so it cannot carry free text.  A plain 'title'
				// attribute is passed through to the anchor verbatim, which is what is
				// wanted here because the description is already localised text.
				$item['title'] = $description;
			}

			if ( $mode->id === $currentId ) {
				// 'active' puts the skin's own current-item styling on the list item;
				// aria-current tells assistive technology the same thing.
				$item['active'] = true;
				$item['aria-current'] = 'true';
			}

			$items[] = $item;
		}
		return $items;
	}
}
