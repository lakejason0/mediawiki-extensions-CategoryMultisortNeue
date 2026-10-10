<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup Extensions
 */

namespace MediaWiki\Extension\CategoryMultisortNeue;

use MediaWiki\MediaWikiServices;
use MediaWiki\Request\WebRequest;
use MediaWiki\User\Options\UserOptionsLookup;
use MediaWiki\User\User;

/**
 * 决定「本次请求实际使用哪个排序方式」，并给出分类页选择器的候选列表。
 *
 * 实现 BLUEPRINT §4.3 的冻结接口。本类不缓存任何东西：注册表本身已经是
 * 按进程构造一次的，请求级解析每次都是纯函数式查询。
 *
 * 服务名：CategoryMultisortNeue.SortModeSelector
 */
final class SortModeSelector {

	/** 请求参数名：?cmsmode=<mode id> */
	public const URL_PARAM = 'cmsmode';

	/** 分页参数前缀：cms{page|subcat|file}{from|until} */
	public const PAGING_PREFIX = 'cms';

	/** 用户偏好名 */
	public const PREFERENCE = 'categorymultisortneue-sortmode';

	/** 分页参数的合法「类型」片段（BLUEPRINT §2.1） */
	public const PAGING_TYPES = [ 'page', 'subcat', 'file' ];

	/** 分页参数的合法「方向」片段（BLUEPRINT §2.1） */
	public const PAGING_DIRECTIONS = [ 'from', 'until' ];

	/** mode id 的最大长度，与 BLUEPRINT §3 的 /^[A-Za-z0-9_-]{1,32}$/ 一致 */
	private const MAX_MODE_ID_LENGTH = 32;

	private SortModeRegistry $registry;

	private CategoryMultisortNeueConfig $config;

	private UserOptionsLookup $userOptionsLookup;

	/**
	 * @param SortModeRegistry $registry
	 * @param CategoryMultisortNeueConfig $config
	 * @param UserOptionsLookup|null $userOptionsLookup 省略时从服务容器取，
	 *   方便测试与维护脚本只传前两个参数
	 */
	public function __construct(
		SortModeRegistry $registry,
		CategoryMultisortNeueConfig $config,
		?UserOptionsLookup $userOptionsLookup = null
	) {
		$this->registry = $registry;
		$this->config = $config;
		$this->userOptionsLookup = $userOptionsLookup
			?? MediaWikiServices::getInstance()->getUserOptionsLookup();
	}

	/**
	 * 解析本次请求生效的 mode id，优先级（BLUEPRINT §4.3）：
	 *   1. 请求参数 cmsmode（合法、存在且可用时）
	 *   2. 已登录用户的偏好（合法、存在且可用时）
	 *   3. $wgCategoryMultisortNeueDefaultMode / 注册表默认
	 *
	 * 「存在但预检失败」的 mode 会被跳过：宁可回落到站点默认，也不能让分类页 500。
	 * 返回的 id 一定满足 registry->modeExists()，且对应 mode 可用。
	 *
	 * @param WebRequest $request
	 * @param User|null $user
	 * @return string
	 */
	public function resolveModeId( WebRequest $request, ?User $user ): string {
		$requested = $this->normalizeModeId( $request->getVal( self::URL_PARAM ) );
		if ( $requested !== null && $this->isUsable( $requested ) ) {
			return $requested;
		}

		$preferred = $this->normalizeModeId(
			$user !== null && $user->isRegistered()
				? $this->userOptionsLookup->getOption( $user, self::PREFERENCE, '' )
				: null
		);
		if ( $preferred !== null && $this->isUsable( $preferred ) ) {
			return $preferred;
		}

		return $this->registry->getDefaultModeId();
	}

	/**
	 * 给分类页选择器用的候选列表（已过滤不可用 mode）。
	 *
	 * @return SortMode[] 以 mode id 为键，顺序与配置顺序一致
	 */
	public function getSelectableModes(): array {
		return array_filter(
			$this->registry->getModes(),
			static fn ( SortMode $mode ): bool => $mode->isAvailable()
		);
	}

