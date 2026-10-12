<?php
/**
 * Category viewer with selectable sort modes.
 *
 * @license GPL-2.0-or-later
 * @file
 */

namespace MediaWiki\Extension\CategoryMultisortNeue;

use InvalidArgumentException;
use MediaWiki\Category\Category;
use MediaWiki\Category\CategoryViewer;
use MediaWiki\Collation\Collation;
use MediaWiki\Context\IContextSource;
use MediaWiki\Deferred\LinksUpdate\CategoryLinksTable;
use MediaWiki\Gallery\ImageGalleryBase;
use MediaWiki\Html\Html;
use MediaWiki\Language\ILanguageConverter;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\LinkCache;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Page\PageReference;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleValue;
use Wikimedia\HtmlArmor\HtmlArmor;
use Wikimedia\Rdbms\FakeResultWrapper;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\SelectQueryBuilder;

/**
 * Extends the core category viewer with the ability to read category members from
 * categorylinks_multisort under any configured collation.
 *
 * Everything that turns rows into HTML - the three sections, the column lists, the
 * language conversion, the count messages - is inherited unchanged.  Replaced are:
 * the member query, the grouping letters (which have to come from the selected
 * collation rather than the site one) and the paging links (which have to carry the
 * selected mode and this extension's own offsets).
 *
 * When the site default order is selected the query and the paging links follow the
 * core code paths exactly, so a category page viewed without a mode is the same as
 * it would be without this extension installed.
 *
 * Core declares doCategoryQuery(), getSectionPagingLinks(), pagingLinks() and
 * addFragmentToTitle() private, so they cannot be overridden; they are restated.
 */
class MultiSortCategoryViewer extends CategoryViewer {

	/** Query parameter prefix used for the extension's own offsets. */
	public const OFFSET_PREFIX = 'cms';

	private readonly SortModeRegistry $registry;

	private readonly SortMode $mode;

	/**
	 * Folds writing-system variants, shared with the mode that produced the keys.
	 */
	private readonly VariantRegistry $variants;

	/**
	 * The language variant this reader is served, i.e. the variant whose stored keys
	 * this page is ordered by.
	 */
	private readonly string $readerVariant;

	/**
	 * True when the selected mode wraps $wgCategoryCollation, in which case the core
	 * code paths are used unchanged.
	 */
	private readonly bool $useCoreOrder;

	/**
	 * The original query array, used to rebuild the paging links.
	 *
	 * Core's CategoryViewer::$query is private, so a subclass cannot read it; this is
	 * our own copy, normalised the same way core does it (minus the title, which is
	 * carried by the link target itself).
	 *
	 * @var array
	 */
	private array $ownQuery;

	/**
	 * Whether a section has more members beyond the ones rendered, in the direction
	 * currently being walked.
	 *
	 * Separate from the cursors below, because a cursor is now always present for the
	 * boundary rows: its presence cannot double as "there is another page". Core does
	 * not need this because it stores the *extra* row's key, which only exists when a
	 * further page does.
	 *
	 * @var array<string,bool>
	 */
	private array $hasMore = [ 'page' => false, 'subcat' => false, 'file' => false ];

	/**
	 * Cursors for the lowest and highest rows rendered, per section.
	 *
	 * The member query orders by (sort key, page id) and paging cursors are exclusive,
	 * so the two boundaries are all either direction needs: "after everything shown"
	 * and "before everything shown". Keeping both, rather than core's nextPage/prevPage
	 * pair, is what lets the two directions share one code path — core's pair assumes
	 * forward order, which silently swaps them when the page is rendered backwards.
	 *
	 * @var array<string,array{low:?string,high:?string}>
	 */
	private array $bounds = [
		'page' => [
			'lowSortKey' => null, 'lowPageId' => null,
			'highSortKey' => null, 'highPageId' => null,
		],
		'subcat' => [
			'lowSortKey' => null, 'lowPageId' => null,
			'highSortKey' => null, 'highPageId' => null,
		],
		'file' => [
			'lowSortKey' => null, 'lowPageId' => null,
			'highSortKey' => null, 'highPageId' => null,
		],
	];

	/**
	 * Whether each section had a cursor in the request, i.e. is not the first page.
	 *
	 * @var array<string,bool>
	 */
	private array $hasIncomingCursor = [ 'page' => false, 'subcat' => false, 'file' => false ];

	/**
	 * Language converter used for the grouping letters.
	 *
	 * Core's CategoryViewer::$languageConverter is private as well, so the same
	 * property-lookup problem applies.
	 */
	private ILanguageConverter $ownLanguageConverter;

	/**
	 * Category object for this page.
	 *
	 * Core's CategoryViewer::$cat is private, so the restated section builders cannot
	 * read it; Category::newFromTitle() is a cheap lookup that shares the same cache.
	 */
	private Category $ownCategory;

