<?php

/**
 * Every group the action matrix seed names is a group learniq uses.
 *
 * The seed granted the compliance roll-up, bulk external-training recording,
 * issuing a credential and assigning a regulation to `compliance-officer` and
 * `manager`; neither group exists, so a compliance officer (group
 * `compliance-officers`) was refused all four (live pass 2 Oct, D1:
 * livepass/learniq/compliance-exemption-record/officer2-rollup.json). The
 * groups learniq uses are the ones its register's authorization names; a
 * seed entry outside them can never match a user.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Settings
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

namespace OCA\Learniq\Tests\Unit\Settings;

use OCA\Learniq\Repair\RenameStaleActionGroups;
use OCA\Learniq\Service\DashboardRoleService;
use PHPUnit\Framework\TestCase;

/**
 * The seed names real groups.
 */
class ActionSeedGroupsTest extends TestCase {

	/**
	 * Every group string in the register's authorization blocks.
	 *
	 * @return array<int,string>
	 */
	private static function registerGroups(): array {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'), true);
		$groups = [];
		$walk = static function (mixed $node) use (&$walk, &$groups): void {
			if (is_array($node) === false) {
				return;
			}

			foreach ($node as $key => $value) {
				if ($key === 'authorization' && is_array($value) === true) {
					foreach ($value as $rule) {
						foreach ((array)$rule as $entry) {
							if (is_string($entry) === true) {
								$groups[] = $entry;
							} else if (is_array($entry) === true && is_string($entry['group'] ?? null) === true) {
								$groups[] = $entry['group'];
							}
						}
					}

					continue;
				}

				$walk($value);
			}
		};
		$walk($register);

		return array_values(array_unique($groups));
	}//end registerGroups()

	/**
	 * Every seeded group is admin or a group the register authorizes.
	 *
	 * @return void
	 */
	public function testEverySeededGroupExists(): void {
		$seed = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/actions.seed.json'), true);
		$known = array_merge(['admin'], self::registerGroups(), array_values(DashboardRoleService::GROUP_BACKED_ROLES));

		$unknown = [];
		foreach ($seed['actions'] as $action => $groups) {
			foreach ($groups as $group) {
				if (in_array($group, $known, true) === false) {
					$unknown[] = $action . ' => ' . $group;
				}
			}
		}

		self::assertSame([], $unknown, 'The action seed names groups nobody can be in.');
	}//end testEverySeededGroupExists()

	/**
	 * A compliance officer gets the four compliance actions.
	 *
	 * @return void
	 */
	public function testTheComplianceOfficerGetsTheComplianceActions(): void {
		$seed = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/actions.seed.json'), true);
		foreach (['compliance.department-rollup', 'external-training.bulk-record', 'external-training.issue-credential', 'regulation.assign'] as $action) {
			self::assertContains('compliance-officers', $seed['actions'][$action], $action);
		}
	}//end testTheComplianceOfficerGetsTheComplianceActions()

	/**
	 * The repair renames to exactly the groups the seed now names.
	 *
	 * @return void
	 */
	public function testTheRepairRenamesToRealGroups(): void {
		$known = self::registerGroups();
		foreach (RenameStaleActionGroups::RENAMES as $stale => $group) {
			self::assertContains($group, $known, $stale);
			self::assertNotContains($stale, $known, $stale);
		}
	}//end testTheRepairRenamesToRealGroups()
}//end class
