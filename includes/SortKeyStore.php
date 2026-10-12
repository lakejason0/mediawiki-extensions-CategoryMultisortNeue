<?php
/**
 * Storage for the per-collation sort keys.
 *
 * @license GPL-2.0-or-later
 * @file
 */

namespace MediaWiki\Extension\CategoryMultisortNeue;

use MediaWiki\Title\Title;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IReadableDatabase;

/**
 * Reads and writes categorylinks_multisort.
 *
 * The table mirrors categorylinks row for row, keyed on the same
 * (page id, linktarget id) pair, and adds one binary sort key per configured sort
 * mode.  Nothing here ever writes to categorylinks: the wiki's own collation and
 * sort keys stay exactly as core maintains them.
 */
class SortKeyStore {

	/** Table name, also referenced by SchemaHooks and the viewer. */
	public const TABLE = 'categorylinks_multisort';

	public const FIELD_PAGE = 'cms_from';
	public const FIELD_TARGET = 'cms_target_id';
	public const FIELD_MODE = 'cms_mode';
	public const FIELD_VARIANT = 'cms_variant';
	public const FIELD_SORTKEY = 'cms_sortkey';

	public function __construct(
		private readonly SortModeRegistry $registry,
		private readonly IConnectionProvider $connectionProvider,
		private readonly VariantRegistry $variants
	) {
	}

	private function getPrimary(): IDatabase {
		return $this->connectionProvider->getPrimaryDatabase();
	}

	private function getReplica(): IReadableDatabase {
		return $this->connectionProvider->getReplicaDatabase();
	}

	/**
	 * Compute and store the sort keys of every category link of one page.
	 *
	 * Shaped for two callers: the maintenance rebuild (a batch of pages) and
	 * LinksUpdateComplete (one page, immediately after categorylinks was rewritten).
	 *
	 * A key is stored for every (mode, variant) combination the wiki configures, so a
	 * reader who switches writing system is served a genuinely different order.
	 *
	 * @param int $pageId
	 * @param array<int,array{targetId:int,prefix:string,title:Title}> $rows One entry
	 *   per current categorylinks row of the page.
	 * @param IDatabase $dbw
	 * @param string[]|null $modes Mode ids to compute, null for every available mode
	 * @param string[]|null $variants Variants to compute, null for every configured one
	 * @return int Number of rows written
	 */
	public function storeForPage(
		int $pageId,
		array $rows,
		IDatabase $dbw,
		?array $modes = null,
		?array $variants = null
	): int {
		if ( $rows === [] ) {
			return 0;
		}

		$modes ??= array_keys( $this->registry->getAvailableModes() );
		$sortModes = [];
		foreach ( $modes as $modeId ) {
			$mode = $this->registry->getMode( (string)$modeId );
			if ( $mode !== null && $mode->isAvailable() ) {
				$sortModes[] = $mode;
			}
		}
		if ( $sortModes === [] ) {
			return 0;
		}

		$variants ??= $this->variants->getVariants();
		if ( $variants === [] ) {
			$variants = [ $this->variants->getMixedVariant() ];
		}

		$targets = [];
		foreach ( $rows as $row ) {
			$targets[(int)$row['targetId']] = $row;
		}

		// One read to find what actually changed; a rebuild over an up-to-date table
		// then writes nothing at all.
		$existing = $this->fetchExisting( $dbw, $pageId, array_keys( $targets ) );

		$inserts = [];
		$written = 0;

		foreach ( $targets as $targetId => $row ) {
			$title = $row['title'];
			$prefix = (string)$row['prefix'];
			if ( !$title instanceof Title ) {
				continue;
			}

			foreach ( $sortModes as $mode ) {
				foreach ( $variants as $variant ) {
					try {
						$sortKey = $mode->getSortKey( $title, $prefix, $variant );
					} catch ( \Throwable $e ) {
						$this->registry->getLogger()->warning(
							'CategoryMultisortNeue: cannot compute sort key for {title} ' .
							'in mode {mode} variant {variant}: {error}',
							[
								'title' => $title->getPrefixedDBkey(),
								'mode' => $mode->id,
								'variant' => $variant,
								'error' => $e->getMessage(),
							]
						);
						continue;
					}

					$key = "$pageId|$targetId|{$mode->id}|$variant";
					if ( array_key_exists( $key, $existing ) ) {
						if ( $existing[$key] === $sortKey ) {
							// Already correct. Skipped for the write, but the row is
							// there, which is what the reader query needs.
							continue;
						}

						// The row exists but moved; replace it rather than leave the old
						// key behind for the category page to trip over.
						$dbw->newDeleteQueryBuilder()
							->deleteFrom( self::TABLE )
							->where( [
								self::FIELD_PAGE => $pageId,
								self::FIELD_TARGET => $targetId,
								self::FIELD_MODE => $mode->id,
								self::FIELD_VARIANT => $variant,
							] )
							->caller( __METHOD__ )
							->execute();
					}

					// Every (mode, variant) is written, including when the key equals
					// another variant's. Keeping only one row per distinct key would be
					// tempting, but a member must have a row for every variant it can be
					// read in: a title that sorts by a leading Latin part converts to the
					// same key under every variant, and a member with no row for the
					// reader's variant would otherwise vanish from the category. The
					// query falls back to the mixed variant as well, so this is about
					// keeping the data complete rather than about correctness alone.
					$inserts[] = [
						self::FIELD_PAGE => $pageId,
						self::FIELD_TARGET => $targetId,
						self::FIELD_MODE => $mode->id,
						self::FIELD_VARIANT => $variant,
						self::FIELD_SORTKEY => $sortKey,
					];
				}
			}
		}

		if ( $inserts === [] ) {
			return 0;
		}

		foreach ( array_chunk( $inserts, 100 ) as $chunk ) {
			$dbw->newInsertQueryBuilder()
				->insertInto( self::TABLE )
				->rows( $chunk )
				->caller( __METHOD__ )
				->execute();
			$written += $dbw->affectedRows();
		}

		return $written;
	}