	/**
	 * @param PageIdentity $page
	 * @param IContextSource $context
	 * @param array $from Core offsets, keyed page/subcat/file
	 * @param array $until Core offsets, keyed page/subcat/file
	 * @param array $query Original query array for paging links
	 * @param string|null $modeId Sort mode for this request, null to resolve it here
	 */
	public function __construct(
		PageIdentity $page,
		IContextSource $context,
		array $from = [],
		array $until = [],
		array $query = [],
		?string $modeId = null
	) {
		parent::__construct( $page, $context, $from, $until, $query );

		$services = MediaWikiServices::getInstance();

		unset( $query['title'] );

		// Drop every paging cursor the request happened to carry. Each section builds
		// its own cursors, and a cursor that arrived for one section must not be copied
		// into another section's links: the subcategory list would then hand the page
		// list's *old* cursor back to it, and the same page of members would be served
		// twice. core never hits this because its cursors live in $from/$until rather
		// than in the query array.
		foreach ( array_keys( $query ) as $key ) {
			if ( preg_match(
				'/^' . preg_quote( self::OFFSET_PREFIX, '/' ) . '[a-z]+(?:from|until|offset)$/',
				$key
			) ) {
				unset( $query[$key] );
			}
		}

		$this->ownQuery = $query;
		$this->ownLanguageConverter = $services->getLanguageConverterFactory()->getLanguageConverter();
		$this->ownCategory = Category::newFromTitle( $page );

		/** @var SortModeRegistry $registry */
		$registry = $services->get( 'CategoryMultisortNeue.SortModeRegistry' );
		$this->registry = $registry;

		/** @var VariantRegistry $variants */
		$variants = $services->get( 'CategoryMultisortNeue.VariantRegistry' );
		$this->variants = $variants;
		$this->readerVariant = $variants->getReaderVariant();

		if ( $modeId === null ) {
			/** @var SortModeSelector $selector */
			$selector = $services->get( 'CategoryMultisortNeue.SortModeSelector' );
			$modeId = $selector->resolveModeId( $context->getRequest(), $context->getUser() );
		}

		$this->mode = $registry->resolveMode( $modeId );
		$this->useCoreOrder = $this->mode->isDefault;
	}

	/**
	 * The sort mode this page is rendered in.
	 */
	public function getMode(): SortMode {
		return $this->mode;
	}

	/**
	 * True when the requested mode was not usable and the site default is shown
	 * instead, which the reader deserves to be told about.
	 */
	private function fellBackFromUnusableMode(): bool {
		$configured = $this->registry->getMode( $this->mode->id );
		if ( $configured !== null ) {
			return !$configured->isAvailable();
		}
		// resolveMode() synthesised the site default because nothing configured was
		// usable; that is a configuration error, not a user error, so stay quiet.
		return false;
	}

	/** @inheritDoc */
	public function getHTML() {
		$notice = '';
		if ( $this->fellBackFromUnusableMode() ) {
			$notice = $this->msg( 'categorymultisortneue-unavailable-notice' )
				->params( $this->mode->id )
				->parseAsBlock();
		}
		return $this->buildInlineFallback() . $notice . parent::getHTML();
	}

	/**
	 * Optional in-body rendering of the same choices as the portlet.
	 *
	 * Only used when $wgCategoryMultisortNeueInlineFallback is on, for skins that do
	 * not render sidebar portlets (Minerva).  The items are built by SortModePortlet,
	 * so the two renderings cannot diverge; when JavaScript runs it drops this copy
	 * as soon as it finds the portlet, so nobody sees the control twice.
	 */
	private function buildInlineFallback(): string {
		/** @var CategoryMultisortNeueConfig $config */
		$config = MediaWikiServices::getInstance()->get( 'CategoryMultisortNeue.Config' );
		if ( !$config->inlineFallback() ) {
			return '';
		}

		$modes = $this->registry->getAvailableModes();
		if ( count( $modes ) < 2 ) {
			return '';
		}

		$title = MediaWikiServices::getInstance()->getTitleFactory()
			->newFromPageIdentity( $this->page );
		$items = SortModePortlet::buildItems(
			$title,
			$modes,
			$this->mode->id,
			$this->getContext()
		);

		$html = '';
		foreach ( $items as $item ) {
			$attribs = [
				'id' => $item['id'],
				'class' => SortModePortlet::ITEM_CLASS,
				'href' => $item['href'],
				'aria-label' => $item['aria-label'],
			];
			if ( isset( $item['title'] ) ) {
				$attribs['title'] = $item['title'];
			}
			$itemClass = 'mw-list-item';
			if ( !empty( $item['active'] ) ) {
				$attribs['aria-current'] = 'true';
				$itemClass .= ' active';
			}
			$html .= Html::rawElement(
				'li',
				[ 'class' => $itemClass ],
				Html::element( 'a', $attribs, $item['text'] )
			);
		}

		return Html::rawElement(
			'div',
			[
				'id' => 'cms-inline-fallback',
				'class' => 'mw-category-multisort-inline',
			],
			Html::element(
				'div',
				[ 'class' => 'mw-category-multisort-inline-heading' ],
				$this->msg( SortModePortlet::PORTLET_NAME )->text()
			) .
			Html::rawElement( 'ul', [ 'class' => 'mw-category-multisort-inline-list' ], $html )
		);
	}

