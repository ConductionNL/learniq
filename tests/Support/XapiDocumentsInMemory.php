<?php

/**
 * An ObjectService double over RegisterFaithfulStore for the xapi-document schema.
 *
 * `find`, `findAll`, `saveObject` and `deleteObject` act on the same rows, with
 * OpenRegister's filter semantics, so a test reads back exactly what the code
 * wrote. The xapi-document schema is declared through
 * RegisterFaithfulStore::declarePending() until it ships in the register.
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

/**
 * Builds the ObjectService double; use from a TestCase.
 */
trait XapiDocumentsInMemory {

	/**
	 * The properties the pending xapi-document schema declares (see the change's design.md).
	 *
	 * @var array<int, string>
	 */
	private static array $xapiDocumentProperties = [
		'kind', 'documentId', 'activityId', 'registration', 'agent', 'contents', 'contentEncoding',
		'contentType', 'etag', 'updated', 'verified_actor_id', 'lessonId', 'tenant_id',
	];

	/**
	 * An ObjectService whose xapi-document rows live in `$store`.
	 *
	 * @param RegisterFaithfulStore $store The rows.
	 *
	 * @return ObjectService&MockObject
	 */
	private function xapiObjectService(RegisterFaithfulStore $store): ObjectService&MockObject {
		RegisterFaithfulStore::declarePending('xapi-document', self::$xapiDocumentProperties);

		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			static fn (array $config = [], bool $rbac = true, bool $multitenancy = true): array => $store->findAll($config, $rbac, $multitenancy)
		);
		$objects->method('saveObject')->willReturnCallback(
			static fn (array|ObjectEntity $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null): ObjectEntity => $store->save((string)$schema, (array)$object, $uuid)
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
			static function (string $uuid, mixed $register = null, mixed $schema = null) use ($store): bool {
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
