<?php

/**
 * Tests for PortalVisitAnswer.
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
 * @spec openspec/changes/bpv-coach-today-and-placement-board/specs/bpv/spec.md#requirement-a-school-coach-plans-visits-and-the-company-agrees-the-date
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Portal;

use OCA\Learniq\Service\Portal\PortalOutcome;
use OCA\Learniq\Service\Portal\PortalVisitAnswer;
use OCA\Learniq\Support\SerialisedRow;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * A trainer accepts or answers the visit date the coach proposed, or is refused.
 */
#[CoversClass(PortalVisitAnswer::class)]
#[UsesClass(PortalOutcome::class)]
#[UsesClass(SerialisedRow::class)]
final class PortalVisitAnswerTest extends TestCase {

	private const TRAINER = 'ee030010-0000-4000-8000-000000000001';

	private const OTHER_TRAINER = 'ee030010-0000-4000-8000-000000000002';

	private const PLACEMENT = 'ee030020-0000-4000-8000-000000000001';

	private const VISIT = 'ee030050-0000-4000-8000-000000000001';

	private const MOMENT = '2026-10-13T10:00:00+02:00';

	/**
	 * The fake OpenRegister store.
	 *
	 * @var RegisterFaithfulStore
	 */
	private RegisterFaithfulStore $store;

