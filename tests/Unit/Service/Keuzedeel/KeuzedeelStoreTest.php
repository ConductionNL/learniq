<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec openspec/changes/exam-committee-today-and-keuzedelen/specs/enrolment/spec.md#requirement-an-mbo-student-chooses-a-keuzedeel-inside-its-window
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Keuzedeel;

use OCA\Learniq\Service\Keuzedeel\KeuzedeelStore;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Learniq\Service\Keuzedeel\KeuzedeelStore
 * @uses   \OCA\Learniq\Service\Keuzedeel\KeuzedeelRules
 */
class KeuzedeelStoreTest extends TestCase {

	/**
	 * Only offered keuzedelen of her programme and groups, from her live enrolments.
	 *
	 * @return void
	 */
	public function testOffersForTheStudent(): void {
		$offers = $this->store()->offersFor('milan');

		self::assertSame(['kd-all', 'kd-prog', 'kd-group'], array_column($offers, 'id'));
	}//end testOffersForTheStudent()

	/**
	 * One offer by id, her own choices, and nothing for an unknown id.
	 *
	 * @return void
	 */
	public function testOfferAndChoices(): void {
		$store = $this->store();

		self::assertSame('kd-prog', $store->offer('kd-prog')['id']);
		self::assertNull($store->offer('nope'));
		self::assertNull($store->offer(''));
		self::assertSame(['kc-1'], array_column($store->choices(['learnerId' => 'milan']), 'id'));
	}//end testOfferAndChoices()

	/**
	 * The store over an in-memory register.
	 *
	 * @return KeuzedeelStore
	 */
	private function store(): KeuzedeelStore {
		$rows = [
			'enrolment'        => [
				['id' => 'en-1', 'learnerId' => 'milan', 'programmeId' => 'p-1', 'cohortId' => 'mt3a', 'lifecycle' => 'active'],
				['id' => 'en-2', 'learnerId' => 'milan', 'programmeId' => 'p-old', 'cohortId' => 'old', 'lifecycle' => 'withdrawn'],
			],
			'keuzedeel-offer'  => [
				['id' => 'kd-all', 'lifecycle' => 'offered'],
				['id' => 'kd-prog', 'programmeId' => 'p-1', 'lifecycle' => 'offered'],
				['id' => 'kd-group', 'cohortIds' => ['mt3a'], 'lifecycle' => 'offered'],
				['id' => 'kd-old', 'programmeId' => 'p-old', 'lifecycle' => 'offered'],
				['id' => 'kd-gone', 'lifecycle' => 'withdrawn'],
			],
			'keuzedeel-choice' => [['id' => 'kc-1', 'learnerId' => 'milan'], ['id' => 'kc-2', 'learnerId' => 'sara']],
		];
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			static function (array $config = []) use ($rows): array {
				$schema = (string) $config['filters']['schema'];
				return array_map(static fn (array $row): ObjectEntity => OrEntityFactory::make($row, $schema), $rows[$schema] ?? []);
			}
		);

		return new KeuzedeelStore($objects);
	}//end store()
}//end class
