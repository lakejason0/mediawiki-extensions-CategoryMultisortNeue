<?php
/**
 * Compatibility with core's own category orderings.
 *
 * @license GPL-2.0-or-later
 * @file
 */

namespace MediaWiki\Extension\CategoryMultisortNeue;

use MediaWiki\Category\CategoryViewer;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Request\WebRequest;

/**
 * Detects when core, rather than this extension, owns the ordering of a category.
 *
 * MediaWiki 1.47 added a second axis to category listings: `{{CATEGORYSORT:TIMESTAMP}}`
 * and `{{CATEGORYSORT:RTIMESTAMP}}` on a category page store the `categorysort` page
 * property, and `CategoryViewer` then lists members by `cl_timestamp` instead of
 * `cl_sortkey`, grouping them by the day they were added. The request parameters
 * `cldsort` and `cldorder` override what the page says.
 *
 * That ordering is not a collation: it is a different column, a different index and a
 * different notion of a group heading. It therefore cannot be combined with a sort mode
 * — "sorted by when it was added" and "sorted by pinyin" are answers to the same
 * question — and the extension has to step aside rather than merely agree to disagree.
 *
 * How stepping aside works
 * ------------------------
 * `CategoryViewer::doCategoryQuery()` is private and runs the query with no hook in
 * between, which is why this extension restates it at all. A restatement is a copy, so
 * it cannot inherit a later addition: 1.46's copy knows nothing about `cl_timestamp`.
 * Rather than restating the timestamp logic too and having to track it forever, the
 * extension renders such categories with core's own viewer class, so the new ordering is
 * core's code doing exactly what core does.
 *
 * The mode control is hidden there for the same reason: there is nothing to switch.
 * A reader who still wants a collation ordering can pass `cldsort` with any other value,
 * which is core's own override and turns the timestamp ordering off.
 *
 * Detection is a copy of core's own resolution, kept in one place so it can be compared
 * against the core code when that changes. The page property is read here rather than in
 * the viewer constructor because the choice of viewer class has to be made before the
 * viewer exists.
 */
class CoreCategoryOrder {

	/** Page property core's {{CATEGORYSORT:}} sets. */
	public const PAGE_PROPERTY = 'categorysort';

	/** Request parameter overriding which column is used. */
	public const SORT_PARAM = 'cldsort';

	/**
	 * Whether this MediaWiki has core's timestamp ordering at all.
	 *
	 * 1.46 has neither the page property nor the viewer properties, so on 1.46 every
	 * answer below is "not ours" and the extension behaves exactly as before.
	 */
	public static function supportsTimestampOrdering(): bool {
		return property_exists( CategoryViewer::class, 'sortByTimestamp' );
	}

	/**
	 * Whether core's timestamp ordering is in effect for this request.
	 *
	 * Mirrors CategoryViewer::__construct(): the page property picks the ordering and the
	 * `cldsort` parameter overrides it, with `cldorder` choosing which end comes first.
	 * Only `timestamp` is special — any other value from the request, including one the
	 * wiki does not recognise, means "use the collation", which is core's behaviour.
	 */
	public static function isTimestampOrdering( PageIdentity $page, WebRequest $request ): bool {
		if ( !self::supportsTimestampOrdering() ) {
			return false;
		}

		$pageId = $page->getId();
		if ( $pageId <= 0 ) {
			return false;
		}

		$properties = MediaWikiServices::getInstance()
			->getPageProps()
			->getProperties( $page, self::PAGE_PROPERTY );
		$stored = $properties[$pageId] ?? null;

		$sort = match ( $stored ) {
			'timestamp', 'rtimestamp' => 'timestamp',
			default => null,
		};

		return $request->getVal( self::SORT_PARAM, $sort ) === 'timestamp';
	}
}
