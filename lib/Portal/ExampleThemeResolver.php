<?php

/**
 * Learniq example theme resolver
 *
 * A designed portal wants its own thematiq token set (`wilgenboom`,
 * `vaartveld`, ...). Those sets ship in a thematiq release that an instance
 * may not have yet. Writing an id thematiq does not know leaves the portal
 * unthemed, so the resolver checks the installed thematiq and falls back to
 * the older example set when the new one is missing.
 *
 * @category Portal
 * @package  OCA\Learniq\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/example-portal-declares-its-site/specs/example-sets/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

use OCP\App\IAppManager;
use Throwable;

/**
 * Picks the theme id a portal gets: the preferred set when thematiq has it, else the fallback.
 *
 * @spec openspec/changes/example-portal-declares-its-site/specs/example-sets/spec.md
 */
class ExampleThemeResolver {

	/**
	 * Thematiq's app id, checked by name only.
	 */
	public const THEMATIQ_APP_ID = 'thematiq';

	/**
	 * Constructor.
	 *
	 * @param IAppManager $appManager Tells whether thematiq is installed and where.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppManager $appManager,
	) {
	}//end __construct()

	/**
	 * The theme to write, and whether it is the fallback.
	 *
	 * The preferred id counts as available only when thematiq is installed,
	 * names the id in its `token-sets.json` and ships `css/tokens/<id>.css`.
	 * Anything else, including an unreadable registry, gives the fallback, so
	 * a load never fails over a theme.
	 *
	 * @param string $preferred The designed token set.
	 * @param string $fallback  The set to use when the preferred one is missing.
	 *
	 * @return array{theme: string, fallback: bool}
	 *
	 * @spec openspec/changes/example-portal-declares-its-site/specs/example-sets/spec.md#requirement-a-portal-gets-its-designed-theme-and-falls-back-when-thematiq-lacks-it
	 */
	public function resolve(string $preferred, string $fallback): array {
		if ($preferred !== '' && $this->isAvailable(setId: $preferred) === true) {
			return ['theme' => $preferred, 'fallback' => false];
		}

		return ['theme' => $fallback, 'fallback' => true];
	}//end resolve()

	/**
	 * Whether the installed thematiq ships this token set.
	 *
	 * @param string $setId The token set id.
	 *
	 * @return bool
	 */
	private function isAvailable(string $setId): bool {
		if (preg_match('/^[a-z0-9-]+$/', $setId) !== 1) {
			return false;
		}

		try {
			if ($this->appManager->isInstalled(self::THEMATIQ_APP_ID) === false) {
				return false;
			}

			$path = $this->appManager->getAppPath(self::THEMATIQ_APP_ID);
		} catch (Throwable) {
			return false;
		}

		$registry = $path . '/token-sets.json';
		if (is_file($registry) === false || is_readable($registry) === false) {
			return false;
		}

		$sets = json_decode((string)file_get_contents($registry), true);
		if (is_array($sets) === false) {
			return false;
		}

		$named = in_array($setId, array_map(static fn ($set): string => (string)($set['id'] ?? ''), array_filter($sets, 'is_array')), true);

		return ($named === true && is_file($path . '/css/tokens/' . $setId . '.css') === true);
	}//end isAvailable()
}//end class
