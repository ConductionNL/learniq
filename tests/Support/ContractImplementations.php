<?php

/**
 * Resolves a Kernel contract to the class the app binds it to.
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

/**
 * Reads the registerServiceAlias() lines of every lib/<Component>/ContractBindings.php,
 * so a test that builds collaborators by declared type sees the implementation
 * a contract stands for, exactly as the container resolves it.
 */
final class ContractImplementations {

	/**
	 * Contract FQCN => implementation FQCN.
	 *
	 * @var array<string, string>|null
	 */
	private static ?array $map = null;

	/**
	 * The implementation bound to a contract, or the type itself when it is no contract.
	 *
	 * @param string $type A declared type name.
	 *
	 * @return string
	 */
	public static function concrete(string $type): string {
		return self::map()[ltrim($type, '\\')] ?? $type;
	}//end concrete()

	/**
	 * Parse the bindings once.
	 *
	 * @return array<string, string>
	 */
	private static function map(): array {
		if (self::$map !== null) {
			return self::$map;
		}

		self::$map = [];
		foreach (glob(dirname(__DIR__, 2) . '/lib/*/ContractBindings.php') ?: [] as $file) {
			$source = (string)file_get_contents($file);
			preg_match('/^namespace ([^;]+);/m', $source, $namespace);
			$uses = [];
			preg_match_all('/^use ([^;]+);/m', $source, $imports);
			foreach ($imports[1] as $import) {
				$uses[substr($import, (int)strrpos($import, '\\') + 1)] = $import;
			}

			preg_match_all('/registerServiceAlias\((\w+)::class, (\w+)::class\)/', $source, $aliases, PREG_SET_ORDER);
			foreach ($aliases as [, $contract, $implementation]) {
				$contractName = $uses[$contract] ?? ($namespace[1] . '\\' . $contract);
				self::$map[$contractName] = $uses[$implementation] ?? ($namespace[1] . '\\' . $implementation);
			}
		}

		return self::$map;
	}//end map()
}//end class
