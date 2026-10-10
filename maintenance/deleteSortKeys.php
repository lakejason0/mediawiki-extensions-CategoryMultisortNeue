<?php
/**
 * Remove obsolete rows from categorylinks_multisort.
 *
 * Two kinds of rows can outlive their usefulness:
 *
 *   - orphan rows: the (page, category) pair is no longer in categorylinks,
 *     because the page was deleted, renamed or lost the category.
 *   - stale rows: the mode is no longer part of $wgCategoryMultisortNeueModes,
 *     so nothing will ever read or refresh that row again.
 *
 * Rows of a mode that is still configured but currently unusable (for example a
 * collation ICU cannot produce first letters for) are deliberately kept: fixing
 * the configuration should not require a full rebuild.
 *
 * Usage:
 *   php maintenance/run.php CategoryMultisortNeue:deleteSortKeys [options]
 *   php extensions/CategoryMultisortNeue/maintenance/deleteSortKeys.php [options]
 *
 * The "CategoryMultisortNeue:" prefix is required when going through run.php:
 * without it the runner only looks in core's own maintenance directory.
 *
 * @license GPL-2.0-or-later
 * @file
 * @ingroup Maintenance
 */

namespace MediaWiki\Extension\CategoryMultisortNeue\Maintenance;

use MediaWiki\Extension\CategoryMultisortNeue\SortModeRegistry;
use MediaWiki\Maintenance\Maintenance;
use Throwable;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IMaintainableDatabase;
use Wikimedia\Rdbms\IReadableDatabase;

// @codeCoverageIgnoreStart
$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";
// @codeCoverageIgnoreEnd

/**
 * Deletes orphan and stale categorylinks_multisort rows.
 */
class DeleteSortKeys extends Maintenance {

	private const TABLE = 'categorylinks_multisort';

	/** Tuples per DELETE statement; keeps the generated SQL modest. */
	private const DELETE_CHUNK = 200;

	private IMaintainableDatabase $dbw;

	private IReadableDatabase $dbr;

	private bool $dryRun = false;

	/** @var string[] Configured mode ids, used to detect stale rows. */
	private array $configuredModes = [];

	public function __construct() {
		parent::__construct();

		$this->addDescription(
			'Delete categorylinks_multisort rows whose categorylinks row is gone, or ' .
			'whose sort mode is no longer configured.'
		);

		$this->addOption( 'dry-run', 'Only count the rows that would be deleted' );
		$this->addOption( 'mode', 'Restrict the cleanup to this mode id', false, true );
		$this->addOption( 'orphans-only', 'Do not look for rows of modes that are no longer configured' );
		$this->addOption( 'stale-modes-only', 'Only look for rows of modes that are no longer configured' );
		$this->addOption( 'limit', 'Stop after deleting this many rows (default: no limit)', false, true );

		$this->setBatchSize( 500 );
	}

	public function execute() {
		$this->dbw = $this->getDB( DB_PRIMARY );
		$this->dbr = $this->getReplicaDB();
		$this->dryRun = (bool)$this->getOption( 'dry-run' );

		if ( !$this->dbw->tableExists( self::TABLE, __METHOD__ ) ) {
			$this->fatalError(
				'Table ' . self::TABLE . ' does not exist. ' .
				'Run "php maintenance/run.php update.php --quick" first.'
			);
		}

		$registry = $this->getRegistry();
		$this->configuredModes = array_keys( $registry->getModes() );

		$modeFilter = $this->getOption( 'mode' );
		if ( $modeFilter !== null && !in_array( (string)$modeFilter, $this->configuredModes, true ) ) {
			$this->output( sprintf(
				"WARNING: mode \"%s\" is not configured; --mode only limits which rows are inspected.\n",
				$modeFilter
			) );
		}

		$orphansOnly = (bool)$this->getOption( 'orphans-only' );
		$staleOnly = (bool)$this->getOption( 'stale-modes-only' );
		if ( $orphansOnly && $staleOnly ) {
			$this->fatalError( 'Use either --orphans-only or --stale-modes-only, not both.' );
		}

		$limitOption = $this->getOption( 'limit' );
		$limit = $limitOption === null ? 0 : max( 0, (int)$limitOption );

		$this->output( sprintf(
			"Cleaning %s%s%s\n",
			self::TABLE,
			$this->dryRun ? ' (dry run)' : '',
			$modeFilter !== null ? ", mode filter: $modeFilter" : ''
		) );

		// Keyed by "from|target|mode": a row can be both an orphan and a stale
		// mode, and must only be counted once in the total.
		$deleted = [];
		if ( !$staleOnly ) {
			$this->deleteOrphans( $modeFilter, $limit, $deleted );
		}
		if ( !$orphansOnly ) {
			$this->deleteStaleModes( $modeFilter, $limit, $deleted );
		}

		if ( !$this->dryRun && $deleted !== [] ) {
			$this->waitForReplication();
		}

		$this->output( sprintf(
			"\n%d row(s) %s.\n",
			count( $deleted ),
			$this->dryRun ? 'would be deleted' : 'deleted'
		) );

		return true;
	}

