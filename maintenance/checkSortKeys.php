<?php
/**
 * Cross-check categorylinks_multisort against categorylinks.
 *
 * For every configured sort mode the script recomputes the sort key of each
 * categorylinks row with the mode's own collation and compares it with the row
 * stored in the extension table.  It reports four kinds of trouble:
 *
 *   - missing   : a categorylinks row without a matching extension row
 *   - mismatch  : the stored key differs from the freshly computed key
 *   - orphan    : an extension row whose (page, category) pair is gone
 *   - stale     : an extension row whose mode is no longer configured
 *
 * It also exercises getFirstLetter() for every mode, because a collation that
 * can sort but cannot produce a first letter (REPORT.md 6.1) would turn every
 * category page of that mode into a 500.
 *
 * Exit status is non-zero when any problem is found, so the script can gate CI.
 *
 * Usage:
 *   php maintenance/run.php CategoryMultisortNeue:checkSortKeys [options]
 *   php extensions/CategoryMultisortNeue/maintenance/checkSortKeys.php [options]
 *
 * The "CategoryMultisortNeue:" prefix is required when going through run.php:
 * without it the runner only looks in core's own maintenance directory.
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
 * Verifies that every category member has the right sort key for every mode.
 */
class CheckSortKeys extends Maintenance {

	private const TABLE = 'categorylinks_multisort';

	/** Page property names written by the magic words (BLUEPRINT §4.5). */
	private const PROP_DEFAULTSORT_PREFIX = 'cms-defaultsort-';

	private const PROP_SORTKEY_PREFIX = 'cms-sortkey-';

	/** @var array<string,SortMode> Mode id => mode, all configured modes. */
	private array $allModes = [];

	/** @var array<string,SortMode> Mode id => mode, the ones actually checked. */
	private array $modes = [];

	/** @var string[] Language variants being validated. */
	private array $variants = [];

	private IMaintainableDatabase $dbw;

	private IReadableDatabase $dbr;

	private bool $verbose = false;

	private int $samples = 10;

	private bool $ignoreUnavailable = false;

	/** Problem counters, filled while the checks run. */
	private int $missing = 0;

	private int $mismatched = 0;

	private int $orphans = 0;

	private int $stale = 0;

	private int $unavailableModes = 0;

	/** @var array<string,int> How many samples of each problem class were printed. */
	private array $sampleCounts = [];

	public function __construct() {
		parent::__construct();

		$this->addDescription(
			'Compare categorylinks_multisort with the keys the configured collations ' .
			'compute today, and report missing, mismatching, orphan and stale rows.'
		);

		$this->addOption( 'mode', 'Only check this mode id (default: every configured mode)', false, true );
		$this->addOption( 'verbose', 'Print sample rows for every problem class' );
		$this->addOption( 'samples', 'How many sample rows to print with --verbose (default 10)', false, true );
		$this->addOption(
			'ignore-unavailable',
			'Do not fail when a configured mode cannot produce a sort key / first letter'
		);

		$this->setBatchSize( 500 );
	}