	/**
	 * The collation the grouping letters of this page must use.
	 */
	private function activeCollation(): Collation {
		return $this->mode->getCollation() ?? $this->collation;
	}

	/**
	 * Build one member link.
	 *
	 * Core's CategoryViewer::generateLink() is private, so the add*() overrides below
	 * cannot call it.  This restates it faithfully, including the
	 * CategoryViewer::generateLink hook and the redirect marker.
	 *
	 * @param string $type 'page', 'subcat' or 'image'
	 * @param PageReference $page
	 * @param bool $isRedirect
	 * @param string|null $html Pre-escaped link text
	 * @return string
	 * @return-taint escaped
	 */
	private function buildMemberLink(
		string $type,
		PageReference $page,
		bool $isRedirect,
		?string $html = null
	): string {
		$link = null;
		$legacyTitle = MediaWikiServices::getInstance()->getTitleFactory()
			->newFromPageReference( $page );
		// @phan-suppress-next-line PhanTypeMismatchArgument Type mismatch on pass-by-ref args
		$this->getHookRunner()->onCategoryViewer__generateLink( $type, $legacyTitle, $html, $link );
		if ( $link === null ) {
			$linkRenderer = MediaWikiServices::getInstance()->getLinkRenderer();
			if ( $html !== null ) {
				$html = new HtmlArmor( $html );
			}
			$link = $linkRenderer->makeLink( $page, $html );
		}
		if ( $isRedirect ) {
			$link = Html::rawElement(
				'span',
				[ 'class' => 'redirect-in-category' ],
				$link
			);
		}

		return $link;
	}

	/**
	 * @inheritDoc
	 *
	 * @param string $sortKeyPrefix Raw cl_sortkey_prefix, needed for the grouping
	 *   letter.  Core passes the already-combined sort key here; the extension needs
	 *   the prefix because only it can be fed to the selected collation.
	 */
	public function addPageMember(
		PageReference $page,
		string $sortKeyPrefix,
		bool $isRedirect = false
	): void {
		$this->articles[] = $this->buildMemberLink( 'page', $page, $isRedirect );
		$this->articles_start_char[] = $this->getGroupingLetter( $page, $sortKeyPrefix );
	}

	/**
	 * @inheritDoc
	 *
	 * @param string $sortKeyPrefix Raw cl_sortkey_prefix, as for addPageMember().
	 */
	public function addImageMember(
		PageReference $page,
		string $sortKeyPrefix,
		bool $isRedirect = false
	): void {
		$title = MediaWikiServices::getInstance()->getTitleFactory()
			->newFromPageReference( $page );

		if ( $this->showGallery ) {
			if ( $this->flip['file'] ) {
				$this->gallery->insert( $title, '', '', '', [], ImageGalleryBase::LOADING_LAZY );
			} else {
				$this->gallery->add( $title, '', '', '', [], ImageGalleryBase::LOADING_LAZY );
			}
			return;
		}

		$this->imgsNoGallery[] = $this->buildMemberLink( 'image', $page, $isRedirect );
		$this->imgsNoGallery_start_char[] = $this->getGroupingLetter( $page, $sortKeyPrefix );
	}

	/**
	 * @inheritDoc
	 *
	 * @param string $sortKeyPrefix Raw cl_sortkey_prefix, as for addPageMember().
	 */
	public function addSubcategoryObjectMember( Category $cat, string $sortKeyPrefix ): void {
		$page = $cat->getPage();
		if ( !$page ) {
			return;
		}

		$pageRecord = MediaWikiServices::getInstance()->getPageStore()
			->getPageByReference( $page );
		if ( !$pageRecord ) {
			return;
		}

		$this->children[] = $this->buildMemberLink(
			'subcat',
			$pageRecord,
			$pageRecord->isRedirect(),
			htmlspecialchars( str_replace( '_', ' ', $pageRecord->getDBkey() ) )
		);
		$this->children_start_char[] = $this->getGroupingLetter( $pageRecord, $sortKeyPrefix );
	}

	/**
	 * Grouping letter under the selected collation, for the reader's variant.
	 *
	 * Derived from the same per-variant text as the member's sort key, so a page cannot
	 * be sorted under one heading and listed under another. The heading legitimately
	 * changes with the variant: 体 is ⠇ but 體 is ⠗ under stroke order.
	 *
	 * @param PageReference $page
	 * @param string $sortKeyPrefix The raw cl_sortkey_prefix, i.e. what the reader
	 *   wrote after the pipe in [[Category:Foo|...]] or in {{DEFAULTSORT:}}.
	 */
	private function getGroupingLetter( PageReference $page, string $sortKeyPrefix ): string {
		$title = MediaWikiServices::getInstance()->getTitleFactory()
			->newFromPageReference( $page );

		$letter = $this->mode->getFirstLetter( $title, $sortKeyPrefix, $this->readerVariant );

		return $this->ownLanguageConverter->convert( $letter );
	}