	/**
	 * @param IReadableDatabase $db
	 * @param int $pageId
	 * @param int[] $targetIds
	 * @return array<string,string> "page|target|mode|variant" => sort key
	 */
	private function fetchExisting( IReadableDatabase $db, int $pageId, array $targetIds ): array {
		if ( $targetIds === [] ) {
			return [];
		}

		$existing = [];
		foreach ( array_chunk( $targetIds, 500 ) as $chunk ) {
			$res = $db->newSelectQueryBuilder()
				->select( [
					self::FIELD_TARGET,
					self::FIELD_MODE,
					self::FIELD_VARIANT,
					self::FIELD_SORTKEY,
				] )
				->from( self::TABLE )
				->where( [ self::FIELD_PAGE => $pageId, self::FIELD_TARGET => $chunk ] )
				->caller( __METHOD__ )
				->fetchResultSet();
			foreach ( $res as $row ) {
				$existing[
					$pageId . '|' . $row->{self::FIELD_TARGET} . '|' . $row->{self::FIELD_MODE}
					. '|' . $row->{self::FIELD_VARIANT}
				] = $row->{self::FIELD_SORTKEY};
			}
		}
		return $existing;
	}

	/**
	 * Drop every extension row belonging to a page.
	 */
	public function deleteForPage( int $pageId, IDatabase $dbw ): void {
		$dbw->newDeleteQueryBuilder()
			->deleteFrom( self::TABLE )
			->where( [ self::FIELD_PAGE => $pageId ] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * Drop the extension rows of one page for one category.
	 */
	public function deleteForPageTarget( int $pageId, int $targetId, IDatabase $dbw ): void {
		$dbw->newDeleteQueryBuilder()
			->deleteFrom( self::TABLE )
			->where( [
				self::FIELD_PAGE => $pageId,
				self::FIELD_TARGET => $targetId,
			] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * The variant a reader with no stored keys is served, so a category is never empty
	 * just because the wiki configured only some variants.
	 *
	 * This is the wiki's own language code: the mixed, un-converted ordering.
	 */
	public function getFallbackVariant(): string {
		return $this->variants->getMixedVariant();
	}

	/**
	 * How many members of one category have a sort key in one mode.
	 *
	 * Counts every variant, since the caller is checking coverage of the mode rather
	 * than of one reader's view.
	 */
	public function countForCategory( int $targetId, string $mode, IReadableDatabase $dbr ): int {
		return (int)$dbr->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( self::TABLE )
			->where( [
				self::FIELD_TARGET => $targetId,
				self::FIELD_MODE => $mode,
			] )
			->caller( __METHOD__ )
			->fetchField();
	}

	/**
	 * Delete rows whose categorylinks row no longer exists.
	 *
	 * Uses a temporary table rather than a NOT IN (...) list, because the list of
	 * live rows can be far larger than any sensible query parameter budget.
	 *
	 * @param IDatabase $dbw
	 * @param int $batchSize
	 * @param bool $dryRun
	 * @return int Rows deleted (or that would be deleted)
	 */
	public function deleteOrphans( IDatabase $dbw, int $batchSize = 1000, bool $dryRun = false ): int {
		$temp = $dbw->tableName( 'categorylinks_multisort_live' );
		$dbw->query( "DROP TABLE IF EXISTS $temp", __METHOD__ );
		$dbw->query(
			"CREATE TEMPORARY TABLE $temp (" .
			'live_from INTEGER UNSIGNED NOT NULL, live_target BIGINT UNSIGNED NOT NULL, ' .
			'PRIMARY KEY (live_from, live_target))',
			__METHOD__
		);

		$lastFrom = 0;
		do {
			$rows = [];
			$res = $dbw->newSelectQueryBuilder()
				->select( [ 'cl_from', 'cl_target_id' ] )
				->from( 'categorylinks' )
				->where( $dbw->expr( 'cl_from', '>', $lastFrom ) )
				->orderBy( 'cl_from' )
				->limit( $batchSize )
				->caller( __METHOD__ )
				->fetchResultSet();
			foreach ( $res as $row ) {
				$rows[] = [
					'live_from' => (int)$row->cl_from,
					'live_target' => (int)$row->cl_target_id,
				];
				$lastFrom = (int)$row->cl_from;
			}
			if ( $rows !== [] ) {
				$dbw->newInsertQueryBuilder()
					->insertInto( 'categorylinks_multisort_live' )
					->ignore()
					->rows( $rows )
					->caller( __METHOD__ )
					->execute();
			}
		} while ( count( $rows ) === $batchSize );

		$count = (int)$dbw->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( self::TABLE )
			->leftJoin(
				'categorylinks_multisort_live',
				'live',
				[
					'live.live_from = ' . self::FIELD_PAGE,
					'live.live_target = ' . self::FIELD_TARGET,
				]
			)
			->where( [ 'live.live_from' => null ] )
			->caller( __METHOD__ )
			->fetchField();

		if ( !$dryRun && $count > 0 ) {
			$dbw->query(
				'DELETE FROM ' . $dbw->tableName( self::TABLE ) . ' WHERE NOT EXISTS (' .
				'SELECT 1 FROM ' . $temp . ' WHERE live_from = ' . self::FIELD_PAGE .
				' AND live_target = ' . self::FIELD_TARGET . ')',
				__METHOD__
			);
		}

		$dbw->query( "DROP TABLE IF EXISTS $temp", __METHOD__ );

		return $count;
	}
}
