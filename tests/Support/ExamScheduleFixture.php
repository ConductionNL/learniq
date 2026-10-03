<?php

/**
 * Shared fixture for the exam schedule tests: an OpenRegister ObjectService
 * double that answers `find()` by id and `findAll()` through
 * RegisterFaithfulStore, so a filter on an undeclared property matches nothing.
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
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Builds the ObjectService double over a RegisterFaithfulStore.
 */
final class ExamScheduleFixture {

	public const TENANT = '11111111-1111-4111-8111-111111111111';

	/**
	 * The rows the double answers with.
	 *
	 * @var RegisterFaithfulStore
	 */
	public RegisterFaithfulStore $store;

	/**
	 * Constructor.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->store = new RegisterFaithfulStore();
	}//end __construct()

	/**
	 * Add a row to a schema.
	 *
	 * @param string $schema The schema slug.
	 * @param array<string, mixed> $row The row, with an `id`.
	 *
	 * @return void
	 */
	public function add(string $schema, array $row): void {
		$this->store->rows[$schema][] = $row;
	}//end add()

	/**
	 * Wire an ObjectService mock to answer from the store.
	 *
	 * @param ObjectService&MockObject $service A mock the test created.
	 *
	 * @return ObjectService
	 */
	public function wire(ObjectService&MockObject $service): ObjectService {
		$service->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, mixed $register = null, mixed $schema = null): ?ObjectEntity {
				foreach (($this->store->rows[(string)$schema] ?? []) as $row) {
					if (($row['id'] ?? null) === $id) {
						return OrEntityFactory::make($row, (string)$schema);
					}
				}

				return null;
			}
		);
		$service->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $this->store->findAll($config, $_rbac, $_multitenancy)
		);

		return $service;
	}//end wire()
}//end class
