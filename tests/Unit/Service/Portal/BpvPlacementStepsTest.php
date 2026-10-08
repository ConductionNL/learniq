<?php

/**
 * Where a work placement stands, read from what the school records.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\Portal
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
 *
 * @spec openspec/changes/site-workplace-trainer-portal-design/specs/portal-contribution/spec.md#requirement-new-a-placement-shows-where-it-stands
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Portal;

use OCA\Learniq\Service\Portal\BpvPlacementSteps;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IL10N;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * BpvPlacementSteps on Milan de Groot's placement of the vocational set.
 */
class BpvPlacementStepsTest extends TestCase {

	/**
	 * The service over a store holding the mbo set's rows for Milan's placement.
	 *
	 * @param string $placementId Filled with Milan's placement id.
	 *
	 * @return BpvPlacementSteps
	 */
	private function steps(?string &$placementId): BpvPlacementSteps {
		$set = json_decode((string)file_get_contents(__DIR__ . '/../../../../lib/Settings/profiles/mbo.json'), true);
		$objects = $set['x-openregister']['seedData']['objects'];
		$milan = array_values(array_filter($objects['bpv-placement'], static fn (array $p): bool => $p['learnerId'] === 'mbo-student-251'))[0];
		$placementId = $milan['uuid'];
		$store = new RegisterFaithfulStore();
		$rows = static fn (string $bucket): array => array_map(static fn (array $row): array => ['id' => $row['uuid']] + $row, $objects[$bucket]);
		$store->rows = [
			'bpv-placement' => $rows('bpv-placement'),
			'praktijkovereenkomst' => $rows('praktijkovereenkomst'),
			'pok-signature' => $rows('pok-signature'),
			'bpv-visit-report' => $rows('bpv-visit-report'),
		];
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(fn (array $config = []): array => $store->findAll($config, false, false));
		$factory = $this->createMock(IFactory::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$factory->method('get')->willReturn($l10n);

		return new BpvPlacementSteps(objectService: $objectService, l10n: $factory, logger: new NullLogger());
	}//end steps()

	/**
	 * Milan on Monday 5 October 2026: signed and planned, the midterm review on 13 October is next.
	 *
	 * @return void
	 */
	public function testMilansPlacementStandsAtTheMidtermReview(): void {
		$placementId = null;
		$steps = $this->steps(placementId: $placementId)->forPlacement(placementId: (string)$placementId);

		self::assertSame(['Agreement signed', 'Work plan made', 'Midterm review', 'Final review', 'Placement finished'], array_column($steps, 'label'));
		self::assertSame(['done', 'done', 'current', 'todo', 'todo'], array_column($steps, 'state'));
		self::assertSame(['27 augustus 2026', '9 september 2026', '13 oktober 2026', 'januari 2027', '29 januari 2027'], array_column($steps, 'description'));
		// Each day once: no step carries a date beside the line that names it (REPORT-2, item 8).
		self::assertSame([], array_column($steps, 'date'));
	}//end testMilansPlacementStandsAtTheMidtermReview()

	/**
	 * A placement nothing is recorded for yet waits at the agreement; an unknown id has no steps.
	 *
	 * @return void
	 */
	public function testAnEmptyPlacementWaitsForItsAgreement(): void {
		$placementId = null;
		$service = $this->steps(placementId: $placementId);
		$steps = $service->stepsOf(placement: ['periodTo' => '2027-07-02', 'lifecycle' => 'confirmed'], signatures: [], visits: [], l10n: null);

		self::assertSame(['current', 'todo', 'todo', 'todo', 'todo'], array_column($steps, 'state'));
		self::assertSame([], $service->forPlacement(placementId: 'nothing'));
	}//end testAnEmptyPlacementWaitsForItsAgreement()
}//end class
