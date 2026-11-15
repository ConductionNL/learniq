<?php

/**
 * Learniq Rename Stale Action Groups
 *
 * The action matrix seed named two groups that do not exist:
 * `compliance-officer` (the group is `compliance-officers`) and `manager`
 * (renamed to `administration-managers`). A compliance officer was refused the
 * department roll-up, bulk recording of external training, issuing a
 * credential and assigning a regulation (live pass 2 Oct, D1). The seed is
 * fixed, but InitializeActions keeps an existing matrix as it is, so this step
 * renames the two names in a stored matrix once. A name is left alone when a
 * group by that name exists, because then an admin chose it.
 *
 * @category Repair
 * @package  OCA\Learniq\Repair
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/parity/capabilities.json#comp-roll-up-by-department
 */

declare(strict_types=1);

namespace OCA\Learniq\Repair;

use OCA\Learniq\Service\ActionAuthService;
use OCP\IGroupManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Throwable;

/**
 * Rename the two group names the seed got wrong in a stored action matrix.
 *
 * @spec openspec/parity/capabilities.json#comp-roll-up-by-department
 */
class RenameStaleActionGroups implements IRepairStep {

	/**
	 * The wrong name and the group it meant.
	 */
	public const RENAMES = [
		'compliance-officer' => 'compliance-officers',
		'manager'            => 'administration-managers',
	];

	/**
	 * Constructor.
	 *
	 * @param ActionAuthService $actionAuth The app's action matrix.
	 * @param IGroupManager $groupManager To leave a name alone that is a real group.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ActionAuthService $actionAuth,
		private readonly IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string
	 *
	 * @spec openspec/parity/capabilities.json#comp-roll-up-by-department
	 */
	public function getName(): string {
		return 'Let compliance officers and administration managers use the actions granted to them';
	}//end getName()

	/**
	 * Rename the stale names in the stored matrix.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/parity/capabilities.json#comp-roll-up-by-department
	 */
	public function run(IOutput $output): void {
		$matrix = $this->actionAuth->getMatrix();
		$renamed = $this->rename(matrix: $matrix);
		if ($renamed === $matrix) {
			return;
		}

		try {
			$this->actionAuth->setMatrix($renamed);
		} catch (Throwable $e) {
			$output->warning('Learniq action groups: the action matrix could not be written (' . $e->getMessage() . ').');
			return;
		}

		$output->info('Learniq action groups: compliance-officer and manager now read compliance-officers and administration-managers.');
	}//end run()

	/**
	 * The matrix with every stale name replaced by its group, without
	 * duplicates; a name that is a real group stays.
	 *
	 * @param array<string,mixed> $matrix The stored matrix.
	 *
	 * @return array<string,mixed> The renamed matrix.
	 *
	 * @spec openspec/parity/capabilities.json#comp-roll-up-by-department
	 */
	public function rename(array $matrix): array {
		$renames = [];
		foreach (self::RENAMES as $stale => $group) {
			if ($this->groupManager->groupExists($stale) === false) {
				$renames[$stale] = $group;
			}
		}

		foreach ($matrix as $action => $groups) {
			if (is_array($groups) === false) {
				continue;
			}

			$fixed = [];
			foreach ($groups as $group) {
				$name = $group;
				if (is_string($group) === true && isset($renames[$group]) === true) {
					$name = $renames[$group];
				}

				if (in_array($name, $fixed, true) === false) {
					$fixed[] = $name;
				}
			}

			$matrix[$action] = $fixed;
		}

		return $matrix;
	}//end rename()
}//end class
