<?php
/**
 * Category page that supports several sort modes.
 *
 * @license GPL-2.0-or-later
 * @file
 */

namespace MediaWiki\Extension\CategoryMultisortNeue;

use MediaWiki\Category\CategoryViewer;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\CategoryPage;
use MediaWiki\Title\Title;

/**
 * CategoryPage whose viewer understands $wgCategoryMultisortNeueModes.
 *
 * The viewer class is replaced with MultiSortCategoryViewer, which is the only way
 * to change the query the category members come from: CategoryViewer consults no
 * hook between constructing the query and running it, so an extension cannot
 * influence the ORDER BY or the LIMIT from outside.
 *
 * Paging offsets are left to the viewer, which reads both the core parameter names
 * (pagefrom/pageuntil, for the site default order) and the extension's own ones
 * (cmspagefrom/cmspageuntil, for every other mode).
 */
class MultiSortCategoryPage extends CategoryPage {

	/** @var class-string */
	protected $mCategoryViewerClass = MultiSortCategoryViewer::class;

	public function closeShowCategory() {
		$context = $this->getContext();
		$request = $context->getRequest();
		$services = MediaWikiServices::getInstance();

		/** @var SortModeSelector $selector */
		$selector = $services->get( 'CategoryMultisortNeue.SortModeSelector' );
		$modeId = $selector->resolveModeId( $request, $context->getUser() );

		// A per-user preference is not part of the URL, so the response is not
		// shareable.  Choices that arrive as ?cmsmode= are cacheable, because the
		// parameter is part of the request key.  Anonymous readers have no
		// preference, so passing the user keeps their responses shareable.
		if ( $selector->isUserSpecific( $request, $context->getUser() ) ) {
			$context->getOutput()->disableClientCache();
		}

		$oldFrom = $request->getVal( 'from' );
		$oldUntil = $request->getVal( 'until' );

		$reqArray = $request->getQueryValues();

		$from = $until = [];
		foreach ( [ 'page', 'subcat', 'file' ] as $type ) {
			$from[$type] = $request->getVal( "{$type}from", $oldFrom );
			$until[$type] = $request->getVal( "{$type}until", $oldUntil );

			// Do not let the old-style from/to parameters propagate into nav links.
			if ( !isset( $reqArray["{$type}from"] ) && isset( $reqArray["from"] ) ) {
				$reqArray["{$type}from"] = $reqArray["from"];
			}
			if ( !isset( $reqArray["{$type}to"] ) && isset( $reqArray["to"] ) ) {
				$reqArray["{$type}to"] = $reqArray["to"];
			}
		}

		unset( $reqArray["from"] );
		unset( $reqArray["to"] );

		// Since MediaWiki 1.47 a category can ask to be listed by when its members were
		// added, rather than by any collation. That is a different column, index and
		// grouping, and it is core's own code path; this extension restates the query, so
		// its copy would otherwise silently ignore the request. Render those categories
		// with core's viewer instead of trying to reproduce the ordering here.
		if ( CoreCategoryOrder::isTimestampOrdering( $this->getPage(), $request ) ) {
			$viewer = new CategoryViewer(
				$this->getPage(),
				$context,
				$from,
				$until,
				$reqArray
			);
			$out = $context->getOutput();
			$out->addHTML( $viewer->getHTML() );
			$this->addHelpLink( 'Help:Categories' );
			return;
		}

		$viewer = new $this->mCategoryViewerClass(
			$this->getPage(),
			$context,
			$from,
			$until,
			$reqArray,
			$modeId
		);

		$out = $context->getOutput();
		$out->addHTML( $viewer->getHTML() );
		$this->addHelpLink( 'Help:Categories' );
	}

	/**
	 * @inheritDoc
	 */
	protected function newPage( Title $title ) {
		return parent::newPage( $title );
	}
}