	/**
	 * Read one of the extension's paging cursors out of the request.
	 *
	 * @param \MediaWiki\Request\WebRequest $request
	 * @param string $suffix Parameter suffix, "pagefrom" or "pageuntil"
	 * @param IReadableDatabase $db
	 * @return array{sortkey:string,pageId:int,direction:string,inclusive:bool}|null
	 */
	private function readCursor( $request, string $suffix, $db ): ?array {
		$raw = $request->getVal( self::OFFSET_PREFIX . $suffix );
		if ( !is_string( $raw ) || $raw === '' ) {
			return null;
		}
		return OffsetCodec::decode( $raw );
	}

	/**
	 * The sort key expression a member is ordered and paged by.
	 *
	 * The reader's own variant wins; the mixed variant is the fallback when that variant
	 * has no row for the member. Every place that compares or orders on the effective
	 * key has to use this same expression, or the cursor and the ordering disagree.
	 *
	 * Written as literal SQL because it is a fixed two-column choice with no user input;
	 * the parameters are bound separately.
	 */
	private function effectiveSortKeyExpression(): string {
		return 'COALESCE(cms_reader.cms_sortkey, cms_mixed.cms_sortkey)';
	}

	/**
	 * @inheritDoc
	 *
	 * Restated from CategoryViewer with the additions described in the class
	 * comment: members may come from categorylinks_multisort, and the offsets may
	 * use the extension's own base64url encoded parameters.
	 */
	protected function doCategoryQuery() {
		$connProvider = MediaWikiServices::getInstance()->getConnectionProvider();
		$dbr = $connProvider->getReplicaDatabase();
		$categoryLinksDbr = $connProvider->getReplicaDatabase( CategoryLinksTable::VIRTUAL_DOMAIN );

		$this->nextPage = [ 'page' => null, 'subcat' => null, 'file' => null ];
		$this->prevPage = [ 'page' => null, 'subcat' => null, 'file' => null ];
		$this->flip = [ 'page' => false, 'subcat' => false, 'file' => false ];

		$useExtensionTable = !$this->useCoreOrder && $this->mode->isAvailable();
		$targetId = $useExtensionTable ? $this->lookupTargetId( $categoryLinksDbr ) : null;
		if ( $useExtensionTable && $targetId === null ) {
			return;
		}

		$request = $this->getRequest();

		foreach ( [ 'page', 'subcat', 'file' ] as $type ) {
			$extraConds = [ 'cl_type' => $type ];

			if ( $useExtensionTable ) {
				// The cursor in force. Either parameter may carry it, and only one is ever
				// followed, so trying until first and then from means the caller does not
				// have to say which kind of link was clicked. The check is on the raw
				// parameter rather than the decoded cursor, because the token can be
				// falsy as a string.
				$cursor = null;
				$cursorToken = '';
				if ( $request->getVal( self::OFFSET_PREFIX . "{$type}until" ) !== null ) {
					$cursorToken = (string)$request->getVal( self::OFFSET_PREFIX . "{$type}until" );
					$cursor = $this->readCursor( $request, "{$type}until", $categoryLinksDbr );
				}
				if ( $cursor === null ) {
					$cursorToken = (string)$request->getVal( self::OFFSET_PREFIX . "{$type}from" );
					$cursor = $this->readCursor( $request, "{$type}from", $categoryLinksDbr );
				}

				if ( $cursor !== null ) {
					// Filtered on the same expression the query orders by, so the
					// boundary row the cursor names is located the way it was found.
					// The tiebreaker is cl_from: the extension table is joined twice,
					// so its own cms_from is ambiguous here.
					$extraConds[] = OffsetCodec::cursorCondition(
						$categoryLinksDbr,
						$cursor,
						$this->effectiveSortKeyExpression(),
						'cl_from'
					);
					$this->hasIncomingCursor[$type] = true;
					// The cursor carries the ordering, so a page keeps coming back in the
					// direction the reader was already reading, whichever control got here.
					$this->flip[$type] = $cursor['direction'] === OffsetCodec::DIR_BACKWARD;
				}
			} else {
				// Core semantics, untouched.
				if ( isset( $this->from[$type] ) ) {
					$extraConds[] = $categoryLinksDbr->expr(
						'cl_sortkey',
						'>=',
						$this->collation->getSortKey( $this->from[$type] )
					);
				} elseif ( isset( $this->until[$type] ) ) {
					$extraConds[] = $categoryLinksDbr->expr(
						'cl_sortkey',
						'<',
						$this->collation->getSortKey( $this->until[$type] )
					);
					$this->flip[$type] = true;
				}
			}

			$fields = array_merge(
				LinkCache::getSelectFields(),
				[ 'cl_sortkey', 'cl_sortkey_prefix', 'collation_name' ]
			);

			$queryBuilder = $categoryLinksDbr->newSelectQueryBuilder();
			$queryBuilder->select( $fields )
				->from( 'page' )
				->andWhere( $extraConds )
				->join( 'categorylinks', null, [ 'cl_from = page_id' ] )
				->join( 'linktarget', null, 'cl_target_id = lt_id' )
				->straightJoin( 'collation', null, 'cl_collation_id = collation_id' )
				->where( [
					'lt_title' => $this->page->getDBkey(),
					'lt_namespace' => NS_CATEGORY,
				] );

			if ( $useExtensionTable ) {
				// A member's key is read for the reader's own variant, falling back to
				// the mixed variant when that variant has no row for it.
				//
				// The fallback is not a nicety: rows can legitimately be absent for a
				// variant. A page whose title converts to the same key under every
				// variant (any title that sorts by a leading Latin part, for instance)
				// has one distinct key, and a page edited while a variant was not
				// configured has none for it. An inner join on an exact variant match
				// would drop those members from the category entirely, which is
				// silent data loss rather than a different order.
				$readerAlias = 'cms_reader';
				$mixedAlias = 'cms_mixed';
				$queryBuilder
					->leftJoin(
						SortKeyStore::TABLE,
						$readerAlias,
						[
							"$readerAlias.cms_from = cl_from",
							"$readerAlias.cms_target_id = cl_target_id",
							"$readerAlias.cms_mode" => $this->mode->id,
							"$readerAlias.cms_variant" => $this->readerVariant,
						]
					)
					->leftJoin(
						SortKeyStore::TABLE,
						$mixedAlias,
						[
							"$mixedAlias.cms_from = cl_from",
							"$mixedAlias.cms_target_id = cl_target_id",
							"$mixedAlias.cms_mode" => $this->mode->id,
							"$mixedAlias.cms_variant" => $this->variants->getMixedVariant(),
						]
					)
					// A member with neither row has no key in this mode at all, so it is
					// not sortable here and must not appear. Written as literal SQL
					// because the aliases are fixed and carry no user input.
					->andWhere(
						"$readerAlias.cms_sortkey IS NOT NULL OR $mixedAlias.cms_sortkey IS NOT NULL"
					);

				$orderExpression = $this->effectiveSortKeyExpression();
				$queryBuilder->select( [ 'cms_sortkey' => $orderExpression ] );
				$orderField = $orderExpression;
			} else {
				$queryBuilder->useIndex( [ 'categorylinks' => 'cl_sortkey_id' ] );
				$orderField = 'cl_sortkey';
			}

			if ( $this->flip[$type] ) {
				$queryBuilder->orderBy( $orderField, SelectQueryBuilder::SORT_DESC );
			} else {
				$queryBuilder->orderBy( $orderField );
			}
			// Deterministic tiebreaker: two members can share a sort key, and paging
			// must neither skip nor repeat a row when they do.
			$queryBuilder->orderBy( 'cl_from' );
			$queryBuilder->limit( $this->limit + 1 );

			$res = $queryBuilder->caller( __METHOD__ )->fetchResultSet();

			$categoryTitles = [];
			$pageRows = [];
			foreach ( $res as $row ) {
				$pageRows[] = $row;
				if ( (int)$row->page_namespace === NS_CATEGORY ) {
					$categoryTitles[] = $row->page_title;
				}
			}

			$categoryFields = [ 'cat_id', 'cat_title', 'cat_subcats', 'cat_pages', 'cat_files' ];
			$categoryData = [];
			if ( $categoryTitles !== [] ) {
				$categoryRes = $dbr->newSelectQueryBuilder()
					->select( $categoryFields )
					->from( 'category' )
					->where( [ 'cat_title' => $categoryTitles ] )
					->caller( __METHOD__ )
					->fetchResultSet();
				foreach ( $categoryRes as $catRow ) {
					$categoryData[$catRow->cat_title] = $catRow;
				}
			}
			foreach ( $pageRows as $row ) {
				if ( (int)$row->page_namespace === NS_CATEGORY ) {
					$catRow = $categoryData[$row->page_title] ?? null;
					foreach ( $categoryFields as $field ) {
						$row->$field = $catRow->$field ?? null;
					}
				}
			}

			$res = new FakeResultWrapper( $pageRows );
			$this->getHookRunner()->onCategoryViewer__doCategoryQuery( $type, $res );
			$linkCache = MediaWikiServices::getInstance()->getLinkCache();

			$count = 0;
			foreach ( $pageRows as $row ) {
				$title = Title::newFromRow( $row );
				$linkCache->addGoodLinkObjFromRow( $title, $row );

				$humanSortkey = $title->getCategorySortkey( $row->cl_sortkey_prefix );

				if ( $useExtensionTable ) {
					// Extension mode. The extra row only proves another page exists; it is
					// neither shown nor used as a cursor, because the cursors built for the
					// links are exclusive and naming that row would skip it.
					if ( ++$count > $this->limit ) {
						$this->hasMore[$type] = true;
						break;
					}

					$bound = $this->bounds[$type];
					$bound['lowSortKey'] ??= (string)$row->cms_sortkey;
					$bound['lowPageId'] ??= (int)$row->page_id;
					$bound['highSortKey'] = (string)$row->cms_sortkey;
					$bound['highPageId'] = (int)$row->page_id;
					$this->bounds[$type] = $bound;
				} else {
					// Core mode, reproduced exactly: the extra row *is* the forward cursor,
					// and the last displayed row is the backward one.
					if ( ++$count > $this->limit ) {
						$this->nextPage[$type] = $humanSortkey;
						break;
					}
					if ( $count == $this->limit ) {
						$this->prevPage[$type] = $humanSortkey;
					}
				}

				$prefix = (string)$row->cl_sortkey_prefix;
				if ( $title->getNamespace() === NS_CATEGORY ) {
					$cat = Category::newFromRow( $row, $title );
					$this->addSubcategoryObjectMember( $cat, $prefix );
				} elseif ( $title->getNamespace() === NS_FILE ) {
					$this->addImageMember( $title, $prefix, $row->page_is_redirect );
				} else {
					$this->addPageMember( $title, $prefix, $row->page_is_redirect );
				}
			}
		}
	}