	/**
	 * The service over a store with one trainer (and another), Jesse's
	 * placement with her, and his mid assessment visit proposed for Tuesday
	 * 13 October 10.00.
	 *
	 * @param string $lifecycle  The visit's state.
	 * @param bool   $saveThrows Whether the write fails downstream.
	 *
	 * @return PortalVisitAnswer
	 */
	private function answers(string $lifecycle='proposed', bool $saveThrows=false): PortalVisitAnswer {
		$this->store = new RegisterFaithfulStore();
		$this->store->rows = [
			'praktijkopleider' => [
				['id' => self::TRAINER, 'givenName' => 'Karin', 'familyName' => 'Smit'],
				['id' => self::OTHER_TRAINER, 'givenName' => 'Piet', 'familyName' => 'Bos'],
			],
			'bpv-placement' => [
				['id' => self::PLACEMENT, 'practicalTrainerId' => self::TRAINER, 'learnerRef' => 'lp-jesse'],
			],
			'bpv-visit' => [
				[
					'id' => self::VISIT,
					'bpvPlacementId' => self::PLACEMENT,
					'visitKind' => 'tussentijds-gesprek',
					'schoolCoachId' => 'ruud',
					'proposedAt' => self::MOMENT,
					'trainerResponse' => 'Liever in de ochtend.',
					'lifecycle' => $lifecycle,
					'tenant_id' => '00000000-0000-4000-8000-000000000000',
				],
			],
		];

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config=[], bool $_rbac=true, bool $_multitenancy=true): array => $this->store->findAll($config, $_rbac, $_multitenancy)
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend=[], $register=null, $schema=null, ?string $uuid=null, bool $_rbac=true) use ($saveThrows): object {
				if ($saveThrows === true) {
					throw new RuntimeException('SQLSTATE 23505 secret table name');
				}

				return $this->store->save((string) $schema, $object, $uuid, $_rbac);
			}
		);

		return new PortalVisitAnswer(objectService: $objectService, logger: new NullLogger());
	}//end answers()

	/**
	 * The stored visit.
	 *
	 * @return array<string, mixed>
	 */
	private function visit(): array {
		return $this->store->rows['bpv-visit'][0];
	}//end visit()

	/**
	 * Accepting plans the visit for the moment the coach proposed, through
	 * the declared `accept` transition, and clears her earlier note.
	 *
	 * @return void
	 */
	public function testAcceptingPlansTheVisitForTheProposedMoment(): void {
		$outcome = $this->answers()->answer(trainerRef: self::TRAINER, body: ['bpvVisitId' => self::VISIT, 'outcome' => 'accept']);

		self::assertSame(200, $outcome->status);
		self::assertSame(['bpvVisitId' => self::VISIT, 'lifecycle' => 'planned', 'plannedAt' => self::MOMENT], $outcome->body);
		$visit = $this->visit();
		self::assertSame('planned', $visit['lifecycle']);
		self::assertSame(self::MOMENT, $visit['plannedAt']);
		self::assertSame(self::MOMENT, $visit['proposedAt']);
		self::assertNull($visit['trainerResponse']);
		self::assertSame(self::TRAINER, $visit['practicalTrainerId']);
		self::assertSame('ruud', $visit['schoolCoachId']);
	}//end testAcceptingPlansTheVisitForTheProposedMoment()

	/**
	 * Answering sends the visit back to the coach unplanned with her note, and
	 * keeps the moment she answered to.
	 *
	 * @return void
	 */
	public function testAnsweringSendsTheVisitBackWithHerNote(): void {
		$outcome = $this->answers()->answer(
			trainerRef: self::TRAINER,
			body: ['bpvVisitId' => self::VISIT, 'outcome' => 'answer', 'trainerResponse' => '  Woensdag 14 oktober om 13.00 kan wel.  ']
		);

		self::assertSame(200, $outcome->status);
		self::assertSame('unplanned', $outcome->body['lifecycle']);
		self::assertNull($outcome->body['plannedAt']);
		$visit = $this->visit();
		self::assertSame('unplanned', $visit['lifecycle']);
		self::assertSame('Woensdag 14 oktober om 13.00 kan wel.', $visit['trainerResponse']);
		self::assertSame(self::MOMENT, $visit['proposedAt']);
	}//end testAnsweringSendsTheVisitBackWithHerNote()

	/**
	 * An incomplete request, an unknown outcome and an answer without a note
	 * are refused before anything is read or written.
	 *
	 * @return void
	 */
	public function testIncompleteRequestsAreRefusedBeforeAnyRead(): void {
		$cases = [
			'incomplete' => [self::TRAINER, ['outcome' => 'accept']],
			'unknown_outcome' => [self::TRAINER, ['bpvVisitId' => self::VISIT, 'outcome' => 'maybe']],
			'answer_needs_note' => [self::TRAINER, ['bpvVisitId' => self::VISIT, 'outcome' => 'answer', 'trainerResponse' => '   ']],
		];
		$cases['incomplete-trainer'] = ['', ['bpvVisitId' => self::VISIT, 'outcome' => 'accept']];
		foreach ($cases as $error => [$trainer, $body]) {
			$service = $this->answers();
			$outcome = $service->answer(trainerRef: $trainer, body: $body);

			self::assertSame(422, $outcome->status, $error);
			self::assertSame(str_replace('-trainer', '', $error), $outcome->body['error']);
			self::assertSame([], $this->store->reads, $error);
			self::assertSame([], $this->store->saves, $error);
		}
	}//end testIncompleteRequestsAreRefusedBeforeAnyRead()

	/**
	 * Another trainer's student, an unknown trainer, a missing visit and a
	 * visit that is not waiting for an answer are each refused and nothing is
	 * written.
	 *
	 * @return void
	 */
	public function testAVisitThatIsNotHersOrNotProposedIsRefused(): void {
		$cases = [
			[self::OTHER_TRAINER, self::VISIT, 'proposed', 403, 'not_your_student'],
			['ee030010-0000-4000-8000-000000000099', self::VISIT, 'proposed', 403, 'unknown_trainer'],
			[self::TRAINER, 'ee030050-0000-4000-8000-000000000099', 'proposed', 404, 'not_found'],
			[self::TRAINER, self::VISIT, 'planned', 409, 'not_proposed'],
			[self::TRAINER, self::VISIT, 'unplanned', 409, 'not_proposed'],
		];
		foreach ($cases as [$trainer, $visitId, $lifecycle, $status, $error]) {
			$service = $this->answers(lifecycle: $lifecycle);
			$outcome = $service->answer(trainerRef: $trainer, body: ['bpvVisitId' => $visitId, 'outcome' => 'accept']);

			self::assertSame($status, $outcome->status, $error);
			self::assertSame($error, $outcome->body['error']);
			self::assertSame([], $this->store->saves, $error);
			self::assertSame($lifecycle, $this->visit()['lifecycle']);
		}
	}//end testAVisitThatIsNotHersOrNotProposedIsRefused()

	/**
	 * A failed write answers 502 and leaks nothing of the failure.
	 *
	 * @return void
	 */
	public function testAFailedWriteLeaksNothing(): void {
		$outcome = $this->answers(saveThrows: true)->answer(trainerRef: self::TRAINER, body: ['bpvVisitId' => self::VISIT, 'outcome' => 'accept']);

		self::assertSame(502, $outcome->status);
		self::assertSame(['error' => 'downstream_error'], $outcome->body);
		self::assertSame('proposed', $this->visit()['lifecycle']);
	}//end testAFailedWriteLeaksNothing()
}//end class
