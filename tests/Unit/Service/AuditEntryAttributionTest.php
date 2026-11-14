<?php

/**
 * Learniq AuditEntryAttribution golden test.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
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
 *
 * @spec openspec/specs/compliance-audit/spec.md#requirement-export-audit-ready-zip-per-regulation-and-date-range
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\AuditEntryAttribution;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins which audit entries reach the caller's pack: only those whose object
 * sits in the caller's tenant, narrowed to a regulation when one is asked.
 */
class AuditEntryAttributionTest extends TestCase {

	/**
	 * Every findAll call, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $reads = [];

	/**
	 * The attribution over an OpenRegister double that holds the given objects per schema.
	 *
	 * @param array<string, array<int, mixed>> $store Rows per schema; a schema mapped to null throws.
	 *
	 * @return AuditEntryAttribution
	 */
	private function attribution(array $store): AuditEntryAttribution {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config, bool $_rbac, bool $_multitenancy) use ($store): array {
				$this->reads[] = ['config' => $config, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				$schema = $config['filters']['schema'];
				if (($store[$schema] ?? []) === null) {
					throw new RuntimeException('schema not in register');
				}

				return ($store[$schema] ?? []);
			}
		);

		return new AuditEntryAttribution(objectService: $objects);
	}//end attribution()

	/**
	 * An object row as OpenRegister returns it: an entity with a uuid under `@self`.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $row): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($row);

		return $entity;
	}//end entity()

	/**
	 * ALLOWED and REFUSED: the caller's tenant's entries are kept, another
	 * tenant's are dropped silently, and unplaceable ones are counted.
	 *
	 * @return void
	 */
	public function testOnlyTheCallersTenantsEntriesAreKept(): void {
		$attribution = $this->attribution(
			[
				'enrolment' => [
					['id' => 'own', 'tenant_id' => 'tenant-a'],
					$this->entity(['@self' => ['id' => 'other'], 'tenant_id' => 'tenant-b']),
					['id' => 'untenanted', 'tenant_id' => ''],
					['id' => '', 'tenant_id' => 'tenant-a'],
				],
				'foreign'   => null,
			]
		);
		$entries = [
			['objectUuid' => 'own', 'schema' => 'enrolment', 'n' => 1],
			['objectUuid' => 'other', 'schema' => 'enrolment', 'n' => 2],
			['objectUuid' => 'untenanted', 'schema' => 'enrolment', 'n' => 3],
			['objectUuid' => 'gone', 'schema' => 'enrolment', 'n' => 4],
			['objectUuid' => 'x', 'schema' => 'foreign', 'n' => 5],
			['objectUuid' => '', 'schema' => 'enrolment', 'n' => 6],
			['objectUuid' => 'own', 'schema' => '', 'n' => 7],
			['objectUuid' => 'own', 'schema' => 'enrolment', 'n' => 8],
		];

		$result = $attribution->ownEntries($entries, 'tenant-a', '');

		self::assertSame(['events' => [$entries[0], $entries[6], $entries[7]], 'unattributed' => 4], $result);
		self::assertSame(
			[
				['config' => ['filters' => ['register' => 'learniq', 'schema' => 'enrolment'], 'ids' => ['own', 'other', 'untenanted', 'gone'], 'limit' => 4], 'rbac' => false, 'multitenancy' => false],
				['config' => ['filters' => ['register' => 'learniq', 'schema' => 'foreign'], 'ids' => ['x'], 'limit' => 1], 'rbac' => false, 'multitenancy' => false],
			],
			$this->reads
		);
	}//end testOnlyTheCallersTenantsEntriesAreKept()

	/**
	 * More than one chunk of uuids is read in chunks of 200.
	 *
	 * @return void
	 */
	public function testUuidsAreReadInChunksOf200(): void {
		$entries = [];
		$rows    = [];
		for ($i = 0; $i < 450; $i++) {
			$entries[] = ['objectUuid' => 'u' . $i, 'schema' => 'grade'];
			$rows[]    = ['id' => 'u' . $i, 'tenant_id' => 'tenant-a'];
		}

		$result = $this->attribution(['grade' => $rows])->ownEntries($entries, 'tenant-a', '');

		self::assertSame([200, 200, 50], array_map(static fn (array $read): int => $read['config']['limit'], $this->reads));
		self::assertCount(450, $result['events']);
	}//end testUuidsAreReadInChunksOf200()

	/**
	 * The regulation is the object's own slug, else the changed value's new
	 * then old value, from an array or a JSON string; anything else is no regulation.
	 *
	 * @return void
	 */
	public function testTheRegulationFilterReadsTheObjectThenTheChange(): void {
		$attribution = $this->attribution(
			[
				's' => [
					['id' => 'own-slug', 'tenant_id' => 't', 'regulationSlug' => 'wpo'],
					['id' => 'empty-slug', 'tenant_id' => 't', 'regulationSlug' => ''],
					['id' => 'number-slug', 'tenant_id' => 't', 'regulationSlug' => 5],
				],
			]
		);
		$entries = [
			['objectUuid' => 'own-slug', 'schema' => 's', 'changed' => ['regulationSlug' => 'wvo']],
			['objectUuid' => 'empty-slug', 'schema' => 's', 'changed' => ['regulationSlug' => ['old' => 'wpo', 'new' => 'wvo']]],
			['objectUuid' => 'empty-slug', 'schema' => 's', 'changed' => ['regulationSlug' => ['old' => 'wpo']]],
			['objectUuid' => 'number-slug', 'schema' => 's', 'changed' => '{"regulationSlug":"wpo"}'],
			['objectUuid' => 'number-slug', 'schema' => 's', 'changed' => ['regulationSlug' => 7]],
			['objectUuid' => 'number-slug', 'schema' => 's', 'changed' => 'not json'],
			['objectUuid' => 'number-slug', 'schema' => 's'],
		];

		self::assertSame([$entries[0], $entries[2], $entries[3]], $attribution->ownEntries($entries, 't', 'wpo')['events']);
		self::assertSame([$entries[1]], $attribution->ownEntries($entries, 't', 'wvo')['events']);
	}//end testTheRegulationFilterReadsTheObjectThenTheChange()
}//end class