	public function execute() {
		$this->dbw = $this->getDB( DB_PRIMARY );
		$this->dbr = $this->getReplicaDB();
		$this->verbose = (bool)$this->getOption( 'verbose' );
		$this->ignoreUnavailable = (bool)$this->getOption( 'ignore-unavailable' );
		$samples = $this->getOption( 'samples' );
		if ( $samples !== null ) {
			$this->samples = max( 0, (int)$samples );
		}

		if ( !$this->dbw->tableExists( self::TABLE, __METHOD__ ) ) {
			$this->fatalError(
				'Table ' . self::TABLE . ' does not exist. ' .
				'Run "php maintenance/run.php update.php --quick" first.'
			);
		}

		$registry = $this->getRegistry();
		$this->allModes = $registry->getModes();
		$this->modes = $this->resolveModes();
		$this->variants = $this->getServiceContainer()
			->get( 'CategoryMultisortNeue.VariantRegistry' )->getVariants();

		$this->reportModeAvailability( $registry );
		$this->checkRows();
		$this->checkExtraRows( $registry );

		$problems = $this->missing + $this->mismatched + $this->orphans + $this->stale;
		$this->output( "\n" . str_repeat( '=', 72 ) . "\n" );
		$this->output( sprintf(
			"missing=%d mismatch=%d orphan=%d stale=%d unavailable-modes=%d\n",
			$this->missing,
			$this->mismatched,
			$this->orphans,
			$this->stale,
			$this->unavailableModes
		) );

		if ( $problems === 0 && ( $this->unavailableModes === 0 || $this->ignoreUnavailable ) ) {
			$this->output( "checkSortKeys: OK - every stored key matches its collation.\n" );
			return true;
		}

		$reasons = [];
		if ( $problems > 0 ) {
			$reasons[] = "$problems inconsistent row(s)";
		}
		if ( $this->unavailableModes > 0 && !$this->ignoreUnavailable ) {
			$reasons[] = "{$this->unavailableModes} unusable mode(s)";
		}
		$this->fatalError( 'checkSortKeys: FAILED - ' . implode( ', ', $reasons ) . '.' );
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
	 * @return array<string,SortMode>
	 */
	private function resolveModes(): array {
		$requested = $this->getOption( 'mode' );
		if ( $requested === null ) {
			return array_filter( $this->allModes, static fn ( SortMode $m ) => $m->isAvailable() );
		}

		$mode = $this->allModes[(string)$requested] ?? null;
		if ( $mode === null ) {
			$this->fatalError( sprintf(
				'Unknown mode id "%s". Configured modes: %s',
				$requested,
				implode( ', ', array_keys( $this->allModes ) ) ?: '(none)'
			) );
		}
		if ( !$mode->isAvailable() ) {
			$this->fatalError( sprintf(
				'Mode "%s" is configured but not usable, so its keys cannot be verified: %s',
				$requested,
				$mode->getError()
			) );
		}
		return [ $mode->id => $mode ];
	}

	/**
	 * Print one line per configured mode, including a live getFirstLetter() probe.
	 */
	private function reportModeAvailability( SortModeRegistry $registry ): void {
		$this->output( "Configured modes:\n" );
		foreach ( $this->allModes as $id => $mode ) {
			$status = $mode->isAvailable() ? 'available' : 'UNAVAILABLE';
			$this->output( sprintf(
				"  %-12s %-14s %-34s %s\n",
				$id,
				$status,
				$mode->collationName . ( $mode->isDefault ? ' (site default)' : '' ),
				$mode->isAvailable() ? '' : $mode->getError()
			) );

			if ( !$mode->isAvailable() ) {
				$this->unavailableModes++;
				continue;
			}

			// REPORT.md 6.1: getFirstLetter() throws for ICU locales that are
			// not registered in first-letters-root.php, even though getSortKey()
			// works.  A mode in that state must never reach a category page.
			// Probed through the mode rather than the collation directly, so this
			// exercises the same path the category page uses.
			try {
				$letters = [];
				foreach ( [ '安', 'A', '1' ] as $probe ) {
					$letters[] = $probe . '=>' . $mode->getFirstLetter(
						Title::makeTitle( NS_MAIN, $probe ),
						''
					);
				}
				$this->output( '               first letters: ' . implode( ' ', $letters ) . "\n" );
			} catch ( Throwable $e ) {
				$this->unavailableModes++;
				$this->error( sprintf(
					'mode "%s": getFirstLetter() failed: %s',
					$id,
					$e->getMessage()
				) );
			}
		}

		if ( !$this->modes ) {
			$this->fatalError( 'No usable mode to check.' );
		}
		$this->output( sprintf(
			"Checking %d mode(s): %s\n\n",
			count( $this->modes ),
			implode( ', ', array_keys( $this->modes ) )
		) );
	}

	/**
	 * Walk categorylinks in cl_from batches and compare every row.
	 */
	private function checkRows(): void {
		$batchSize = $this->getBatchSize();
		$maxFrom = (int)$this->dbr->newSelectQueryBuilder()
			->select( 'MAX(cl_from)' )
			->from( 'categorylinks' )
			->caller( __METHOD__ )
			->fetchField();
		$rowCount = (int)$this->dbr->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( 'categorylinks' )
			->caller( __METHOD__ )
			->fetchField();

		$this->output( sprintf(
			"Checking %d categorylinks row(s) against %s, batch size %d...\n",
			$rowCount,
			self::TABLE,
			$batchSize
		) );

		$batchStart = 0;
		do {
			$rows = $this->dbw->newSelectQueryBuilder()
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
				->where( $this->dbw->expr( 'cl_from', '>=', $batchStart )
					->and( 'cl_from', '<', $batchStart + $batchSize ) )
				->orderBy( 'cl_from' )
				->caller( __METHOD__ )
				->fetchResultSet();

			$this->compareBatch( $rows );
			$batchStart += $batchSize;
		} while ( $maxFrom >= $batchStart );
	}

	private function compareBatch( IResultWrapper $rows ): void {
		/** @var array<int,array<int,array{title:Title,prefix:string,target:string}>> $pairs */
		$pairs = [];
		foreach ( $rows as $row ) {
			$title = Title::newFromRow( $row );
			if ( !$title ) {
				$this->error( "Cannot build a Title for page {$row->page_namespace}:{$row->page_title}" );
				$this->mismatched++;
				continue;
			}
			$pairs[(int)$row->cl_from][(int)$row->cl_target_id] = [
				'title' => $title,
				'prefix' => self::prefixForRow( $title, $row ),
				'target' => (string)$row->lt_title,
			];
		}

		if ( $pairs === [] ) {
			return;
		}

		$perMode = $this->fetchPerModePrefixes( array_keys( $pairs ) );
		$stored = $this->fetchStoredKeys( array_keys( $pairs ) );

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
					foreach ( $this->variants as $variant ) {
						try {
							$expected = $mode->getSortKey( $info['title'], $prefix, $variant );
						} catch ( Throwable $e ) {
							$this->error( sprintf(
								'mode "%s" variant "%s" failed for page %d / category %d: %s',
								$modeId,
								$variant,
								$from,
								$targetId,
								$e->getMessage()
							) );
							$this->mismatched++;
							continue;
						}

						$actual = $stored[$from][$targetId][$modeId][$variant] ?? null;
						if ( $actual === null ) {
							$this->missing++;
							$this->sample( 'missing', sprintf(
								'page %d "%s" -> category %d, mode %s, variant %s',
								$from,
								$info['title']->getPrefixedText(),
								$targetId,
								$modeId,
								$variant
							) );
							continue;
						}

						if ( $actual !== $expected ) {
							$this->mismatched++;
							$this->sample( 'mismatch', sprintf(
								'page %d "%s" -> category %d, mode %s, variant %s: stored %s, computed %s',
								$from,
								$info['title']->getPrefixedText(),
								$targetId,
								$modeId,
								$variant,
								bin2hex( $actual ),
								bin2hex( $expected )
							) );
						}
					}
				}
			}
		}
	}

	/**
	 * Look for extension rows that should not exist: no matching categorylinks
	 * row (orphan) or a mode that is no longer configured (stale).
	 */
	private function checkExtraRows( SortModeRegistry $registry ): void {
		$total = (int)$this->dbr->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( self::TABLE )
			->caller( __METHOD__ )
			->fetchField();
		$clCount = (int)$this->dbr->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( 'categorylinks' )
			->caller( __METHOD__ )
			->fetchField();
		$expectedShape = count( $this->allModes ) * $clCount;

		$this->output( sprintf(
			"\n%s holds %d row(s); %d categorylinks row(s) x %d configured mode(s) = %d expected.\n",
			self::TABLE,
			$total,
			$clCount,
			count( $this->allModes ),
			$expectedShape
		) );

		// Orphans: extension rows whose (page, category) pair is not in categorylinks.
		$orphanCounts = [];
		$res = $this->dbr->newSelectQueryBuilder()
			->select( [ 'cms_mode', 'cnt' => 'COUNT(*)' ] )
			->from( self::TABLE )
			->leftJoin( 'categorylinks', null, [
				'cl_from = cms_from',
				'cl_target_id = cms_target_id',
			] )
			->where( [ 'cl_from' => null ] )
			->groupBy( 'cms_mode' )
			->caller( __METHOD__ )
			->fetchResultSet();
		foreach ( $res as $row ) {
			$orphanCounts[$row->cms_mode] = (int)$row->cnt;
			$this->orphans += (int)$row->cnt;
		}

		if ( $this->orphans > 0 ) {
			$this->output( "Orphan rows (no matching categorylinks row):\n" );
			foreach ( $orphanCounts as $modeId => $count ) {
				$this->output( sprintf( "  mode %-12s %d row(s)\n", $modeId, $count ) );
			}
			$sampleRes = $this->dbr->newSelectQueryBuilder()
				->select( [ 'cms_from', 'cms_target_id', 'cms_mode' ] )
				->from( self::TABLE )
				->leftJoin( 'categorylinks', null, [
					'cl_from = cms_from',
					'cl_target_id = cms_target_id',
				] )
				->where( [ 'cl_from' => null ] )
				->limit( $this->samples )
				->caller( __METHOD__ )
				->fetchResultSet();
			foreach ( $sampleRes as $row ) {
				$this->sample( 'orphan', sprintf(
					'page %d -> category %d, mode %s',
					$row->cms_from,
					$row->cms_target_id,
					$row->cms_mode
				) );
			}
		} else {
			$this->output( "No orphan rows.\n" );
		}

		// Stale: rows for a mode or a variant that is not in the configuration any more.
		//
		// Variants have to be checked as well as modes, and for the same reason: a row
		// stored for an unconfigured variant is not a wrong key, it is a key for an
		// ordering this wiki no longer serves. Comparing it against the configured
		// variant's key would report every such row as a mismatch, which is what a rebuild
		// under a different setting leaves behind.
		$configuredIds = array_keys( $registry->getModes() );
		$configuredVariants = $this->variants;
		$staleCounts = [];
		$res = $this->dbr->newSelectQueryBuilder()
			->select( [ 'cms_mode', 'cms_variant', 'cnt' => 'COUNT(*)' ] )
			->from( self::TABLE )
			->groupBy( [ 'cms_mode', 'cms_variant' ] )
			->caller( __METHOD__ )
			->fetchResultSet();
		foreach ( $res as $row ) {
			$modeKnown = in_array( $row->cms_mode, $configuredIds, true );
			$variantKnown = in_array( $row->cms_variant, $configuredVariants, true );
			if ( $modeKnown && $variantKnown ) {
				continue;
			}
			$reason = $modeKnown ? 'variant' : 'mode';
			$staleCounts[] = [
				'mode' => $row->cms_mode,
				'variant' => $row->cms_variant,
				'reason' => $reason,
				'count' => (int)$row->cnt,
			];
			$this->stale += (int)$row->cnt;
		}

		if ( $this->stale > 0 ) {
			$this->output( "Stale rows (mode or variant no longer configured):\n" );
			foreach ( $staleCounts as $entry ) {
				$this->output( sprintf(
					"  mode %-12s variant %-10s %d row(s), stale %s; clean up with deleteSortKeys.php\n",
					$entry['mode'],
					$entry['variant'],
					$entry['count'],
					$entry['reason']
				) );
			}
		} else {
			$this->output( "No stale rows.\n" );
		}
	}

	/**
	 * Print a problem sample when --verbose is active, at most $this->samples per class.
	 */
	private function sample( string $class, string $message ): void {
		if ( !$this->verbose || $this->samples <= 0 ) {
			return;
		}
		$this->sampleCounts[$class] = ( $this->sampleCounts[$class] ?? 0 ) + 1;
		if ( $this->sampleCounts[$class] > $this->samples ) {
			return;
		}
		$this->output( sprintf( "  [%s] %s\n", $class, $message ) );
	}

	/**
	 * The per-mode sort key overrides of these pages, read from the page
	 * properties the {{#multisortdefaultsort:}} / {{#multisortsortkey:}} magic
	 * words write (BLUEPRINT §4.5).
	 *
	 * checkSortKeys has to use the same precedence as the writer
	 * (includes/LinksUpdateHooks.php) and as rebuildSortKeys.php, otherwise every
	 * page that pins a sort key per mode is reported as a mismatch:
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
			->select( [ 'cms_from', 'cms_target_id', 'cms_mode', 'cms_variant', 'cms_sortkey' ] )
			->from( self::TABLE )
			->where( [
				'cms_from' => $fromIds,
				'cms_mode' => array_keys( $this->modes ),
			] )
			->caller( __METHOD__ )
			->fetchResultSet();

		foreach ( $res as $row ) {
			$stored[(int)$row->cms_from][(int)$row->cms_target_id][$row->cms_mode][$row->cms_variant]
				= $row->cms_sortkey;
		}
		return $stored;
	}

	/**
	 * The human readable prefix core would use for this row (same fallback as
	 * maintenance/updateCollation.php for rows without cl_collation_id).
	 *
	 * @param Title $title
	 * @param \stdClass $row
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

$maintClass = CheckSortKeys::class;
require_once RUN_MAINTENANCE_IF_MAIN;
return CheckSortKeys::class;
