<?php
/**
 * Registry of configured sort modes.
 *
 * @license GPL-2.0-or-later
 * @file
 */

namespace MediaWiki\Extension\CategoryMultisortNeue;

use MediaWiki\Collation\CollationFactory;
use MediaWiki\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;

/**
 * Turns $wgCategoryMultisortNeueModes into SortMode objects, once per request.
 *
 * Modes whose collation cannot produce both a sort key and a first letter are
 * kept in the registry but marked unavailable: the selector hides them and the
 * category viewer refuses to switch to them, so a bad configuration degrades
 * into "that option is missing" rather than a fatal error.
 */
class SortModeRegistry {

	/** @var array<string,SortMode>|null */
	private ?array $modes = null;

	private readonly LoggerInterface $logger;

	public function __construct(
		private readonly CategoryMultisortNeueConfig $config,
		private readonly CollationFactory $collationFactory,
		private readonly ?VariantFolder $variantFolder = null
	) {
		$this->logger = LoggerFactory::getInstance( 'CategoryMultisortNeue' );
	}

	/**
	 * The logger used for configuration problems, exposed so that collaborators
	 * (SortKeyStore) report through the same channel.
	 */
	public function getLogger(): LoggerInterface {
		return $this->logger;
	}

	private function load(): void {
		if ( $this->modes !== null ) {
			return;
		}

		$siteCollation = $this->config->getSiteCollationName();
		$modes = [];

		foreach ( $this->config->getRawModes() as $id => $collationName ) {
			$isDefault = $collationName === '';
			$resolved = $isDefault ? $siteCollation : $collationName;
			$mode = SortMode::create(
				$id,
				$resolved,
				$isDefault,
				fn ( string $name ) => $this->collationFactory->makeCollation( $name ),
				$this->variantFolder
			);
			if ( !$mode->isAvailable() ) {
				$this->logger->warning(
					'CategoryMultisortNeue: sort mode "{mode}" is unusable and will be hidden: {error}',
					[ 'mode' => $id, 'error' => $mode->getError() ]
				);
			}
			$modes[$id] = $mode;
		}

		foreach ( $this->config->getInvalidModes() as $id => $reason ) {
			if ( $id === '__config__' ) {
				$this->logger->error( 'CategoryMultisortNeue: {reason}', [ 'reason' => $reason ] );
				continue;
			}
			$this->logger->warning(
				'CategoryMultisortNeue: ignoring sort mode "{mode}": {reason}',
				[ 'mode' => $id, 'reason' => $reason ]
			);
		}

		$this->modes = $modes;
	}

	/**
	 * Every syntactically valid configured mode, available or not, in configuration order.
	 *
	 * @return array<string,SortMode>
	 */
	public function getModes(): array {
		$this->load();
		return $this->modes;
	}

	/**
	 * Only the modes that can actually be rendered.
	 *
	 * @return array<string,SortMode>
	 */
	public function getAvailableModes(): array {
		return array_filter( $this->getModes(), static fn ( SortMode $m ) => $m->isAvailable() );
	}

	public function getMode( string $id ): ?SortMode {
		$this->load();
		return $this->modes[$id] ?? null;
	}

	public function modeExists( string $id ): bool {
		$this->load();
		return isset( $this->modes[$id] );
	}

	/**
	 * The mode the wiki wants by default: $wgCategoryMultisortNeueDefaultMode when
	 * it names an available mode, otherwise the first available mode, otherwise
	 * whichever mode wraps the site collation.
	 */
	public function getDefaultModeId(): string {
		$available = $this->getAvailableModes();
		if ( $available === [] ) {
			return $this->getSiteDefaultModeId();
		}

		$configured = $this->config->getConfiguredDefaultModeId();
		if ( $configured !== null && isset( $available[$configured] ) ) {
			return $configured;
		}

		$first = array_key_first( $available );
		if ( $configured !== null && $configured !== $first ) {
			$this->logger->info(
				'CategoryMultisortNeue: default mode "{configured}" is not available, falling back to "{fallback}"',
				[ 'configured' => $configured, 'fallback' => $first ]
			);
		}
		return $first;
	}

	/**
	 * A mode id that wraps the site's own $wgCategoryCollation.  Used as the last
	 * resort when no configured mode is usable, so the category viewer can always
	 * fall back to exactly what core would have done.
	 */
	public function getSiteDefaultModeId(): string {
		foreach ( $this->getModes() as $id => $mode ) {
			if ( $mode->isDefault && $mode->isAvailable() ) {
				return $id;
			}
		}

		// No usable mode wraps the site collation.  Build one on the fly so the
		// caller always gets a real SortMode; it is not part of the configured set.
		return '__site__';
	}

	/**
	 * Never returns null: when every configured mode is unusable this synthesises
	 * a mode around $wgCategoryCollation, preserving core behaviour.
	 */
	public function getFallbackMode(): SortMode {
		$id = $this->getSiteDefaultModeId();
		$mode = $this->getMode( $id );
		if ( $mode !== null && $mode->isAvailable() ) {
			return $mode;
		}

		return SortMode::create(
			$id,
			$this->config->getSiteCollationName(),
			true,
			fn ( string $name ) => $this->collationFactory->makeCollation( $name )
		);
	}

	/**
	 * Resolve a mode id, falling back to the site default.  Never throws.
	 */
	public function resolveMode( string $id ): SortMode {
		$mode = $this->getMode( $id );
		if ( $mode !== null && $mode->isAvailable() ) {
			return $mode;
		}
		return $this->getFallbackMode();
	}

	/**
	 * Comma separated list of available mode ids, for parser error messages.
	 */
	public function getAvailableModeIdList(): string {
		return implode( ', ', array_keys( $this->getAvailableModes() ) );
	}
}