	/**
	 * 请求参数名 → 分页查询参数名。
	 *
	 * 合法取值：$type ∈ {page, subcat, file}，$direction ∈ {from, until}，
	 * 例如 pagingParam( 'page', 'from' ) === 'cmspagefrom'。
	 *
	 * @param string $type
	 * @param string $direction
	 * @return string
	 */
	public static function pagingParam( string $type, string $direction ): string {
		return self::PAGING_PREFIX . $type . $direction;
	}

	/**
	 * 全部 6 个分页参数名（供 JS 与维护脚本使用；不在冻结接口内，纯便利方法）。
	 *
	 * @return string[]
	 */
	public static function getAllPagingParams(): array {
		$params = [];
		foreach ( self::PAGING_TYPES as $type ) {
			foreach ( self::PAGING_DIRECTIONS as $direction ) {
				$params[] = self::pagingParam( $type, $direction );
			}
		}
		return $params;
	}

	/**
	 * 本次生效的排序方式是否「不由 URL 决定」。
	 *
	 * URL 参数是请求键的一部分，因此只要 cmsmode 确实决定了结果，带该参数的
	 * 响应就可以正常缓存；一旦 cmsmode 缺失、非法、不存在或不可用，解析就会
	 * 回落到读者个人偏好，同一个 URL 对不同用户会有不同输出，调用方必须据此
	 * 禁用客户端缓存（MultiSortCategoryPage::closeShowCategory 就这么做）。
	 *
	 * 注意这里的判据是「URL 有没有真正决定结果」，而不是「URL 里有没有参数」：
	 * ?cmsmode=typo 同样会回落到偏好，不能当作可缓存。
	 *
	 * @param WebRequest $request
	 * @param User|null $user 传入用户才能精确判断：匿名读者没有偏好，输出对所有
	 *   人一致，不该白白禁用客户端缓存。省略时按「可能因人而异」保守处理，
	 *   让只传一个参数的调用方保持原语义。
	 * @return bool
	 */
	public function isUserSpecific( WebRequest $request, ?User $user = null ): bool {
		if ( !$this->config->offerPreference() ) {
			// 偏好被关掉时解析结果只由请求决定，与用户无关
			return false;
		}

		$requested = $this->normalizeModeId( $request->getVal( self::URL_PARAM ) );
		if ( $requested !== null && $this->isUsable( $requested ) ) {
			// URL 真正决定了结果，所有读者看到的都一样
			return false;
		}

		if ( $user === null ) {
			// 调用方没给用户：可能落到某个人的偏好上，只能保守处理
			return true;
		}
		if ( !$user->isRegistered() ) {
			// 匿名读者没有偏好可用，回落到站点默认
			return false;
		}

		$preferred = $this->normalizeModeId(
			$this->userOptionsLookup->getOption( $user, self::PREFERENCE, '' )
		);
		// 只有偏好确实生效（存在且可用）时，输出才因人而异
		return $preferred !== null && $this->isUsable( $preferred );
	}

	/**
	 * 把外部输入归一化成候选 mode id；不合法时返回 null。
	 *
	 * @param mixed $value
	 * @return string|null
	 */
	private function normalizeModeId( $value ): ?string {
		// 数组（?cmsmode[]=x）、对象、null 一律视为未提供
		if ( !is_scalar( $value ) || is_bool( $value ) ) {
			return null;
		}
		$value = trim( (string)$value );
		if ( $value === '' || strlen( $value ) > self::MAX_MODE_ID_LENGTH ) {
			return null;
		}
		return $value;
	}

	/**
	 * mode 是否存在且预检通过。
	 *
	 * @param string $id
	 * @return bool
	 */
	private function isUsable( string $id ): bool {
		$mode = $this->registry->getMode( $id );
		return $mode !== null && $mode->isAvailable();
	}
}