	/**
	 * Resolve the category's linktarget id so the member query can filter on the
	 * integer column and use the covering extension index.
	 */
	private function lookupTargetId( IReadableDatabase $dbr ): ?int {
		$id = $dbr->newSelectQueryBuilder()
			->select( 'lt_id' )
			->from( 'linktarget' )
			->where( [
				'lt_title' => $this->page->getDBkey(),
				'lt_namespace' => NS_CATEGORY,
			] )
			->caller( __METHOD__ )
			->fetchField();

		return $id === false || $id === null ? null : (int)$id;
	}

	/**
	 * Restated from CategoryViewer::getSubcategorySection().
	 *
	 * The three section builders have to be restated even though they are protected
	 * and overridable in principle: they call getSectionPagingLinks() and formatList(),
	 * which core declares private, and a private call inside a method body is bound to
	 * the class that declares it.  Overriding only the private helpers, as an earlier
	 * revision of this class did, therefore produces dead code: core's own copies keep
	 * running and the paging links keep the core parameter names.
	 */
	protected function getSubcategorySection() {
		$html = '';
		$localCount = count( $this->children );
		$databaseCount = $this->ownCategory->getSubcatCount();
		// Called even when the result is unused: it has side effects on the counters.
		$countMessage = $this->countMessage( $localCount, $databaseCount, 'subcat' );

		if ( $localCount > 0 ) {
			$html .= Html::openElement( 'div', [ 'id' => 'mw-subcategories' ] ) . "\n";
			$html .= Html::rawElement( 'h2', [], $this->msg( 'subcategories' )->parse() ) . "\n";
			$html .= $countMessage;
			$html .= $this->sectionPagingLinks( 'subcat' );
			$html .= $this->formatList( $this->children, $this->children_start_char );
			$html .= $this->sectionPagingLinks( 'subcat' );
			$html .= "\n" . Html::closeElement( 'div' );
		}
		return $html;
	}