	private function getRegistry(): SortModeRegistry {
		try {
			$registry = $this->getServiceContainer()->get( 'CategoryMultisortNeue.SortModeRegistry' );
		} catch ( Throwable $e ) {
			$this->fatalError(
				'The CategoryMultisortNeue extension is not loaded (is wfLoadExtension() in ' .
				'LocalSettings.php?): ' . $e->getMessage()
			);
		}
		if ( !$registry instanceof SortModeRegistry ) {
			$this->fatalError( 'CategoryMultisortNeue.SortModeRegistry has an unexpected type.' );
		}
		return $registry;
	}

	/**
	 * Delete rows whose (cms_from, cms_target_id) pair is not in categorylinks.
	 *
	 * @param array<string,bool> &$seen Collects "from|target|mode" keys.
	 */
	private function deleteOrphans( ?string $modeFilter, int $limit, array &$seen ): void {
		$batchSize = $this->getBatchSize();
		$total = 0;
		$cursor = null;

		while ( true ) {
			$qb = $this->dbr->newSelectQueryBuilder()
				->select( [ 'cms_from', 'cms_target_id', 'cms_mode' ] )
				->from( self::TABLE )
				->leftJoin( 'categorylinks', null, [
					'cl_from = cms_from',
					'cl_target_id = cms_target_id',
				] )
				->where( [ 'cl_from' => null ] )
				->orderBy( [ 'cms_from', 'cms_target_id', 'cms_mode' ] )
				->limit( $batchSize );

			if ( $modeFilter !== null ) {
				$qb->andWhere( [ 'cms_mode' => $modeFilter ] );
			}
			if ( $cursor !== null ) {
				$qb->andWhere( $this->keysetCondition( $cursor ) );
			}

			$batch = [];
			foreach ( $qb->caller( __METHOD__ )->fetchResultSet() as $row ) {
				$batch[] = [ (int)$row->cms_from, (int)$row->cms_target_id, $row->cms_mode ];
			}

			if ( $batch === [] ) {
				break;
			}

			$cursor = $batch[count( $batch ) - 1];
			$total += count( $batch );
			$this->rememberTuples( $batch, $seen );
			if ( !$this->dryRun ) {
				$this->deleteTuples( $batch );
			}
			$this->output( sprintf( "  orphan rows: %d%s\n", $total, $this->dryRun ? ' (dry run)' : '' ) );

			if ( count( $batch ) < $batchSize ) {
				break;
			}
			if ( $limit > 0 && $total >= $limit ) {
				$this->output( "  limit of $limit row(s) reached\n" );
				break;
			}
		}

		if ( $total === 0 ) {
			$this->output( "  no orphan rows\n" );
		}
	}

	/**
	 * Delete every row whose mode is not configured any more.
	 *
	 * @param array<string,bool> &$seen Collects "from|target|mode" keys.
	 */
	private function deleteStaleModes( ?string $modeFilter, int $limit, array &$seen ): void {
		$known = $this->configuredModes;
		$modeFilter = $modeFilter ?? '';

		$res = $this->dbr->newSelectQueryBuilder()
			->select( [ 'cms_mode', 'cnt' => 'COUNT(*)' ] )
			->from( self::TABLE )
			->groupBy( 'cms_mode' )
			->caller( __METHOD__ )
			->fetchResultSet();

		$staleModes = [];
		foreach ( $res as $row ) {
			if ( in_array( $row->cms_mode, $known, true ) ) {
				continue;
			}
			if ( $modeFilter !== '' && $row->cms_mode !== $modeFilter ) {
				continue;
			}
			$staleModes[$row->cms_mode] = (int)$row->cnt;
		}

		if ( $staleModes === [] ) {
			$this->output( "  no stale-mode rows\n" );
			return;
		}

		$total = 0;
		foreach ( $staleModes as $modeId => $count ) {
			$this->output( sprintf(
				"  mode %s: %d row(s) of an unconfigured mode\n",
				$modeId,
				$count
			) );
			if ( $this->dryRun ) {
				$rows = $this->dbw->newSelectQueryBuilder()
					->select( [ 'cms_from', 'cms_target_id' ] )
					->from( self::TABLE )
					->where( [ 'cms_mode' => $modeId ] )
					->limit( $count )
					->caller( __METHOD__ )
					->fetchResultSet();
				foreach ( $rows as $row ) {
					$seen[(int)$row->cms_from . '|' . (int)$row->cms_target_id . "|$modeId"] = true;
				}
				$total += $count;
				continue;
			}
			$this->deleteModeRows( $modeId, $limit > 0 ? $limit - $total : 0, $seen );
			$total += $count;
		}
	}

