<?php
/**
 * Rebuild the CategoryMultisortNeue sort keys stored in categorylinks_multisort.
 *
 * The script walks categorylinks in batches of cl_from, exactly like core's
 * maintenance/updateCollation.php, and recomputes the binary sort key of every
 * (page, category, mode) triple.  The key itself is produced by
 * SortMode::getSortKey(), i.e.
 *
 *     substr( $collation->getSortKey( $title->getCategorySortkey( $prefix ) ), 0, 230 )
 *
 * with $prefix taken from cl_sortkey_prefix (with core's old-row fallback), so
 * the rows written here are byte-identical to what core would have written for
 * the site collation.
 *
 * Pages that pin a sort key per mode with {{#multisortdefaultsort:}} or
 * {{#multisortsortkey:}} override that prefix for the named mode, with the same
 * precedence as includes/LinksUpdateHooks.php:
 * per-category key > per-mode DEFAULTSORT > cl_sortkey_prefix.
 *
 * Usage:
 *   php maintenance/run.php CategoryMultisortNeue:rebuildSortKeys [options]
 *   php extensions/CategoryMultisortNeue/maintenance/rebuildSortKeys.php [options]
 *
 * The "CategoryMultisortNeue:" prefix is required when going through run.php:
 * without it the runner only looks in core's own maintenance directory.  The
 * direct form relies on RUN_MAINTENANCE_IF_MAIN and MW_INSTALL_PATH (which is
 * derived from the file location when the environment variable is not set).
 *
 * @license GPL-2.0-or-later
 * @file
 * @ingroup Maintenance
 */

namespace MediaWiki\Extension\CategoryMultisortNeue\Maintenance;

use MediaWiki\Extension\CategoryMultisortNeue\SortMode;
use MediaWiki\Extension\CategoryMultisortNeue\SortModeRegistry;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Title\Title;
use Throwable;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IMaintainableDatabase;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\IResultWrapper;

// @codeCoverageIgnoreStart
$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";
// @codeCoverageIgnoreEnd

/**
 * Fills categorylinks_multisort from categorylinks.
 */
class RebuildSortKeys extends Maintenance {

	/** Extension table holding one sort key per (page, category, mode). */
	private const TABLE = 'categorylinks_multisort';

	/** Page property names written by the magic words (BLUEPRINT §4.5). */
	private const PROP_DEFAULTSORT_PREFIX = 'cms-defaultsort-';

	private const PROP_SORTKEY_PREFIX = 'cms-sortkey-';

	/**
	 * How many (page, category) pairs are folded into one DELETE statement when
	 * stale rows are removed.  Keeps the generated SQL well below any limit.
	 */
	private const DELETE_CHUNK = 400;

	/** @var array<string,SortMode> Mode id => mode, only the available ones. */
	private array $modes = [];

	private IMaintainableDatabase $dbw;

	private IReadableDatabase $dbr;

	private bool $dryRun = false;

	private bool $force = false;

	public function __construct() {
		parent::__construct();

		$this->addDescription(
			'Rebuild the per-mode sort keys in categorylinks_multisort from the core ' .
			'categorylinks table, using the same key algorithm as updateCollation.php.'
		);

		$this->addOption( 'mode', 'Only rebuild this mode id (default: every configured mode)', false, true );
		$this->addOption( 'dry-run', 'Report what would change, but do not write to the database' );
		$this->addOption( 'force', 'Rewrite rows even when the stored key is already correct' );
		$this->addOption(
			'since',
			'Only rebuild rows whose categorylinks row was inserted/updated at or after this time ' .
			'(YYYYMMDDHHMMSS, ISO 8601 or any strtotime() expression)',
			false,
			true
		);

		$this->setBatchSize( 200 );
	}

