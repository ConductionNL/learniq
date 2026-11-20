<?php

/**
 * Tests for BpvVisitProposalStamp.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Listener
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

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\Listener\BpvVisitProposalStamp;
use OCA\Learniq\Listener\WrittenObject;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * "Datum voorstellen" puts a visit in proposed, waiting for the company.
 */
#[CoversClass(BpvVisitProposalStamp::class)]
#[UsesClass(WrittenObject::class)]
final class BpvVisitProposalStampTest extends TestCase {

	private const VISIT = 'ee030050-0000-4000-8000-000000000001';

	private const MOMENT = '2026-10-13T10:00:00+02:00';

	/**
	 * The stamp, with the resolver reporting this schema (or failing).
	 *
	 * @param string|null $slug The schema the resolver reports; null throws.
	 *
	 * @return BpvVisitProposalStamp
	 */
	private function stamp(?string $slug='bpv-visit'): BpvVisitProposalStamp {
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		if ($slug === null) {
			$resolver->method('guardSchemaSlug')->willThrowException(new RuntimeException('no schema'));
		} else {
			$resolver->method('guardSchemaSlug')->willReturn($slug);
		}

		return new BpvVisitProposalStamp($resolver);
	}//end stamp()

	/**
	 * Jesse's mid assessment visit without a date.
	 *
	 * @param array<string, mixed> $extra The fields that differ.
	 *
	 * @return array<string, mixed>
	 */
	private static function visit(array $extra=[]): array {
		return $extra + [
			'bpvPlacementId' => 'ee030020-0000-4000-8000-000000000001',
			'visitKind' => 'tussentijds-gesprek',
			'schoolCoachId' => 'ruud',
			'lifecycle' => 'unplanned',
		];
	}//end visit()

	/**
	 * An update from the stored visit to the written one.
	 *
	 * @param array<string, mixed> $stored  The stored visit.
	 * @param array<string, mixed> $written The visit as it will be saved.
	 *
	 * @return ObjectUpdatingEvent
	 */
	private static function update(array $stored, array $written): ObjectUpdatingEvent {
		return new ObjectUpdatingEvent(
			OrEntityFactory::make($written, 'bpv-visit', 'learniq', self::VISIT),
			OrEntityFactory::make($stored, 'bpv-visit', 'learniq', self::VISIT)
		);
	}//end update()

	/**
	 * The coach proposes Tuesday 13 October 10.00: the visit reads proposed,
	 * and the company's earlier answer is cleared so she answers this moment.
	 *
	 * @return void
	 */
	public function testProposingAMomentPutsTheVisitInProposed(): void {
		$event = self::update(
			self::visit(['trainerResponse' => 'Liever woensdag.']),
			self::visit(['trainerResponse' => 'Liever woensdag.', 'proposedAt' => self::MOMENT])
		);

		$this->stamp()->handle($event);

		self::assertSame(['lifecycle' => 'proposed', 'plannedAt' => null, 'trainerResponse' => null], $event->getModifiedData());
	}//end testProposingAMomentPutsTheVisitInProposed()

	/**
	 * A new visit created with a moment is proposed at once, and a proposed
	 * visit given another moment stays proposed for the new one.
	 *
	 * @return void
	 */
	public function testANewVisitWithAMomentAndANewMomentAreProposals(): void {
		$event = new ObjectCreatingEvent(OrEntityFactory::make(self::visit(['proposedAt' => self::MOMENT]), 'bpv-visit'));
		$this->stamp()->handle($event);
		self::assertSame('proposed', $event->getModifiedData()['lifecycle']);

		$event = self::update(
			self::visit(['lifecycle' => 'proposed', 'proposedAt' => self::MOMENT]),
			self::visit(['lifecycle' => 'proposed', 'proposedAt' => '2026-10-14T13:00:00+02:00'])
		);
		$this->stamp()->handle($event);
		self::assertSame('proposed', $event->getModifiedData()['lifecycle']);
	}//end testANewVisitWithAMomentAndANewMomentAreProposals()

	/**
	 * The company's answer saves the visit with the same moment: that is no
	 * new proposal, so her accept or answer is left as she sent it. A planned
	 * or held visit, a save without a moment and another schema are left
	 * alone too.
	 *
	 * @return void
	 */
	public function testSavesThatProposeNothingAreLeftAlone(): void {
		$cases = [
			'answer keeps the moment' => self::update(
				self::visit(['lifecycle' => 'proposed', 'proposedAt' => self::MOMENT]),
				self::visit(['lifecycle' => 'unplanned', 'proposedAt' => self::MOMENT, 'trainerResponse' => 'Woensdag kan wel.'])
			),
			'planned' => self::update(
				self::visit(['lifecycle' => 'planned', 'proposedAt' => self::MOMENT]),
				self::visit(['lifecycle' => 'planned', 'proposedAt' => '2026-10-20T10:00:00+02:00'])
			),
			'done' => self::update(
				self::visit(['lifecycle' => 'done', 'proposedAt' => self::MOMENT]),
				self::visit(['lifecycle' => 'done', 'proposedAt' => '2026-10-20T10:00:00+02:00'])
			),
			'no moment' => self::update(self::visit(), self::visit(['proposedAt' => '  '])),
		];
		foreach ($cases as $case => $event) {
			$this->stamp()->handle($event);
			self::assertSame([], $event->getModifiedData(), $case);
		}

		$event = self::update(self::visit(), self::visit(['proposedAt' => self::MOMENT]));
		$this->stamp(slug: 'bpv-visit-report')->handle($event);
		self::assertSame([], $event->getModifiedData());

		$event = self::update(self::visit(), self::visit(['proposedAt' => self::MOMENT]));
		$this->stamp(slug: null)->handle($event);
		self::assertSame([], $event->getModifiedData());
	}//end testSavesThatProposeNothingAreLeftAlone()

	/**
	 * A stopped event and an event after the write are not touched.
	 *
	 * @return void
	 */
	public function testStoppedAndAfterTheFactEventsAreIgnored(): void {
		$event = self::update(self::visit(), self::visit(['proposedAt' => self::MOMENT]));
		$event->stopPropagation();
		$this->stamp()->handle($event);
		self::assertSame([], $event->getModifiedData());

		$entity = OrEntityFactory::make(self::visit(['proposedAt' => self::MOMENT]), 'bpv-visit', 'learniq', self::VISIT);
		$after  = new ObjectUpdatedEvent($entity, $entity);
		$this->stamp()->handle($after);
		self::assertSame(self::MOMENT, $entity->getObject()['proposedAt']);
		self::assertSame('unplanned', $entity->getObject()['lifecycle']);
	}//end testStoppedAndAfterTheFactEventsAreIgnored()
}//end class
