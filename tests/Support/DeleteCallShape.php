<?php

/**
 * Adapts test doubles of ObjectService::deleteObject() to its ObjectDeletion shape.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Support
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Support;

use Closure;
use OCA\OpenRegister\Service\Object\ObjectDeletion;

/**
 * Flattens a deleteObject(deletion, _rbac, _multitenancy, _retentionSweep) call
 * to the flat argument list (uuid, register, schema, _rbac, _multitenancy,
 * _retentionSweep, currentUser, permanent), so a double can keep reading the
 * arguments by name.
 */
final class DeleteCallShape {

	/**
	 * The flat parameters in order: name, whether it has a default, the default.
	 *
	 * @var array<int, array{0: string, 1: bool, 2: mixed}>
	 */
	private const FLAT_PARAMETERS = [
		['uuid', false, null],
		['register', true, null],
		['schema', true, null],
		['_rbac', true, true],
		['_multitenancy', true, true],
		['_retentionSweep', true, false],
		['currentUser', true, null],
		['permanent', true, false],
	];

	/**
	 * The flat argument list of one deleteObject() call.
	 *
	 * @param array<int|string, mixed> $args The arguments the double received.
	 *
	 * @return array<int, mixed>
	 */
	public static function flat(array $args): array {
		$args = array_values($args);
		$deletion = $args[0];
		assert($deletion instanceof ObjectDeletion);

		return [
			$deletion->uuid,
			$deletion->target->register,
			$deletion->target->schema,
			($args[1] ?? true),
			($args[2] ?? true),
			($args[3] ?? false),
			$deletion->currentUser,
			$deletion->permanent,
		];
	}//end flat()

	/**
	 * The flat argument list keyed by the flat parameter names, leaving out
	 * every argument equal to its flat default.
	 *
	 * @param array<int|string, mixed> $args The arguments the double received.
	 *
	 * @return array<string, mixed>
	 */
	public static function named(array $args): array {
		$named = [];
		foreach (self::flat(args: $args) as $position => $value) {
			[$name, $hasDefault, $default] = self::FLAT_PARAMETERS[$position];
			if ($hasDefault === true && $default === $value) {
				continue;
			}

			$named[$name] = $value;
		}

		return $named;
	}//end named()

	/**
	 * Wrap a callback written for the flat argument list.
	 *
	 * @param callable $callback Takes (uuid, register, schema, _rbac, _multitenancy, _retentionSweep, currentUser, permanent).
	 *
	 * @return Closure
	 */
	public static function legacy(callable $callback): Closure {
		return static fn (mixed ...$args): mixed => $callback(...self::flat(args: $args));
	}//end legacy()
}//end class
