/**
 * CategoryMultisortNeue — client-side enhancement for the sort mode portlet.
 *
 * The portlet itself is rendered by the skin, from the data the extension hands it
 * through SidebarBeforeOutput.  There is nothing to build here: without this module
 * the links already work, because they are ordinary anchors with ordinary hrefs.
 *
 * What is left for the client is the part a server round trip cannot express:
 *
 *   1. aria-current on the active link.  The server flags the current item with the
 *      `active` class, which is what skins style, but screen readers look for
 *      aria-current, and the sidebar item shape has no field that reaches the anchor.
 *   2. A guard against duplicate portlet markup.  The portlet is produced when the
 *      sidebar is built, so if a skin renders the same portlet more than once (for
 *      example in a sticky header and in the sidebar) only the first copy may claim
 *      to be the current one.
 *
 * No dependencies beyond the native DOM, so it stays cheap on large category pages.
 */
function initCategoryMultisortNeue( document ) {
	'use strict';

	/** Must match SortModePortlet::PORTLET_NAME and ::ITEM_CLASS. */
	var PORTLET_ID = 'p-categorymultisortneue';
	var FALLBACK_ID = 'cms-inline-fallback';
	var LINK_CLASS = 'cms-sortmode-link';
	var ACTIVE_CLASS = 'active';

	/**
	 * @param {Element} link
	 * @param {boolean} isCurrent
	 */
	function markCurrent( link, isCurrent ) {
		if ( isCurrent ) {
			link.setAttribute( 'aria-current', 'true' );
		} else {
			link.removeAttribute( 'aria-current' );
		}
		var item = link.closest( 'li' );
		if ( item ) {
			item.classList.toggle( ACTIVE_CLASS, isCurrent );
		}
	}

	function enhance() {
		var portlet = document.getElementById( PORTLET_ID );
		var fallback = document.getElementById( FALLBACK_ID );

		// The optional in-body fallback exists for skins that render no portlet.  If
		// the skin did render one, the fallback is redundant and is removed, so no
		// reader is offered the same choice twice.
		if ( portlet && fallback ) {
			fallback.remove();
			fallback = null;
		}

		var container = portlet || fallback;
		if ( !container ) {
			return;
		}

		var links = container.querySelectorAll( 'a.' + LINK_CLASS );
		if ( !links.length ) {
			return;
		}

		// `active` on the list item is the only signal the server can carry into the
		// item markup, so derive "current" from it and mirror it to aria-current.
		var seenCurrent = false;
		Array.prototype.forEach.call( links, function ( link ) {
			var item = link.closest( 'li' );
			var isCurrent = !!item && item.classList.contains( ACTIVE_CLASS ) && !seenCurrent;
			if ( isCurrent ) {
				seenCurrent = true;
			}
			markCurrent( link, isCurrent );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', enhance );
	} else {
		enhance();
	}
}

initCategoryMultisortNeue( document );
