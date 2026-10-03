<?php

/**
 * Learniq Apply Store Rights Defaults
 *
 * Brings the D27 store rights to an instance that installed learniq before
 * them. The ADR-023 matrix is seeded only when it is empty
 * (GenericInitializeActions), so on an existing instance a new action row is
 * simply absent, which getAllowedGroups() reads as admin-only, and a changed
 * default never arrives.
 *
 * Once per instance, this step:
 *   - adds `course-store.install` with its seed groups when the matrix has no
 *     such row (any teacher installs a shared course as a copy);
 *   - replaces `course-package.share` with its seed groups only when the row
 *     still holds the untouched old default `["admin"]` (team leads publish).
 *
 * A marker records that it ran, so an administrator who later narrows either
 * row under Action authorization keeps that choice on every upgrade after.
 * The new values are read from lib/actions.seed.json, the one place they are
 * written.
 *
 * @category Repair
 * @package  OCA\Learniq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/course-management/spec.md#requirement-existing-installs-get-the-new-store-defaults-once
 */

declare(strict_types=1);

namespace OCA\Learniq\Repair;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Service\ActionAuthService;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Throwable;

/**
 * Applies the store rights defaults to an existing matrix, once.
 */
class ApplyStoreRightsDefaults implements IRepairStep {

	/**
	 * App config key recording that this step ran.
	 */
	public const MARKER = 'store_rights_defaults_applied';

	public const ACTION_INSTALL = 'course-store.install';

	public const ACTION_SHARE = 'course-package.share';

	/**
	 * The share row as every instance had it before D27.
	 */
	private const OLD_SHARE_DEFAULT = ['admin'];

	/**
	 * Constructor.
	 *
	 * @param ActionAuthService $actionAuth Learniq's ADR-023 matrix.
	 * @param IAppConfig        $appConfig  Learniq's app config, for the marker.
	 * @param string            $seedPath   The seed file; tests point it elsewhere.
	 */
	public function __construct(
		private readonly ActionAuthService $actionAuth,
		private readonly IAppConfig $appConfig,
		private readonly string $seedPath=__DIR__ . '/../actions.seed.json',
	) {

	}//end __construct()

	/**
	 * Human-readable step name.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-existing-installs-get-the-new-store-defaults-once
	 */
	public function getName(): string {
		return 'Let teachers install shared courses and team leads publish them (store rights, once)';

	}//end getName()

	/**
	 * Apply the defaults once.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-existing-installs-get-the-new-store-defaults-once
	 */
	public function run(IOutput $output): void {
		if ($this->appConfig->getValueString(Application::APP_ID, self::MARKER, '') !== '') {
			return;
		}

		$matrix = $this->actionAuth->getMatrix();
		if ($matrix === []) {
			// The seed has not run; InitializeActions owns an empty matrix.
			$output->info('Learniq store rights: the action matrix is empty, left to the seed.');
			return;
		}

		$seed = $this->seedRows();
		if ($seed === null) {
			$output->warning('Learniq store rights: lib/actions.seed.json is missing or unreadable, nothing changed.');
			return;
		}

		$changed = $this->applyDefaults(matrix: $matrix, seed: $seed);
		if ($changed !== $matrix) {
			try {
				$this->actionAuth->setMatrix($changed);
			} catch (Throwable $e) {
				$output->warning('Learniq store rights: the action matrix could not be written (' . $e->getMessage() . ').');
				return;
			}

			$output->info('Learniq store rights: teachers may install shared courses, team leads may publish.');
		}

		$this->appConfig->setValueString(Application::APP_ID, self::MARKER, '1');

	}//end run()

	/**
	 * The matrix with the store defaults applied: the install row added when
	 * absent, the share row replaced only when it is the old default.
	 *
	 * @param array<string, array<int, string>> $matrix The current matrix.
	 * @param array<string, array<int, string>> $seed   The two seed rows.
	 *
	 * @return array<string, array<int, string>>
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-existing-installs-get-the-new-store-defaults-once
	 */
	public function applyDefaults(array $matrix, array $seed): array {
		if (array_key_exists(self::ACTION_INSTALL, $matrix) === false) {
			$matrix[self::ACTION_INSTALL] = $seed[self::ACTION_INSTALL];
		}

		if (($matrix[self::ACTION_SHARE] ?? null) === self::OLD_SHARE_DEFAULT) {
			$matrix[self::ACTION_SHARE] = $seed[self::ACTION_SHARE];
		}

		return $matrix;

	}//end applyDefaults()

	/**
	 * The two store rows from the seed file, or null when either is missing.
	 *
	 * @return array<string, array<int, string>>|null
	 */
	private function seedRows(): ?array {
		if (is_readable($this->seedPath) === false) {
			return null;
		}

		$seed = json_decode((string)file_get_contents($this->seedPath), true);
		$rows = [];
		foreach ([self::ACTION_INSTALL, self::ACTION_SHARE] as $action) {
			$groups = ($seed['actions'][$action] ?? null);
			if (is_array($groups) === false || $groups === []) {
				return null;
			}

			$rows[$action] = array_values(array_filter($groups, 'is_string'));
		}

		return $rows;

	}//end seedRows()
}//end class