	/**
	 * Restated from CategoryViewer::getPagesSection().
	 */
	protected function getPagesSection() {
		$name = $this->getOutput()->getUnprefixedDisplayTitle();
		$html = '';

		$databaseCount = $this->ownCategory->getPageCount( Category::COUNT_CONTENT_PAGES );
		$localCount = count( $this->articles );
		$countMessage = $this->countMessage( $localCount, $databaseCount, 'page' );

		if ( $localCount > 0 ) {
			$html .= Html::openElement( 'div', [ 'id' => 'mw-pages' ] ) . "\n";
			$html .= Html::rawElement(
				'h2',
				[],
				$this->msg( 'category_header' )->rawParams( $name )->parse()
			) . "\n";
			$html .= $countMessage;
			$html .= $this->sectionPagingLinks( 'page' );
			$html .= $this->formatList( $this->articles, $this->articles_start_char );
			$html .= $this->sectionPagingLinks( 'page' );
			$html .= "\n" . Html::closeElement( 'div' );
		}
		return $html;
	}

	/**
	 * Restated from CategoryViewer::getImageSection().
	 */
	protected function getImageSection() {
		$name = $this->getOutput()->getUnprefixedDisplayTitle();
		$html = '';
		$localCount = $this->showGallery ?
			$this->gallery->count() :
			count( $this->imgsNoGallery );
		$databaseCount = $this->ownCategory->getFileCount();
		$countMessage = $this->countMessage( $localCount, $databaseCount, 'file' );

		if ( $localCount > 0 ) {
			$html .= Html::openElement( 'div', [ 'id' => 'mw-category-media' ] ) . "\n";
			$html .= Html::rawElement(
				'h2',
				[],
				$this->msg( 'category-media-header' )->rawParams( $name )->parse()
			) . "\n";
			$html .= $countMessage;
			$html .= $this->sectionPagingLinks( 'file' );
			if ( $this->showGallery ) {
				$html .= $this->gallery->toHTML();
			} else {
				$html .= $this->formatList( $this->imgsNoGallery, $this->imgsNoGallery_start_char );
			}
			$html .= $this->sectionPagingLinks( 'file' );
			$html .= "\n" . Html::closeElement( 'div' );
		}
		return $html;
	}

