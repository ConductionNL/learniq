<?php

/**
 * An ObjectService double over RegisterFaithfulStore for the xapi-document schema.
 *
 * `find`, `findAll`, `saveObject` and `deleteObject` act on the same rows, with
 * OpenRegister's filter semantics, so a test reads back exactly what the code
 * wrote. Two more live behaviours are copied, both found on 2026-09-29:
 * - a save decodes a string value that holds a JSON object or list into an
 *   array, as OpenRegister does;
 * - a delete with multitenancy on does not find an object saved without a
 *   user session ("Object not found in magic table"), so it throws. The xapi-document schema is read from the register like every other.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
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
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use RuntimeException;

/**
 * Builds the ObjectService double; use from a TestCase.
 */
trait XapiDocumentsInMemory {

	/**
	 * An ObjectService whose xapi-document rows live in `$store`.
	 *
	 * @param RegisterFaithfulStore $store The rows.
	 *
	 * @return ObjectService&MockObject
	 */
	private function xapiObjectService(RegisterFaithfulStore $store): ObjectService&MockObject {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			static fn (array $config = [], bool $rbac = true, bool $multitenancy = true): array => $store->findAll($config, $rbac, $multitenancy)
		);
		$objects->method('saveObject')->willReturnCallback(
			static function (array|ObjectEntity $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null) use ($store): ObjectEntity {
				$row = (array)$object;
				foreach ($row as $name => $value) {
					if (is_string($value) === true && is_array(json_decode($value, true)) === true) {
						$row[$name] = json_decode($value, true);
					}
				}

				return $store->save((string)$schema, $row, $uuid);
			}
		);
		$objects->method('find')->willReturnCallback(
			static function (int|string $id, ?array $extend = [], bool $files = false, mixed $register = null, mixed $schema = null) use ($store): ?ObjectEntity {
				foreach (($store->rows[(string)$schema] ?? []) as $row) {
					if (($row['id'] ?? null) === $id) {
						return OrEntityFactory::make($row, (string)$schema);
					}
				}

				return null;
			}
		);
		$objects->method('deleteObject')->willReturnCallback(
			static function (string $uuid, mixed $register = null, mixed $schema = null, bool $rbac = true, bool $multitenancy = true) use ($store): bool {
				if ($multitenancy === true) {
					throw new RuntimeException('Object not found in magic table');
				}

				foreach (($store->rows[(string)$schema] ?? []) as $index => $row) {
					if (($row['id'] ?? null) === $uuid) {
						unset($store->rows[(string)$schema][$index]);
						$store->rows[(string)$schema] = array_values($store->rows[(string)$schema]);
						return true;
					}
				}

				return false;
			}
		);

		return $objects;
	}//end xapiObjectService()
}//end trait
