<?php

/**
 * Adapts test doubles of ObjectService::saveObject() to its ObjectSaveOptions shape.
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
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;

/**
 * Flattens a saveObject(object, options, _rbac, _multitenancy) call to the flat
 * argument list (object, extend, register, schema, uuid, _rbac, _multitenancy,
 * silent, _validation, uploadedFiles, currentUser, failIfExists, _unowned,
 * _dedupOverride, _owner), so a double can keep reading the arguments by name.
 */
final class SaveCallShape {

	/**
	 * The flat parameters in order: name, whether it has a default, the default.
	 *
	 * @var array<int, array{0: string, 1: bool, 2: mixed}>
	 */
	private const FLAT_PARAMETERS = [
		['object', false, null],
		['extend', true, []],
		['register', true, null],
		['schema', true, null],
		['uuid', true, null],
		['_rbac', true, true],
		['_multitenancy', true, true],
		['silent', true, false],
		['_validation', true, true],
		['uploadedFiles', true, null],
		['currentUser', true, null],
		['failIfExists', true, false],
		['_unowned', true, false],
		['_dedupOverride', true, false],
		['_owner', true, null],
	];

	/**
	 * The flat argument list of one saveObject() call.
	 *
	 * @param array<int|string, mixed> $args The arguments the double received.
	 *
	 * @return array<int, mixed>
	 */
	public static function flat(array $args): array {
		$args    = array_values($args);
		$options = ($args[1] ?? null) ?? new ObjectSaveOptions();
		assert($options instanceof ObjectSaveOptions);

		return [
			$args[0] ?? null,
			$options->extend,
			$options->target->register,
			$options->target->schema,
			$options->uuid,
			($args[2] ?? true),
			($args[3] ?? true),
			$options->policy->silent,
			$options->policy->validation,
			$options->uploadedFiles,
			$options->actor->currentUser,
			$options->policy->failIfExists,
			$options->actor->unowned,
			$options->policy->dedupOverride,
			$options->actor->owner,
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
	 * The whole flat argument list keyed by the flat parameter names.
	 *
	 * @param array<int|string, mixed> $args The arguments the double received.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(array $args): array {
		return array_combine(array_column(self::FLAT_PARAMETERS, 0), self::flat(args: $args));
	}//end all()

	/**
	 * Wrap a callback written for the flat argument list.
	 *
	 * @param callable $callback Takes the flat saveObject() parameters, in order.
	 *
	 * @return Closure
	 */
	public static function legacy(callable $callback): Closure {
		return static fn (mixed ...$args): mixed => $callback(...self::flat(args: $args));
	}//end legacy()
}//end class
