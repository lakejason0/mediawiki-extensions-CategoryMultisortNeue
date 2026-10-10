<?php
/**
 * CategoryMultisortNeue — incremental maintenance of categorylinks_multisort.
 *
 * On every LinksUpdateComplete (that is, after a page was saved or deleted and
 * its categorylinks rows were rewritten) this rebuilds the page's rows in the
 * extension table.  Only the extension table is touched; categorylinks itself
 * is never written to.
 *
 * @file
 * @ingroup Extensions
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\CategoryMultisortNeue;

use MediaWiki\Deferred\LinksUpdate\LinksUpdate;
use MediaWiki\MediaWikiServices;

class LinksUpdateHooks {

	/**
	 * LinksUpdateComplete handler.
	 *
	 * @param LinksUpdate $linksUpdate
	 * @param mixed $ticket Prior result of LBFactory::getEmptyTransactionTicket()
	 */
	public static function onLinksUpdateComplete( LinksUpdate $linksUpdate, $ticket = null ): void {
		$services = MediaWikiServices::getInstance();

		// 站点可以选择不在编辑时增量维护（例如只用 rebuildSortKeys.php 全量重建）
		$config = $services->getMainConfig();
		if ( $config->has( 'CategoryMultisortNeueWriteOnEdit' )
			&& !$config->get( 'CategoryMultisortNeueWriteOnEdit' )
		) {
			return;
		}

		$pageId = $linksUpdate->getPageId();
		if ( $pageId <= 0 ) {
			// 页面还没有落库，没有 cl_from 可以用
			return;
		}

		$title = $linksUpdate->getTitle();
		$output = $linksUpdate->getParserOutput();

		// 页面用 {{#multisortdefaultsort:...}} / {{#multisortsortkey:...}} 声明的
		// 每 mode 排序键（BLUEPRINT §4.5）
		$perModeSortKeys = Magics::readPerModeSortKeys( $output );
		$perModeDefaultSort = Magics::readPerModeDefaultSort( $output );

		$registry = $services->getService( 'CategoryMultisortNeue.SortModeRegistry' );
		$store = $services->getService( 'CategoryMultisortNeue.SortKeyStore' );

		$dbw = $services->getConnectionProvider()->getPrimaryDatabase();

		// 一次 SELECT 取回本页在 categorylinks 里的全部行（含分类名），避免 N+1
		$categoryRows = $dbw->newSelectQueryBuilder()
			->select( [ 'cl_target_id', 'cl_sortkey_prefix', 'lt_title' ] )
			->from( 'categorylinks' )
			->join( 'linktarget', null, 'cl_target_id = lt_id' )
			->where( [ 'cl_from' => $pageId ] )
			->caller( __METHOD__ )
			->fetchResultSet();

		$dbw->startAtomic( __METHOD__ );
		// 先清空这一页的扩展行：既保证幂等，也顺带删掉「页面被移出某分类」留下的孤儿行
		$store->deleteForPage( $pageId, $dbw );

		foreach ( $registry->getModes() as $mode ) {
			if ( !$mode->isAvailable() ) {
				// 预检失败的 mode 不能算键（REPORT.md §6.1），直接跳过
				continue;
			}

			$rows = [];
			foreach ( $categoryRows as $row ) {
				$categoryDbKey = (string)$row->lt_title;
				// 每 mode 的前缀优先级：单独 sortkey > 该 mode 的 DEFAULTSORT > 核心前缀
				$prefix = $perModeSortKeys[$mode->id][$categoryDbKey]
					?? $perModeDefaultSort[$mode->id]
					?? (string)$row->cl_sortkey_prefix;

				$rows[] = [
					'targetId' => (int)$row->cl_target_id,
					'prefix' => $prefix,
					'title' => $title,
				];
			}

			if ( $rows === [] ) {
				continue;
			}

			// prefix 是「每 mode」的，所以每个 mode 单独调用一次，并用第 4 个参数
			// 把本次调用限制在这一个 mode 上（一次多行 upsert，不是 N+1）
			$store->storeForPage( $pageId, $rows, $dbw, [ $mode->id ] );
		}

		$dbw->endAtomic( __METHOD__ );
	}
}
