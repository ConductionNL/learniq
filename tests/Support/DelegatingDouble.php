<?php

/**
 * A mock of an OpenRegister class whose methods answer through a plain test double.
 *
 * A typed constructor parameter takes a mock of the real class, while the
 * existing tests keep their small anonymous doubles. Each call is passed on by
 * parameter NAME, keeping only the names the double declares and leaving out
 * arguments that equal the declared default, so a double that leaves out a
 * parameter it does not need still works and records only what was passed.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Support
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
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Support;

use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Service\OrganisationService;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionMethod;

/**
 * Builds delegating mocks inside a TestCase.
 */
trait DelegatingDouble {

	/**
	 * A mock of the class whose listed methods call the double's methods of the same name.
	 *
	 * @param class-string        $class The mocked class.
	 * @param array{0: object, 1: array<int, string>} $route The double and the methods it answers.
	 *
	 * @return MockObject
	 */
	private function delegatingMock(string $class, array $route): MockObject {
		[$double, $methods] = $route;
		$mock = $this->createMock($class);
		foreach ($methods as $method) {
			$params = (new ReflectionMethod($class, $method))->getParameters();
			$names  = array_map(static fn ($param): string => $param->getName(), $params);
			$unset  = array_map(static fn ($param): array => ($param->isDefaultValueAvailable() === true) ? [$param->getDefaultValue()] : [], $params);
			$own   = new ReflectionMethod($double, $method);
			$known = array_map(static fn ($param): string => $param->getName(), $own->getParameters());
			$open  = $own->isVariadic();
			$mock->method($method)->willReturnCallback(
				static function (mixed ...$args) use ($double, $method, $names, $unset, $known, $open): mixed {
					$named = [];
					foreach (array_values($args) as $index => $value) {
						$name = $names[$index];
						if ($unset[$index] !== [] && $unset[$index][0] === $value) {
							continue;
						}

						if ($open === true || in_array($name, $known, true) === true) {
							$named[$name] = $value;
						}
					}

					return $double->$method(...$named);
				}
			);
		}

		return $mock;
	}//end delegatingMock()

	/**
	 * An OrganisationService mock whose default organisation has the uuid the double's answer has.
	 *
	 * The double may throw, and its answer may be any object with getUuid().
	 *
	 * @param object $double An object with ensureDefaultOrganisation().
	 *
	 * @return MockObject
	 */
	private function organisationServiceOver(object $double): MockObject {
		$mock = $this->createMock(OrganisationService::class);
		$mock->method('ensureDefaultOrganisation')->willReturnCallback(
			static function () use ($double): Organisation {
				$organisation = new Organisation();
				$organisation->setUuid($double->ensureDefaultOrganisation()->getUuid());
				return $organisation;
			}
		);

		return $mock;
	}//end organisationServiceOver()
}//end class
