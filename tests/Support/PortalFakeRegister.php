<?php

/**
 * In-memory OpenRegister for the portal assessment tests.
 *
 * Answers the way OpenRegister does where it matters to these services:
 * `findAll()` reads `register` and `schema` only from `filters` (a call that
 * passes them elsewhere reads nothing), `find()` throws DoesNotExistException
 * for an unknown id, and every write records the user it ran as, so a test can
 * assert that a portal write happened inside `runAs()` for the pupil.
 *
 * A create of an AssessmentResult also does what the listeners do on a real
 * instance: the attempt gate clears the typed access code, and
 * AssessmentDrawResolver fills `drawnItemRefs` from the test's fixed list.
 *
 * Required explicitly by the tests that use it; deliberately not autoloaded
 * (see OrEntityFactory for why).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Support
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Support;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * A fake register with write and transition logs.
 */
final class PortalFakeRegister {

	/**
	 * Rows by schema slug, then by id.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	public array $rows = [];

	/**
	 * Every write: schema, id, the body as sent, and who it ran as.
	 *
	 * @var array<int, array{schema: string, id: string, data: array<string, mixed>, as: string|null}>
	 */
	public array $writes = [];

	/**
	 * Every transition: object id, action, and who it ran as.
	 *
	 * @var array<int, array{id: string, action: string, as: string|null}>
	 */
	public array $transitions = [];

	/**
	 * The user the current operation runs as, null outside runAs().
	 *
	 * @var string|null
	 */
	private ?string $actingAs = null;

	/**
	 * The counter for new ids.
	 *
	 * @var int
	 */
	private int $nextId = 1;

	/**
	 * Store a row.
	 *
	 * @param string $schema Schema slug.
	 * @param string $id Object id.
	 * @param array<string, mixed> $row The row.
	 *
	 * @return void
	 */
	public function put(string $schema, string $id, array $row): void {
		$this->rows[$schema][$id] = $row;
	}//end put()

	/**
	 * A row, or null.
	 *
	 * @param string $schema Schema slug.
	 * @param string $id Object id.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get(string $schema, string $id): ?array {
		return $this->rows[$schema][$id] ?? null;
	}//end get()

	/**
	 * An ObjectService mock over this register.
	 *
	 * @param TestCase $test The test owning the mock.
	 *
	 * @return ObjectService
	 */
	public function objectService(TestCase $test): ObjectService {
		$service = $test->getMockBuilder(ObjectService::class)->disableOriginalConstructor()->getMock();

		$service->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null): ObjectEntity {
				$row = $this->rows[(string)$schema][(string)$id] ?? null;
				if ($register !== 'learniq' || $row === null) {
					throw new DoesNotExistException('not found');
				}

				return OrEntityFactory::make(array_merge($row, ['id' => (string)$id]), (string)$schema);
			}
		);

		$service->method('findAll')->willReturnCallback(
			function (array $config = []): array {
				return $this->findAll(filters: (array)($config['filters'] ?? []));
			}
		);

		$service->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null): ObjectEntity {
				return $this->save(data: (array)$object, schema: (string)$schema, uuid: $uuid);
			}
		);

		$service->method('runAs')->willReturnCallback(
			function (IUser $user, callable $operation) {
				$previous = $this->actingAs;
				$this->actingAs = $user->getUID();
				try {
					return $operation();
				} finally {
					$this->actingAs = $previous;
				}
			}
		);

		return $service;
	}//end objectService()

	/**
	 * A TransitionEngine mock: `submit` moves an attempt to `submitted`.
	 *
	 * @param TestCase $test The test owning the mock.
	 *
	 * @return TransitionEngine
	 */
	public function transitionEngine(TestCase $test): TransitionEngine {
		$engine = $test->getMockBuilder(TransitionEngine::class)->disableOriginalConstructor()->getMock();
		$engine->method('transition')->willReturnCallback(
			function (string $objectId, string $action, array $data = []): ObjectEntity {
				$this->transitions[] = ['id' => $objectId, 'action' => $action, 'as' => $this->actingAs];
				if ($action === 'submit') {
					$this->rows['assessment-result'][$objectId]['lifecycle'] = 'submitted';
				}

				return OrEntityFactory::make($this->rows['assessment-result'][$objectId] ?? [], 'assessment-result');
			}
		);

		return $engine;
	}//end transitionEngine()

	/**
	 * Rows matching filters; nothing without register and schema in them.
	 *
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return array<int, ObjectEntity>
	 */
	private function findAll(array $filters): array {
		$schema = ($filters['schema'] ?? null);
		if (($filters['register'] ?? null) !== 'learniq' || is_string($schema) === false) {
			return [];
		}

		unset($filters['register'], $filters['schema']);
		$found = [];
		foreach (($this->rows[$schema] ?? []) as $id => $row) {
			foreach ($filters as $key => $value) {
				if (($row[$key] ?? null) !== $value) {
					continue 2;
				}
			}

			$found[] = OrEntityFactory::make(array_merge($row, ['id' => (string)$id]), $schema);
		}

		return $found;
	}//end findAll()

	/**
	 * Upsert a row the way the listeners would leave it.
	 *
	 * @param array<string, mixed> $data The body as sent.
	 * @param string $schema Schema slug.
	 * @param string|null $uuid The id of an existing row.
	 *
	 * @return ObjectEntity
	 */
	private function save(array $data, string $schema, ?string $uuid): ObjectEntity {
		$id = ($uuid ?? ('new-' . $this->nextId++));
		$this->writes[] = ['schema' => $schema, 'id' => $id, 'data' => $data, 'as' => $this->actingAs];

		unset($data['id'], $data['@self']);
		if ($uuid === null && $schema === 'assessment-result') {
			// The attempt gate clears the code; the draw resolver freezes the items.
			$data['accessCode'] = null;
			$exam = ($this->rows['exam'][(string)($data['assessmentId'] ?? '')] ?? []);
			$data['drawnItemRefs'] = ($exam['itemRefs'] ?? []);
		}

		$this->rows[$schema][$id] = array_merge(($this->rows[$schema][$id] ?? []), $data);

		return OrEntityFactory::make(array_merge($this->rows[$schema][$id], ['id' => $id]), $schema);
	}//end save()
}//end class