	public function execute() {
		$this->dbw = $this->getDB( DB_PRIMARY );
		$this->dbr = $this->getReplicaDB();
		$this->dryRun = (bool)$this->getOption( 'dry-run' );
		$this->force = (bool)$this->getOption( 'force' );

		if ( !$this->dbw->tableExists( self::TABLE, __METHOD__ ) ) {
			$this->fatalError(
				'Table ' . self::TABLE . ' does not exist. ' .
				'Run "php maintenance/run.php update.php --quick" first.'
			);
		}

		$this->modes = $this->resolveModes();
		$since = $this->resolveSince();

		$batchSize = $this->getBatchSize();
		$maxFrom = (int)$this->dbr->newSelectQueryBuilder()
			->select( 'MAX(cl_from)' )
			->from( 'categorylinks' )
			->caller( __METHOD__ )
			->fetchField();

		$this->output( sprintf(
			"Rebuilding mode(s): %s\n",
			implode( ', ', array_keys( $this->modes ) )
		) );
		$this->output( sprintf(
			'categorylinks rows with cl_from <= %d, batch size %d%s%s' . "\n",
			$maxFrom,
			$batchSize,
			$since !== null ? ", cl_timestamp >= $since" : '',
			$this->dryRun ? ' (dry run)' : ''
		) );

		$stats = [
			'rows' => 0,
			'pairs' => 0,
			'written' => 0,
			'unchanged' => 0,
			'capped' => 0,
			'errors' => 0,
		];

		$batchStart = 0;
		do {
			$rows = $this->fetchBatch( $batchStart, $batchSize, $since );
			$delta = $this->processBatch( $rows );
			foreach ( $delta as $name => $value ) {
				$stats[$name] += $value;
			}
			$batchStart += $batchSize;
			$this->output( sprintf(
				"  cl_from %d-%d: %d row(s), %d pair(s) rebuilt, %d key(s) %s\n",
				$batchStart - $batchSize,
				$batchStart - 1,
				$delta['rows'],
				$delta['pairs'],
				$delta['written'],
				$this->dryRun ? 'would be written' : 'written'
			) );
		} while ( $maxFrom >= $batchStart );

		$this->output( sprintf(
			"\n%d categorylinks row(s) scanned, %d (page, category) pair(s) rebuilt, " .
			"%d key(s) %s, %d pair(s) already correct, %d key(s) hit the 230-byte cap.\n",
			$stats['rows'],
			$stats['pairs'],
			$stats['written'],
			$this->dryRun ? 'would be written' : 'written',
			$stats['unchanged'],
			$stats['capped']
		) );

		if ( $stats['errors'] > 0 ) {
			$this->fatalError( $stats['errors'] . ' key(s) could not be computed, see the errors above.' );
		}

		return true;
	}

