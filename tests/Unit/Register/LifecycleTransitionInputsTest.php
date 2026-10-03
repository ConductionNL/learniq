<?php

/**
 * Every key a lifecycle guard or action reads from the caller is a declared transition input.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Register
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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

namespace OCA\Learniq\Tests\Unit\Register;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * OpenRegister's TransitionEngine::resolveTransitionInputs() refuses any request
 * key the transition does not declare in `inputs` (422), and only declared keys
 * reach the object the guard and the actions see. A guard or action that reads a
 * caller-supplied key the transition does not declare therefore can never pass
 * (learniq#983).
 *
 * Three checks, over every class a transition names in `requires` or `actions`:
 * - every key in the class's TRANSITION_INPUTS constant is declared in `inputs`
 *   on every transition that names the class;
 * - every key the class reads from the object that is neither stored on a schema
 *   that names the class (property or materialised calculation) nor a declared
 *   input fails (it can only come from the request, which can not carry it);
 * - no class still reads the old by-reference `$transitionContext['payload']`.
 */
class LifecycleTransitionInputsTest extends TestCase {

	/**
	 * Every class named by a transition, with the transitions that name it.
	 *
	 * @return array<string, list<array{schema: string, action: string, inputs: list<string>, properties: list<string>}>>
	 */
	private static function namedClasses(): array {
		$register = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'),
			true,
			flags: JSON_THROW_ON_ERROR
		);

		$classes = [];
		foreach (($register['components']['schemas'] ?? []) as $schemaKey => $schema) {
			// Stored properties and materialised calculations are on the object already.
			$properties = array_merge(
				array_keys(($schema['properties'] ?? [])),
				array_keys(($schema['x-openregister-calculations'] ?? []))
			);
			foreach (($schema['x-openregister-lifecycle']['transitions'] ?? []) as $action => $spec) {
				$inputs = [];
				foreach (($spec['inputs'] ?? []) as $input) {
					if (is_array($input) === true && ($input['field'] ?? '') !== '') {
						$inputs[] = (string)$input['field'];
					}
				}

				$names = [];
				if (is_string($spec['requires'] ?? null) === true) {
					$names[] = $spec['requires'];
				}

				foreach (($spec['actions'] ?? []) as $envelope) {
					if (is_array($envelope) === true && str_starts_with((string)($envelope['action'] ?? ''), 'OCA\\Learniq\\') === true) {
						$names[] = (string)$envelope['action'];
					}
				}

				foreach ($names as $name) {
					$classes[$name][] = [
						'schema' => (string)$schemaKey,
						'action' => (string)$action,
						'inputs' => $inputs,
						'properties' => $properties,
					];
				}
			}//end foreach
		}//end foreach

		return $classes;
	}//end namedClasses()

	/**
	 * The walk must find the classes, or every other assertion is vacuous.
	 *
	 * @return void
	 */
	public function testRegisterNamesClasses(): void {
		self::assertGreaterThanOrEqual(50, count(self::namedClasses()));
	}//end testRegisterNamesClasses()

	/**
	 * Every TRANSITION_INPUTS key is declared on every transition that names the class.
	 *
	 * @return void
	 */
	public function testDeclaredTransitionInputsAreDeclaredInTheRegister(): void {
		$missing = [];
		foreach (self::namedClasses() as $class => $transitions) {
			if (class_exists($class) === false) {
				continue;
			}

			$constant = (new ReflectionClass($class))->getConstants()['TRANSITION_INPUTS'] ?? [];
			foreach ($transitions as $transition) {
				foreach (array_diff($constant, $transition['inputs']) as $key) {
					$missing[] = sprintf('%s.%s: %s reads "%s", not in inputs', $transition['schema'], $transition['action'], $class, $key);
				}
			}
		}

		self::assertSame([], $missing, implode("\n", $missing));
	}//end testDeclaredTransitionInputsAreDeclaredInTheRegister()

	/**
	 * A key read from the object that is neither a schema property nor a declared input can not arrive.
	 *
	 * @return void
	 */
	public function testNoClassReadsAKeyTheTransitionCanNotCarry(): void {
		$unreachable = [];
		foreach (self::namedClasses() as $class => $transitions) {
			if (class_exists($class) === false) {
				continue;
			}

			// A guard shared by several schemas reads each schema's own keys, so a key
			// is on the object when any schema that names the class stores it.
			$stored = ['id', 'uuid', '@self'];
			foreach ($transitions as $transition) {
				$stored = array_merge($stored, $transition['properties']);
			}

			$read = self::keysReadFromTheObject(class: $class);
			foreach ($transitions as $transition) {
				$reachable = array_merge($stored, $transition['inputs']);
				foreach (array_diff($read, $reachable) as $key) {
					$unreachable[] = sprintf('%s.%s: %s reads "%s", neither a property nor an input', $transition['schema'], $transition['action'], $class, $key);
				}
			}
		}

		self::assertSame([], $unreachable, implode("\n", $unreachable));
	}//end testNoClassReadsAKeyTheTransitionCanNotCarry()

	/**
	 * No class reads the by-reference context shape OpenRegister never passes.
	 *
	 * @return void
	 */
	public function testNoClassReadsTheOldPayloadContext(): void {
		$old = [];
		foreach (array_keys(self::namedClasses()) as $class) {
			if (class_exists($class) === false) {
				continue;
			}

			$source = (string)file_get_contents((string)(new ReflectionClass($class))->getFileName());
			if (preg_match('/\$transitionContext\s*\[|array\s*&\$transitionContext/', $source) === 1) {
				$old[] = $class;
			}
		}

		self::assertSame([], $old, 'Still on the old $transitionContext shape: ' . implode(', ', $old));
	}//end testNoClassReadsTheOldPayloadContext()

	/**
	 * The keys a guard's check() or an action's execute() reads from the object it is handed.
	 *
	 * Reads `$<param>['key']` for the object parameter of check()/execute(), and of
	 * the private allows() the part-A guards delegate to.
	 *
	 * @param string $class The class name.
	 *
	 * @return list<string> The keys read.
	 */
	private static function keysReadFromTheObject(string $class): array {
		$reflection = new ReflectionClass($class);
		$source = (string)file_get_contents((string)$reflection->getFileName());

		$variables = [];
		foreach (['check', 'execute', 'allows'] as $method) {
			if ($reflection->hasMethod($method) === true) {
				$parameters = $reflection->getMethod($method)->getParameters();
				if ($parameters !== [] && (string)$parameters[0]->getType() === 'array') {
					$variables[] = $parameters[0]->getName();
				}
			}
		}

		$keys = [];
		foreach (array_unique($variables) as $variable) {
			preg_match_all('/\$' . preg_quote($variable, '/') . '\[\'([A-Za-z0-9_@]+)\'\]/', $source, $matches);
			$keys = array_merge($keys, $matches[1]);
		}

		return array_values(array_unique($keys));
	}//end keysReadFromTheObject()
}//end class
