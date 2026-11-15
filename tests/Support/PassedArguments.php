<?php

/**
 * The arguments of a doubled call, by parameter name, without the ones left at their default.
 *
 * A golden that records a mocked call as its full positional argument list
 * follows the LOADED class: the local stub, or OpenRegister itself where it is
 * installed. When OpenRegister adds a parameter, every later position moves
 * and the golden breaks with nothing changed in learniq. Recording by name and
 * leaving out arguments equal to the declared default records what the code
 * actually passes, whichever class is loaded.
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

use ReflectionMethod;

/**
 * Names the arguments of a mocked call.
 */
final class PassedArguments {

	/**
	 * The call's arguments keyed by parameter name, leaving out those equal to the default.
	 *
	 * @param array{0: class-string, 1: string} $method The mocked class and method.
	 * @param array<int, mixed>                 $args   The arguments as the mock received them.
	 *
	 * @return array<string, mixed>
	 */
	public static function of(array $method, array $args): array {
		$named = [];
		foreach ((new ReflectionMethod($method[0], $method[1]))->getParameters() as $index => $param) {
			if (array_key_exists($index, $args) === false) {
				break;
			}

			if ($param->isDefaultValueAvailable() === true && $param->getDefaultValue() === $args[$index]) {
				continue;
			}

			$named[$param->getName()] = $args[$index];
		}

		return $named;
	}//end of()
}//end class