	/**
	 * Delete all rows of one mode, batched on (cms_from, cms_target_id).
	 *
	 * @param array<string,bool> &$seen Collects "from|target|mode" keys.
	 */
	private function deleteModeRows( string $modeId, int $limit, array &$seen ): int {
		$batchSize = $this->getBatchSize();
		$total = 0;

		while ( true ) {
			$take = $batchSize;
			if ( $limit > 0 ) {
				$take = min( $take, $limit - $total );
			}
			if ( $take <= 0 ) {
				break;
			}

			$res = $this->dbw->newSelectQueryBuilder()
				->select( [ 'cms_from', 'cms_target_id' ] )
				->from( self::TABLE )
				->where( [ 'cms_mode' => $modeId ] )
				->orderBy( [ 'cms_from', 'cms_target_id' ] )
				->limit( $take )
				->caller( __METHOD__ )
				->fetchResultSet();

			$tuples = [];
			foreach ( $res as $row ) {
				$tuples[] = [ (int)$row->cms_from, (int)$row->cms_target_id, $modeId ];
			}
			if ( $tuples === [] ) {
				break;
			}

			$this->rememberTuples( $tuples, $seen );
			$this->deleteTuples( $tuples );
			$total += count( $tuples );
			if ( count( $tuples ) < $take ) {
				break;
			}
		}

		return $total;
	}

	/**
	 * @param array<int,array{0:int,1:int,2:string}> $tuples
	 * @param array<string,bool> &$seen
	 */
	private function rememberTuples( array $tuples, array &$seen ): void {
		foreach ( $tuples as [ $from, $targetId, $modeId ] ) {
			$seen[$from . '|' . $targetId . '|' . $modeId] = true;
		}
	}

	/**
	 * @param array<int,array{0:int,1:int,2:string}> $tuples
	 */
	private function deleteTuples( array $tuples ): void {
		foreach ( array_chunk( $tuples, self::DELETE_CHUNK ) as $chunk ) {
			$conds = [];
			foreach ( $chunk as [ $from, $targetId, $modeId ] ) {
				$conds[] = $this->dbw->expr( 'cms_from', '=', $from )
					->and( 'cms_target_id', '=', $targetId )
					->and( 'cms_mode', '=', $modeId );
			}

			$this->beginTransactionRound( __METHOD__ );
			$this->dbw->newDeleteQueryBuilder()
				->deleteFrom( self::TABLE )
				->where( $this->dbw->makeList( $conds, IDatabase::LIST_OR ) )
				->caller( __METHOD__ )
				->execute();
			$this->commitTransactionRound( __METHOD__ );
		}
	}

	/**
	 * Keyset pagination over the primary key (cms_from, cms_target_id, cms_mode).
	 *
	 * @param array{0:int,1:int,2:string} $cursor
	 */
	private function keysetCondition( array $cursor ): string {
		[ $from, $targetId, $modeId ] = $cursor;
		return $this->dbw->makeList( [
			$this->dbw->expr( 'cms_from', '>', $from ),
			$this->dbw->expr( 'cms_from', '=', $from )->and( 'cms_target_id', '>', $targetId ),
			$this->dbw->expr( 'cms_from', '=', $from )
				->and( 'cms_target_id', '=', $targetId )
				->and( 'cms_mode', '>', $modeId ),
		], IDatabase::LIST_OR );
	}
}

$maintClass = DeleteSortKeys::class;
require_once RUN_MAINTENANCE_IF_MAIN;
return DeleteSortKeys::class;