	/**
	 * Restated from CategoryViewer::formatList().
	 *
	 * @param string[] $articles
	 * @param string[] $articlesStartChar
	 * @param int $cutoff
	 */
	private function formatList( array $articles, array $articlesStartChar, int $cutoff = 6 ): string {
		$list = '';
		if ( count( $articles ) > $cutoff ) {
			$list = self::columnList( $articles, $articlesStartChar );
		} elseif ( count( $articles ) > 0 ) {
			// For short lists of articles in categories.
			$list = self::shortList( $articles, $articlesStartChar );
		}

		$pageLang = MediaWikiServices::getInstance()->getTitleFactory()
			->newFromPageIdentity( $this->page )
			->getPageLanguage();
		$attribs = [ 'lang' => $pageLang->getHtmlCode(), 'dir' => $pageLang->getDir(),
			'class' => 'mw-content-' . $pageLang->getDir() ];
		$list = Html::rawElement( 'div', $attribs, $list );

		return $list;
	}

	/**
	 * Restated from CategoryViewer::getCountMessage().
	 *
	 * @param int $localCount
	 * @param int $databaseCount
	 * @param string $type 'page', 'subcat' or 'file'
	 */
	private function countMessage( int $localCount, int $databaseCount, string $type ): string {
		$msgType = $type === 'page' ? 'article' : $type;

		$fromOrUntil = $this->useCoreOrder
			? ( isset( $this->from[$type] ) || isset( $this->until[$type] ) )
			// In extension mode the offset lives in the request, not in from[]/until[].
			: $this->flip[$type]
				|| $this->getRequest()->getVal( self::OFFSET_PREFIX . "{$type}offset" ) !== null
				|| $this->getRequest()->getVal( self::OFFSET_PREFIX . "{$type}from" ) !== null
				|| $this->getRequest()->getVal( self::OFFSET_PREFIX . "{$type}until" ) !== null;

		if ( $databaseCount == $localCount ||
			( ( $localCount == $this->limit || $fromOrUntil ) && $databaseCount > $localCount )
		) {
			$totalCount = $databaseCount;
		} elseif ( $localCount < $this->limit && !$fromOrUntil ) {
			$totalCount = $localCount;
		} else {
			return $this->msg( "category-$msgType-count-limited" )->numParams( $localCount )->parseAsBlock();
		}

		return $this->msg( "category-$msgType-count" )->numParams( $localCount, $totalCount )->parseAsBlock();
	}

	/**
	 * Restated from CategoryViewer::getSectionPagingLinks().
	 *
	 * The links are built from the two boundaries of the rows actually shown: "next"
	 * continues past the high end, "previous" goes back before the low end. That pair is
	 * enough for either walk direction, which core's nextPage/prevPage pair is not: those
	 * two assume the rows were rendered in ascending order, so a page rendered
	 * descending silently swaps them.
	 *
	 * Each boundary is only turned into a link when there is something beyond it:
	 * "previous" exists once a cursor has been followed at all, and "next" exists while
	 * the extra row keeps arriving.
	 */
	private function sectionPagingLinks( string $type ): string {
		$useExtension = !$this->useCoreOrder && $this->mode->isAvailable();

		if ( !$useExtension ) {
			// Core semantics, untouched.
			$forwardStart = (string)( $this->from[$type] ?? '' );
			if ( $this->nextPage[$type] !== null || $forwardStart !== '' ) {
				return $this->buildPagingLinks(
					$forwardStart,
					(string)( $this->nextPage[$type] ?? '' ),
					$type,
					false
				);
			}
			return '';
		}

		$bound = $this->bounds[$type];
		$bound = $this->bounds[$type];
		$backward = $this->flip[$type];

		// Both controls are "strictly beyond a boundary row", and the boundary is the row
		// the current page stops at in each direction:
		//
		//   next      the high row, exclusive, in the walk's own ordering
		//   previous  the low row, exclusive, ordered backwards
		//
		// Measured against the real rows in tools/probe-cursor-matrix.php: from page 2 a
		// backwards cursor on page 2's low row reproduces page 1, which is the inverse of
		// the cursor that produced page 2.
		$low = $bound['lowSortKey'];
		$lowId = $bound['lowPageId'];
		$high = $bound['highSortKey'];
		$highId = $bound['highPageId'];

		$nextCursor = '';
		if ( $this->hasMore[$type] ) {
			$nextCursor = $backward
				? ( $low === null ? '' : OffsetCodec::encode(
					$low, $lowId, OffsetCodec::DIR_BACKWARD, '<', '<'
				) )
				: ( $high === null ? '' : OffsetCodec::encode(
					$high, $highId, OffsetCodec::DIR_FORWARD, '>', '>'
				) );
		}

		$prevCursor = '';
		if ( $this->hasIncomingCursor[$type] && $low !== null ) {
			$prevCursor = OffsetCodec::encode(
				$low, $lowId, OffsetCodec::DIR_BACKWARD, '<', '<'
			);
		}

		// A descending page shows its rows in the opposite reading order, so the two
		// controls swap places while each keeps the meaning its cursor carries.
		$first = $backward ? $nextCursor : $prevCursor;
		$last = $backward ? $prevCursor : $nextCursor;

		if ( $first === '' && $last === '' ) {
			return '';
		}

		return $this->buildPagingLinks( $first, $last, $type, true );
	}