	/**
	 * The modes to rebuild: --mode if given, otherwise every available mode.
	 *
	 * @return array<string,SortMode>
	 */
	private function resolveModes(): array {
		$registry = $this->getRegistry();

		$requested = $this->getOption( 'mode' );
		if ( $requested !== null ) {
			$mode = $registry->getMode( (string)$requested );
			if ( $mode === null ) {
				$this->fatalError( sprintf(
					'Unknown mode id "%s". Configured modes: %s',
					$requested,
					implode( ', ', array_keys( $registry->getModes() ) ) ?: '(none)'
				) );
			}
			if ( !$mode->isAvailable() ) {
				$this->fatalError( sprintf(
					'Mode "%s" is configured but not usable: %s',
					$requested,
					$mode->getError()
				) );
			}
			return [ $mode->id => $mode ];
		}

		$modes = [];
		foreach ( $registry->getModes() as $id => $mode ) {
			if ( $mode->isAvailable() ) {
				$modes[$id] = $mode;
			} else {
				$this->output( sprintf(
					"WARNING: skipping unusable mode \"%s\": %s\n",
					$id,
					$mode->getError()
				) );
			}
		}

		if ( $modes === [] ) {
			$this->fatalError( 'No usable sort mode is configured, nothing to rebuild.' );
		}
		return $modes;
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
	 * Parse --since into a MW timestamp (YYYYMMDDHHMMSS), or null when not given.
	 */
	private function resolveSince(): ?string {
		$raw = $this->getOption( 'since' );
		if ( $raw === null || $raw === '' ) {
			return null;
		}

		$raw = (string)$raw;
		if ( preg_match( '/^\d{14}$/', $raw ) ) {
			return $raw;
		}

		$ts = strtotime( $raw );
		if ( $ts === false ) {
			$this->fatalError( "Cannot parse --since value \"$raw\"." );
		}
		return wfTimestamp( TS_MW, $ts );
	}

	/**
	 * One cl_from range of categorylinks rows, joined with the page table so
	 * Title::newFromRow() has page_namespace/page_title, and with linktarget so
	 * the per-mode page properties can be matched by category DB key.
	 *
	 * @return IResultWrapper
	 */
	private function fetchBatch( int $start, int $size, ?string $since ) {
		$qb = $this->dbr->newSelectQueryBuilder()
			->select( [
				'cl_from',
				'cl_target_id',
				'cl_sortkey_prefix',
				'cl_sortkey',
				'collation_name',
				'page_namespace',
				'page_title',
				'lt_title',
			] )
			->from( 'categorylinks' )
			->join( 'page', null, 'cl_from = page_id' )
			->join( 'linktarget', null, 'cl_target_id = lt_id' )
			->leftJoin( 'collation', null, 'cl_collation_id = collation_id' )
			->where( $this->dbr->expr( 'cl_from', '>=', $start )->and( 'cl_from', '<', $start + $size ) )
			->orderBy( 'cl_from' );

		if ( $since !== null ) {
			$qb->andWhere( $this->dbr->expr( 'cl_timestamp', '>=', $since ) );
		}

		return $qb->caller( __METHOD__ )->fetchResultSet();
	}

	/**
	 * Rebuild one batch of (page, category) pairs.
	 *
	 * @param IResultWrapper $rows
	 * @return array<string,int> Per-batch counters to add to the run totals.
	 */
	private function processBatch( $rows ): array {
		$stats = [
			'rows' => 0,
			'pairs' => 0,
			'written' => 0,
			'unchanged' => 0,
			'capped' => 0,
			'errors' => 0,
		];

		/** @var array<int,array<int,array{title:Title,prefix:string,target:string}>> $pairs */
		$pairs = [];
		foreach ( $rows as $row ) {
			$title = Title::newFromRow( $row );
			if ( !$title ) {
				$stats['errors']++;
				$this->error( "Cannot build a Title for page {$row->page_namespace}:{$row->page_title}" );
				continue;
			}
			$stats['rows']++;
			$pairs[(int)$row->cl_from][(int)$row->cl_target_id] = [
				'title' => $title,
				'prefix' => self::prefixForRow( $title, $row ),
				'target' => (string)$row->lt_title,
			];
		}

		if ( $pairs === [] ) {
			return $stats;
		}

		$perMode = $this->fetchPerModePrefixes( array_keys( $pairs ) );

		$expected = $this->computeExpectedKeys( $pairs, $perMode, $stats );
		if ( $expected === [] ) {
			return $stats;
		}

		$stored = $this->fetchStoredKeys( array_keys( $pairs ) );

		$toInsert = [];
		$toClean = [];
		foreach ( $expected as $from => $byTarget ) {
			foreach ( $byTarget as $targetId => $keys ) {
				$dirty = $this->force;
				foreach ( $keys as $modeId => $key ) {
					if ( ( $stored[$from][$targetId][$modeId] ?? null ) !== $key ) {
						$dirty = true;
					}
				}

				if ( !$dirty ) {
					$stats['unchanged']++;
					continue;
				}

				$stats['pairs']++;
				$toClean[] = [ $from, $targetId ];
				foreach ( $keys as $modeId => $key ) {
					$toInsert[] = [
						'cms_from' => $from,
						'cms_target_id' => $targetId,
						'cms_mode' => $modeId,
						'cms_sortkey' => $key,
					];
				}
			}
		}

		$stats['written'] += count( $toInsert );
		if ( $this->dryRun || $toInsert === [] ) {
			return $stats;
		}

		// Delete-then-insert keeps the batch idempotent and removes rows whose
		// mode was dropped from the configuration.  The DELETE is restricted to
		// the exact (page, category) pairs and modes being rewritten, so rows
		// belonging to pages outside the current batch stay untouched.
		$modeIds = array_keys( $this->modes );
		$this->beginTransactionRound( __METHOD__ );
		foreach ( array_chunk( $toClean, self::DELETE_CHUNK ) as $chunk ) {
			$this->dbw->newDeleteQueryBuilder()
				->deleteFrom( self::TABLE )
				->where( $this->tupleCondition( $chunk, $modeIds ) )
				->caller( __METHOD__ )
				->execute();
		}
		$this->dbw->newInsertQueryBuilder()
			->insertInto( self::TABLE )
			->rows( $toInsert )
			->caller( __METHOD__ )
			->execute();
		$this->commitTransactionRound( __METHOD__ );

		return $stats;
	}

	/**
	 * @param array<int,array<int,array{title:Title,prefix:string,target:string}>> $pairs
	 * @param array{default:array,perCategory:array} $perMode
	 * @param array<string,int> &$stats
	 * @return array<int,array<int,array<string,string>>>
	 */
	private function computeExpectedKeys( array $pairs, array $perMode, array &$stats ): array {
		$expected = [];
		foreach ( $pairs as $from => $byTarget ) {
			foreach ( $byTarget as $targetId => $info ) {
				foreach ( $this->modes as $modeId => $mode ) {
					$prefix = self::resolvePrefix(
						$perMode,
						$from,
						$modeId,
						$info['target'],
						$info['prefix']
					);
					try {
						$key = $mode->getSortKey( $info['title'], $prefix );
					} catch ( Throwable $e ) {
						$stats['errors']++;
						$this->error( sprintf(
							'mode "%s" failed for page %d / category %d: %s',
							$modeId,
							$from,
							$targetId,
							$e->getMessage()
						) );
						continue;
					}
					if ( strlen( $key ) >= 230 ) {
						$stats['capped']++;
					}
					$expected[$from][$targetId][$modeId] = $key;
				}
			}
		}
		return $expected;
	}

	/**
	 * The per-mode sort key overrides of the pages in this batch, read from the
	 * page properties the {{#multisortdefaultsort:}} / {{#multisortsortkey:}}
	 * magic words write (BLUEPRINT §4.5).
	 *
	 * The maintenance scripts have to honour exactly the same precedence as
	 * includes/LinksUpdateHooks.php, otherwise a full rebuild would clobber the
	 * keys of pages that pin a sort key per mode:
	 *
	 *     per-category key  >  per-mode DEFAULTSORT  >  cl_sortkey_prefix
	 *
	 * @param int[] $pageIds
	 * @return array{default:array<int,array<string,string>>,perCategory:array<int,array<string,array<string,string>>>}
	 */
	private function fetchPerModePrefixes( array $pageIds ): array {
		$default = [];
		$perCategory = [];

		// List the property names explicitly: pp_propname is a fixed set of keys,
		// and an IN () scan avoids the LIKE API (which wants a LikeValue in 1.46).
		$propNames = [];
		foreach ( array_keys( $this->modes ) as $modeId ) {
			$propNames[] = self::PROP_DEFAULTSORT_PREFIX . $modeId;
			$propNames[] = self::PROP_SORTKEY_PREFIX . $modeId;
		}

		$res = $this->dbr->newSelectQueryBuilder()
			->select( [ 'pp_page', 'pp_propname', 'pp_value' ] )
			->from( 'page_props' )
			->where( [ 'pp_page' => $pageIds, 'pp_propname' => $propNames ] )
			->caller( __METHOD__ )
			->fetchResultSet();

		foreach ( $res as $row ) {
			$pageId = (int)$row->pp_page;
			$name = (string)$row->pp_propname;
			$value = (string)$row->pp_value;

			if ( str_starts_with( $name, self::PROP_DEFAULTSORT_PREFIX ) ) {
				// An empty value means "not set" (BLUEPRINT §4.5).
				$modeId = substr( $name, strlen( self::PROP_DEFAULTSORT_PREFIX ) );
				if ( $modeId !== '' && $value !== '' ) {
					$default[$pageId][$modeId] = $value;
				}
				continue;
			}

			if ( str_starts_with( $name, self::PROP_SORTKEY_PREFIX ) ) {
				$modeId = substr( $name, strlen( self::PROP_SORTKEY_PREFIX ) );
				if ( $modeId === '' ) {
					continue;
				}
				foreach ( explode( "\n", $value ) as $line ) {
					if ( $line === '' ) {
						continue;
					}
					$parts = explode( "\t", $line, 2 );
					if ( count( $parts ) === 2 && $parts[0] !== '' && $parts[1] !== '' ) {
						$perCategory[$pageId][$modeId][$parts[0]] = $parts[1];
					}
				}
			}
		}

		return [ 'default' => $default, 'perCategory' => $perCategory ];
	}

	/**
	 * @param array{default:array,perCategory:array} $perMode
	 */
	private static function resolvePrefix(
		array $perMode,
		int $pageId,
		string $modeId,
		string $categoryDbKey,
		string $corePrefix
	): string {
		return $perMode['perCategory'][$pageId][$modeId][$categoryDbKey]
			?? $perMode['default'][$pageId][$modeId]
			?? $corePrefix;
	}

	/**
	 * @param int[] $fromIds
	 * @return array<int,array<int,array<string,string>>>
	 */
	private function fetchStoredKeys( array $fromIds ): array {
		$stored = [];
		$res = $this->dbr->newSelectQueryBuilder()
			->select( [ 'cms_from', 'cms_target_id', 'cms_mode', 'cms_sortkey' ] )
			->from( self::TABLE )
			->where( [
				'cms_from' => $fromIds,
				'cms_mode' => array_keys( $this->modes ),
			] )
			->caller( __METHOD__ )
			->fetchResultSet();

		foreach ( $res as $row ) {
			$stored[(int)$row->cms_from][(int)$row->cms_target_id][$row->cms_mode] = $row->cms_sortkey;
		}
		return $stored;
	}

	/**
	 * Build "((cms_from = 1 AND cms_target_id = 2 AND cms_mode IN ('a','b')) OR ...)".
	 *
	 * @param array<int,array{0:int,1:int}> $pairs
	 * @param string[] $modeIds
	 */
	private function tupleCondition( array $pairs, array $modeIds ): string {
		$conds = [];
		foreach ( $pairs as [ $from, $targetId ] ) {
			$conds[] = $this->dbw->makeList(
				[
					'cms_from' => $from,
					'cms_target_id' => $targetId,
					'cms_mode' => $modeIds,
				],
				IDatabase::LIST_AND
			);
		}
		return $this->dbw->makeList( $conds, IDatabase::LIST_OR );
	}

	/**
	 * The human readable prefix core would use for this row.  Mirrors the
	 * fallback in maintenance/updateCollation.php for rows written before
	 * cl_collation_id existed.
	 *
	 * @param Title $title
	 * @param \stdClass $row A categorylinks row with cl_sortkey/cl_sortkey_prefix/collation_name.
	 */
	private static function prefixForRow( Title $title, $row ): string {
		if ( !$row->collation_name ) {
			if ( $row->cl_sortkey === $title->getText()
				|| $row->cl_sortkey === $title->getPrefixedText()
			) {
				return '';
			}
			return (string)$row->cl_sortkey;
		}
		return (string)$row->cl_sortkey_prefix;
	}
}

$maintClass = RebuildSortKeys::class;
require_once RUN_MAINTENANCE_IF_MAIN;
return RebuildSortKeys::class;
