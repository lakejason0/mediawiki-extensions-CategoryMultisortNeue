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
use MediaWiki\Output\OutputPage;
use MediaWiki\Skin\Skin;
use MediaWiki\User\User;

/**
 * 用户偏好与前端模块加载的钩子处理器（BLUEPRINT §5：GetPreferences / BeforePageDisplay）。
 *
 * 这里刻意用静态 hook handler（extension.json 已冻结为 `Class::method` 形式），
 * 需要服务时从服务容器取。
 */
final class PreferenceHooks {

	/**
	 * 偏好放在「外观 → 高级选项」里，和 showhiddencats 等分类相关开关同处一节。
	 */
	private const PREFERENCE_SECTION = 'rendering/advancedrendering';

	/**
	 * GetPreferences：注册 categorymultisortneue-sortmode 偏好。
	 *
	 * - 只在 $wgCategoryMultisortNeuePreference 为真时提供；
	 * - 只对已登录（含临时账号）用户显示；
	 * - 选项 = 各可用 mode 的本地化名 + 「跟随站点默认」（值 ''，即不写偏好）。
	 *
	 * @param User $user
	 * @param array &$preferences
	 */
	public static function onGetPreferences( User $user, array &$preferences ) {
		if ( !self::getConfig()->offerPreference() ) {
			return;
		}
		if ( !$user->isRegistered() ) {
			return;
		}

		$modes = self::getSelector()->getSelectableModes();
		if ( $modes === [] ) {
			// 一个可用 mode 都没有时，注册一个只有「跟随站点默认」的下拉毫无意义
			return;
		}

		// 'options' 是「标签 => 值」的数组，标签重复会互相覆盖。
		// SortMode::getLabel() 返回未转义的纯文本（消息被禁用时回落为裸 mode id），
		// HTMLForm 渲染时会统一转义一次，这里只负责纯文本。
		$options = [];
		$followLabel = wfMessage( 'categorymultisortneue-pref-default' )->text();
		$options[$followLabel] = '';

		foreach ( $modes as $mode ) {
			$label = $mode->getLabel();
			// 配置里包着站点 collation 的那个 mode 往往就叫「站点默认」，与上一项同名。
			// 撞名时补上 mode id，保证每个 mode 都还能被选到——否则它会被静默丢掉，
			// 而 'default' => '' 一旦不是合法选项，Special:Preferences 会直接抛
			// UnexpectedValueException（HTMLSelectField::validate() 拒绝默认值）。
			$suffix = 1;
			$unique = $label;
			while ( isset( $options[$unique] ) ) {
				$unique = $suffix === 1
					? $label . ' (' . $mode->id . ')'
					: $label . ' (' . $mode->id . ' #' . $suffix . ')';
				$suffix++;
			}
			$options[$unique] = $mode->id;
		}

		$preferences[SortModeSelector::PREFERENCE] = [
			'type' => 'select',
			'label-message' => 'categorymultisortneue-pref',
			'help-message' => 'categorymultisortneue-pref-help',
			'section' => self::PREFERENCE_SECTION,
			'options' => $options,
			// 必须是一个合法选项值：HTMLSelectField::validate() 会对默认值做
			// in_array(strval($value), $options, true)；'' 即「跟随站点默认」。
			'default' => '',
		];
	}

	/**
	 * BeforePageDisplay：只在分类命名空间加载前端模块。
	 *
	 * 模块本身是纯渐进增强的：即使因为 action=history 之类的非阅读视图被加载，
	 * JS 找不到 p-categorymultisortneue 这个 portlet 也会立刻返回。
	 *
	 * @param OutputPage $out
	 * @param Skin $skin
	 */
	public static function onBeforePageDisplay( OutputPage $out, Skin $skin ): void {
		if ( !self::getConfig()->showSelector() ) {
			return;
		}

		$title = $out->getTitle();
		if ( $title === null || $title->getNamespace() !== NS_CATEGORY ) {
			return;
		}

		$out->addModules( 'ext.categoryMultisortNeue' );
	}

	/**
	 * @return SortModeSelector
	 */
	private static function getSelector(): SortModeSelector {
		return MediaWikiServices::getInstance()
			->getService( 'CategoryMultisortNeue.SortModeSelector' );
	}

	/**
	 * @return CategoryMultisortNeueConfig
	 */
	private static function getConfig(): CategoryMultisortNeueConfig {
		return MediaWikiServices::getInstance()
			->getService( 'CategoryMultisortNeue.Config' );
	}
}