	/**
	 * Restated from CategoryViewer::pagingLinks().  Offset values are passed
	 * verbatim; the mode parameter is added so a page of results cannot be linked
	 * under a different order.
	 *
	 * The extension keeps core's two parameters, one per direction of travel, because
	 * the comparison a cursor needs does not follow from the row it names: a link that
	 * reaches back over a boundary row has to include it, and one that carries on has to
	 * exclude it. Two parameters let each link carry the comparison it needs without the
	 * reader having to infer it. The unused one is cleared so a section can never hold
	 * two conflicting cursors.
	 *
	 * @param string $first Cursor for the "previous page" control, or ''
	 * @param string $last Cursor for the "next page" control, or ''
	 * @param string $type
	 * @param bool $useExtension
	 */
	/**
	 * Restated from CategoryViewer::pagingLinks(). Offset values are passed verbatim; the
	 * mode parameter is added so a page of results cannot be linked under a different
	 * order.
	 *
	 * The extension keeps core's two offset parameters, one per direction of travel, and
	 * adds a third that is not an offset at all: the cursor that leads back to where the
	 * reader came from. A "next" link necessarily knows that cursor — it *is* the cursor
	 * the current page was reached with — so it passes it on, and the "previous" link then
	 * has an exact token to hand back instead of one reconstructed from boundary rows.
	 * Inverting a cursor is not its own inverse, so the token that leads back is a
	 * different token from the one that led here and cannot be recomputed from the page.
	 *
	 * @param string $first Cursor for the "previous page" control, or ''
	 * @param string $last Cursor for the "next page" control, or ''
	 * @param string $type
	 * @param bool $useExtension
	 */
	private function buildPagingLinks(
		string $first,
		string $last,
		string $type = '',
		bool $useExtension = false
	): string {
		$prevLink = $this->msg( 'prev-page' )->escaped();
		$linkRenderer = MediaWikiServices::getInstance()->getLinkRenderer();

		$fromParam = $useExtension ? self::OFFSET_PREFIX . "{$type}from" : "{$type}from";
		$untilParam = $useExtension ? self::OFFSET_PREFIX . "{$type}until" : "{$type}until";
		$otherFrom = $useExtension ? "{$type}from" : self::OFFSET_PREFIX . "{$type}from";
		$otherUntil = $useExtension ? "{$type}until" : self::OFFSET_PREFIX . "{$type}until";
		$stale = $useExtension ? [ self::OFFSET_PREFIX . "{$type}offset" ] : [];

		if ( $first != '' ) {
			$prevQuery = $this->ownQuery;
			$prevQuery[$untilParam] = $first;
			unset( $prevQuery[$fromParam] );
			foreach ( $stale as $key ) {
				unset( $prevQuery[$key] );
			}
			unset( $prevQuery[$otherFrom], $prevQuery[$otherUntil] );
			$prevQuery[SortModeSelector::URL_PARAM] = $this->mode->id;
			$prevLink = $linkRenderer->makeKnownLink(
				$this->addFragmentToTitle( $this->page, $type ),
				new HtmlArmor( $prevLink ),
				[],
				$prevQuery
			);
		}

		$nextLink = $this->msg( 'next-page' )->escaped();
		if ( $last != '' ) {
			$lastQuery = $this->ownQuery;
			$lastQuery[$fromParam] = $last;
			unset( $lastQuery[$untilParam] );
			foreach ( $stale as $key ) {
				unset( $lastQuery[$key] );
			}
			unset( $lastQuery[$otherFrom], $lastQuery[$otherUntil] );
			$lastQuery[SortModeSelector::URL_PARAM] = $this->mode->id;
			$nextLink = $linkRenderer->makeKnownLink(
				$this->addFragmentToTitle( $this->page, $type ),
				new HtmlArmor( $nextLink ),
				[],
				$lastQuery
			);
		}

		return $this->msg( 'categoryviewer-pagedlinks' )->rawParams( $prevLink, $nextLink )->escaped();
	}

	/**
	 * Restated from CategoryViewer::addFragmentToTitle().
	 */
	private function addFragmentToTitle( PageReference $page, string $section ): LinkTarget {		$fragment = match ( $section ) {
			'page' => 'mw-pages',
			'subcat' => 'mw-subcategories',
			'file' => 'mw-category-media',
			default => throw new InvalidArgumentException( __METHOD__ . " Invalid section $section." )
		};
		return new TitleValue( $page->getNamespace(), $page->getDBkey(), $fragment );
	}
}
