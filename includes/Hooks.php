<?php
/**
 * Core hook handlers for CategoryMultisortNeue.
 *
 * @license GPL-2.0-or-later
 * @file
 */

namespace MediaWiki\Extension\CategoryMultisortNeue;

use MediaWiki\Deferred\LinksUpdate\CategoryLinksTable;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\Article;
use MediaWiki\Page\WikiPage;
use MediaWiki\Title\Title;
use Wikimedia\Rdbms\IDatabase;

/**
 * Hooks that belong to the extension's own plumbing.
 *
 * The sort mode selector, the magic words and the incremental sort key maintenance
 * live in their own handler classes so that each concern stays readable; this class
 * only wires the category page subclass in and cleans up after page deletions.
 */
class Hooks {

	/**
	 * Give category pages the multi-sort viewer.
	 *
	 * CategoryPage picks its viewer class from a protected property, and
	 * CategoryViewer runs its query before consulting any hook, so replacing the page
	 * class is the only way in that does not patch core.  Note that
	 * CategoryViewer::doCategoryQuery() cannot be influenced from a hook at all: it
	 * runs the query and only then fires CategoryViewer::doCategoryQuery.
	 *
	 * Any handler that replaced the article with a different class has already won;
	 * this only upgrades the plain core category page.
	 *
	 * @param Title $title
	 * @param Article|null &$article
	 * @param \MediaWiki\Context\IContextSource $context
	 * @return bool|void
	 */
	public static function onArticleFromTitle( $title, &$article, $context ) {
		if ( !$title->inNamespace( NS_CATEGORY ) ) {
			return true;
		}

		if ( $article !== null ) {
			// Another handler already chose a class.  Take over only the plain core
			// category page, so that a wiki-specific subclass keeps working.
			if ( !is_a( $article, \MediaWiki\Page\CategoryPage::class )
				|| get_class( $article ) !== \MediaWiki\Page\CategoryPage::class
			) {
				return true;
			}
		}

		$page = new MultiSortCategoryPage( $title );
		$page->setContext( $context );
		$article = $page;
		return true;
	}

	/**
	 * Drop the extension's rows when a page is deleted.
	 *
	 * LinksUpdate does not run for deletions, so without this the table would keep
	 * rows pointing at a page id that no longer exists.  deleteSortKeys.php can also
	 * clean these up in bulk; this keeps the common case tidy immediately.
	 *
	 * @param WikiPage $wikiPage
	 * @param \MediaWiki\User\UserIdentity $user
	 * @param string $reason
	 * @param int $id
	 * @return bool|void
	 */
	public static function onArticleDeleteComplete( $wikiPage, $user, $reason, $id ) {
		/** @var CategoryMultisortNeueConfig $config */
		$config = MediaWikiServices::getInstance()->get( 'CategoryMultisortNeue.Config' );
		if ( !$config->writeOnEdit() ) {
			return true;
		}

		$pageId = (int)$id;
		if ( $pageId <= 0 ) {
			return true;
		}

		/** @var SortKeyStore $store */
		$store = MediaWikiServices::getInstance()->get( 'CategoryMultisortNeue.SortKeyStore' );
		$dbw = MediaWikiServices::getInstance()->getConnectionProvider()->getPrimaryDatabase();
		$store->deleteForPage( $pageId, $dbw );

		return true;
	}

	/**
	 * Helper for hook handlers that need the primary database in the right domain.
	 */
	public static function primaryDb(): IDatabase {
		return MediaWikiServices::getInstance()
			->getConnectionProvider()
			->getPrimaryDatabase( CategoryLinksTable::VIRTUAL_DOMAIN );
	}
}
