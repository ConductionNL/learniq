<?php

/**
 * Adapts test doubles of ObjectService::find() to its ObjectFindOptions shape.
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
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;

/**
 * Flattens a find(id, options, _rbac, _multitenancy) call to the flat argument
 * list (id, _extend, files, register, schema, _rbac, _multitenancy, _render,
 * _audit), so a double can keep reading the arguments by name.
 */
final class FindCallShape {

	/**
	 * The flat parameters in order: name, whether it has a default, the default.
	 *
	 * @var array<int, array{0: string, 1: bool, 2: mixed}>
	 */
	private const FLAT_PARAMETERS = [
		['id', false, null],
		['_extend', true, []],
		['files', true, false],
		['register', true, null],
		['schema', true, null],
		['_rbac', true, true],
		['_multitenancy', true, true],
		['_render', true, true],
		['_audit', true, true],
	];

	/**
	 * The flat argument list of one find() or findSilent() call.
	 *
	 * @param array<int|string, mixed> $args The arguments the double received.
	 *
	 * @return array<int, mixed>
	 */
	public static function find(array $args): array {
		$args    = array_values($args);
		$options = ($args[1] ?? null) ?? new ObjectFindOptions();

		return [
			$args[0] ?? null,
			$options->extend,
			$options->files,
			$options->register,
			$options->schema,
			($args[2] ?? true),
			($args[3] ?? true),
			$options->render,
			$options->audit,
		];
	}//end find()

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
		foreach (self::find(args: $args) as $position => $value) {
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
	 * @param callable $callback Takes (id, _extend, files, register, schema, _rbac, _multitenancy, _render, _audit).
	 *
	 * @return Closure
	 */
	public static function legacy(callable $callback): Closure {
		return static fn (mixed ...$args): mixed => $callback(...self::find(args: $args));
	}//end legacy()
}//end class
