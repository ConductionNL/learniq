<?php

/**
 * An in-memory OpenRegister stand-in that answers the way the live one does.
 *
 * Most fakes in this suite answer whatever they are asked, so a call site that
 * passes the wrong config can only pass. This one copies two behaviours of
 * OpenRegister that decide whether a lookup works at all:
 *
 * - `ObjectService::findAll()` reads the register and schema ONLY from
 *   `filters.register` / `filters.schema`. Passed at the top level they are
 *   inert, and this store answers with no rows.
 * - A filter on a property the shipped schema does not declare matches
 *   nothing (`MagicSearchHandler` emits `1 = 0`). This store reads the
 *   declared properties from `lib/Settings/learniq_register.json`.
 *
 * Saves are applied, so a test can read back what a call site wrote.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Support;

use OCA\OpenRegister\Db\ObjectEntity;
use RuntimeException;

/**
 * Rows per schema slug, with OpenRegister's filter semantics.
 */
final class RegisterFaithfulStore {

	/**
	 * Keys OpenRegister reserves in `filters`; never treated as properties.
	 */
	private const RESERVED = ['register', 'schema', '_rbac', '_multitenancy'];

	/**
	 * Declared property names per schema slug, from the shipped register.
	 *
	 * @var array<string, array<int, string>>|null
	 */
	private static ?array $declared = null;

	/**
	 * Slugs declared through declarePending(), not read from the register.
	 *
	 * @var array<string, true>
	 */
	private static array $pending = [];

	/**
	 * Rows keyed by schema slug.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	public array $rows = [];

	/**
	 * Every findAll() config received, in order.
	 *
	 * @var array<int, array{config: array<string, mixed>, rbac: bool, multitenancy: bool}>
	 */
	public array $reads = [];

	/**
	 * Every saveObject() call received, in order.
	 *
	 * @var array<int, array{schema: string, object: array<string, mixed>, uuid: string|null}>
	 */
	public array $saves = [];

	/**
	 * When set, every read throws this message.
	 *
	 * @var string|null
	 */
	public ?string $failReads = null;

	/**
	 * Answer a findAll() the way OpenRegister does.
	 *
	 * @param array<string, mixed> $config The findAll config.
	 * @param bool $rbac The _rbac flag.
	 * @param bool $multitenancy The _multitenancy flag.
	 *
	 * @return array<int, ObjectEntity>
	 */
	public function findAll(array $config, bool $rbac = true, bool $multitenancy = true): array {
		$this->reads[] = ['config' => $config, 'rbac' => $rbac, 'multitenancy' => $multitenancy];
		if ($this->failReads !== null) {
			throw new RuntimeException($this->failReads);
		}

		$filters = ($config['filters'] ?? []);
		$schema = ($filters['schema'] ?? null);
		if (is_string($schema) === false || ($filters['register'] ?? null) !== 'learniq') {
			return [];
		}

		$declared = (self::declaredProperties()[$schema] ?? []);
		$matches = [];
		foreach (($this->rows[$schema] ?? []) as $row) {
			if ($this->matches(row: $row, filters: $filters, declared: $declared) === true) {
				$matches[] = $row;
			}
		}

		$offset = (int)($config['offset'] ?? 0);
		$limit = ($config['limit'] ?? null);
		$matches = array_slice($matches, $offset, ($limit === null ? null : (int)$limit));

		return OrEntityFactory::makeMany($matches, $schema);
	}//end findAll()

	/**
	 * Apply a saveObject(): replace the row with the same id, or append.
	 *
	 * @param string $schema Schema slug.
	 * @param array<string, mixed> $object The object data.
	 * @param string|null $uuid The uuid argument.
	 *
	 * @return ObjectEntity
	 */
	public function save(string $schema, array $object, ?string $uuid): ObjectEntity {
		$this->saves[] = ['schema' => $schema, 'object' => $object, 'uuid' => $uuid];
		$id = ($uuid ?? ($object['id'] ?? ('new-' . count($this->saves))));
		$object['id'] = $id;
		foreach (($this->rows[$schema] ?? []) as $index => $row) {
			if (($row['id'] ?? null) === $id) {
				$this->rows[$schema][$index] = $object;
				return OrEntityFactory::make($object, $schema);
			}
		}

		$this->rows[$schema][] = $object;
		return OrEntityFactory::make($object, $schema);
	}//end save()

	/**
	 * Whether a row passes every filter; an undeclared key matches nothing.
	 *
	 * @param array<string, mixed> $row The row.
	 * @param array<string, mixed> $filters The filters.
	 * @param array<int, string> $declared Declared property names.
	 *
	 * @return bool
	 */
	private function matches(array $row, array $filters, array $declared): bool {
		foreach ($filters as $key => $value) {
			if (in_array($key, self::RESERVED, true) === true) {
				continue;
			}

			if (in_array($key, $declared, true) === false) {
				return false;
			}

			// OpenRegister answers a scalar filter on an array property with
			// "the array contains this value" (MagicSearchHandler's `@>`).
			$stored = ($row[$key] ?? null);
			if (is_array($stored) === true && is_scalar($value) === true) {
				if (in_array($value, $stored, true) === false) {
					return false;
				}

				continue;
			}

			if ($stored !== $value) {
				return false;
			}
		}

		return true;
	}//end matches()

	/**
	 * Declare a schema the register does not ship yet, so a test can exercise
	 * code written ahead of its register change. Remove the call once the
	 * schema is in `learniq_register.json`; a slug that already ships is
	 * refused so the two can never disagree.
	 *
	 * @param string             $slug       The schema slug.
	 * @param array<int, string> $properties The property names the pending schema declares.
	 *
	 * @return void
	 */
	public static function declarePending(string $slug, array $properties): void {
		$declared = self::declaredProperties();
		if (isset($declared[$slug]) === true && isset(self::$pending[$slug]) === false) {
			throw new RuntimeException("Schema '$slug' ships in the register now: drop the declarePending() call");
		}

		self::$declared[$slug] = $properties;
		self::$pending[$slug]  = true;
	}//end declarePending()

	/**
	 * Declared property names per schema slug, read once from the register.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function declaredProperties(): array {
		if (self::$declared !== null) {
			return self::$declared;
		}

		$register = json_decode(
			(string)file_get_contents(__DIR__ . '/../../lib/Settings/learniq_register.json'),
			true
		);
		self::$declared = [];
		foreach (($register['components']['schemas'] ?? []) as $name => $schema) {
			$slug = (string)($schema['slug'] ?? $name);
			self::$declared[$slug] = array_keys(($schema['properties'] ?? []));
		}

		return self::$declared;
	}//end declaredProperties()
}//end class
