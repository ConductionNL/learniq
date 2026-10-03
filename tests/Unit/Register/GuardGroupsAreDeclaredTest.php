<?php

/**
 * Every group a guard or listener tests is a group the register declares.
 *
 * The exchange guards tested `coordinator` while the register declares and
 * provisions `coordinators`, so no coordinator could ever pass them. A group
 * name nobody is in fails closed and silently, so this pins every `*_GROUPS`
 * constant in lib/Lifecycle and lib/Listener to the declared groups (the
 * register's OAuth scopes) plus Nextcloud's own `admin`.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Register
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
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-a-schemas-declared-audience-is-enforced-by-its-authorization-block
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Register;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Guards test only declared groups.
 */
class GuardGroupsAreDeclaredTest extends TestCase {

	/**
	 * The groups the register declares, plus Nextcloud's `admin`.
	 *
	 * @return array<int, string>
	 */
	private function declaredGroups(): array {
		$register = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'),
			true
		);

		$scopes = ($register['components']['securitySchemes']['oauth2']['flows']['authorizationCode']['scopes'] ?? []);

		return [...array_keys($scopes), 'admin'];
	}//end declaredGroups()

	/**
	 * Every group named in a `*_GROUPS` constant of a guard or listener.
	 *
	 * @return array<string, array<int, string>> Group names by class::constant.
	 */
	private function testedGroups(): array {
		$found = [];
		foreach (['Lifecycle', 'Listener'] as $namespace) {
			foreach (glob(__DIR__ . '/../../../lib/' . $namespace . '/*.php') as $file) {
				$class = 'OCA\\Learniq\\' . $namespace . '\\' . basename($file, '.php');
				if (class_exists($class) === false) {
					continue;
				}

				foreach ((new ReflectionClass($class))->getConstants() as $name => $value) {
					if (str_ends_with($name, 'GROUPS') === true && is_array($value) === true) {
						$found[basename($file, '.php') . '::' . $name] = $value;
					}
				}
			}
		}

		return $found;
	}//end testedGroups()

	/**
	 * The scan finds the guards it exists for, so an empty scan cannot pass.
	 *
	 * @return void
	 */
	public function testTheScanSeesTheExchangeGuards(): void {
		$tested = $this->testedGroups();

		// The rejection guards left with ExchangeRejection (data-exchange-to-integriq).
		$this->assertArrayHasKey('MunicipalityFeedbackGuard::AUTHORISED_GROUPS', $tested);
		$this->assertGreaterThan(1, count($tested), 'The scan must see more than one guard.');
	}//end testTheScanSeesTheExchangeGuards()

	/**
	 * Every group a guard tests is declared.
	 *
	 * @return void
	 */
	public function testEveryGroupAGuardTestsIsDeclared(): void {
		$declared = $this->declaredGroups();

		$undeclared = [];
		foreach ($this->testedGroups() as $where => $groups) {
			foreach ($groups as $group) {
				if (in_array($group, $declared, true) === false) {
					$undeclared[] = $where . ': ' . $group;
				}
			}
		}

		$this->assertSame([], $undeclared);
	}//end testEveryGroupAGuardTestsIsDeclared()
}//end class
