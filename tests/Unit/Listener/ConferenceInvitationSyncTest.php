<?php

/**
 * ConferenceInvitationSync test.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/guardian-tasks-per-child-and-self-assessment/specs/parent-conferences/spec.md#requirement-the-guardian-gets-one-task-per-child-per-open-conference-round
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\AppInfo\Registrar\CollaborationListenerRegistrar;
use OCA\Learniq\Listener\ConferenceInvitationSync;
use OCA\Learniq\Repair\BackfillConferenceInvitations;
use OCA\Learniq\Service\ConferenceInvitations;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The guardian books a time, cancels it, or the school opens a round: the
 * child's invitation row follows.
 *
 * @spec openspec/changes/guardian-tasks-per-child-and-self-assessment/specs/parent-conferences/spec.md#requirement-the-guardian-gets-one-task-per-child-per-open-conference-round
 */
class ConferenceInvitationSyncTest extends TestCase {

	private const TENANT = '00000000-0000-4000-8000-000000000000';

	private const ROUND = 'ac000001-0000-4000-8000-000000000001';

	private const SLOT = 'ac000002-0000-4000-8000-000000000001';

	private const VERA = 'ac000003-0000-4000-8000-000000000001';

	private const SAMI = 'ac000003-0000-4000-8000-000000000002';

	private RegisterFaithfulStore $store;

	/**
	 * An open round for Vera and Sami, with one free time.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new RegisterFaithfulStore();
		$this->store->rows['conference-round'] = [$this->round()];
		$this->store->rows['conference-slot'] = [$this->slot(state: 'free')];
	}//end setUp()

	/**
	 * The school opens the round: both children get an open row.
	 *
	 * @return void
	 */
	public function testOpeningARoundAsksEachChild(): void {
		$draft = array_merge($this->round(), ['lifecycle' => 'invitations-sent']);
		$this->listener()->handle(new ObjectUpdatedEvent(OrEntityFactory::make($this->round(), 'conference-round'), OrEntityFactory::make($draft, 'conference-round')));

		self::assertSame([self::VERA => 'open', self::SAMI => 'open'], $this->statuses());
	}//end testOpeningARoundAsksEachChild()

	/**
	 * The guardian books Sami's time: his row is booked, Vera's stays open.
	 * She cancels it: his row is open again.
	 *
	 * @return void
	 */
	public function testABookedTimeEndsTheTaskAndACancelBringsItBack(): void {
		$this->listener()->handle(new ObjectCreatedEvent(OrEntityFactory::make($this->round(), 'conference-round')));
		$booked = $this->slot(state: 'booked', child: self::SAMI);
		$this->store->rows['conference-slot'] = [$booked];
		$this->listener()->handle(new ObjectUpdatedEvent(OrEntityFactory::make($booked, 'conference-slot'), OrEntityFactory::make($this->slot(state: 'free'), 'conference-slot')));
		self::assertSame([self::VERA => 'open', self::SAMI => 'booked'], $this->statuses());

		$cancelled = $this->slot(state: 'cancelled', child: self::SAMI);
		$this->store->rows['conference-slot'] = [$cancelled];
		$this->listener()->handle(new ObjectUpdatedEvent(OrEntityFactory::make($cancelled, 'conference-slot'), OrEntityFactory::make($booked, 'conference-slot')));
		self::assertSame([self::VERA => 'open', self::SAMI => 'open'], $this->statuses());
	}//end testABookedTimeEndsTheTaskAndACancelBringsItBack()

	/**
	 * A free time written, a round change that touches nothing an invitation
	 * reads, and another schema leave the rows alone.
	 *
	 * @return void
	 */
	public function testOtherWritesAreLeftAlone(): void {
		$this->listener()->handle(new ObjectCreatedEvent(OrEntityFactory::make($this->slot(state: 'free'), 'conference-slot')));
		$moved = array_merge($this->round(), ['slotDurationMinutes' => 15]);
		$this->listener()->handle(new ObjectUpdatedEvent(OrEntityFactory::make($moved, 'conference-round'), OrEntityFactory::make($this->round(), 'conference-round')));
		$this->listener()->handle(new ObjectCreatedEvent(OrEntityFactory::make(['id' => 'x', 'learnerRef' => self::VERA, 'conferenceRoundId' => self::ROUND], 'conference-signup')));

		self::assertSame([], $this->store->saves);
	}//end testOtherWritesAreLeftAlone()

	/**
	 * The repair writes the rows of every open round once; a second run
	 * writes nothing.
	 *
	 * @return void
	 */
	public function testTheRepairBackfillsOpenRoundsOnce(): void {
		$repair = new BackfillConferenceInvitations($this->objectService(), new ConferenceInvitations($this->objectService()), new NullLogger());
		$output = $this->createMock(IOutput::class);

		$repair->run($output);
		self::assertSame([self::VERA => 'open', self::SAMI => 'open'], $this->statuses());

		$saves = count($this->store->saves);
		$repair->run($output);
		self::assertCount($saves, $this->store->saves);
	}//end testTheRepairBackfillsOpenRoundsOnce()

	/**
	 * The listener is wired after creates and updates, from the registrar the
	 * app runs, and the repair step is listed in info.xml.
	 *
	 * @return void
	 */
	public function testTheListenerAndTheRepairAreWired(): void {
		$wired = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$wired): void {
				$wired[] = [$event, $listener];
			}
		);

		(new CollaborationListenerRegistrar())->register($context);

		self::assertContains([ObjectCreatedEvent::class, ConferenceInvitationSync::class], $wired);
		self::assertContains([ObjectUpdatedEvent::class, ConferenceInvitationSync::class], $wired);
		self::assertStringContainsString(
			'<step>OCA\Learniq\Repair\BackfillConferenceInvitations</step>',
			(string)file_get_contents(__DIR__.'/../../../appinfo/info.xml')
		);
	}//end testTheListenerAndTheRepairAreWired()

	/**
	 * The invitation statuses by child.
	 *
	 * @return array<string, string>
	 */
	private function statuses(): array {
		$statuses = array_column(($this->store->rows['conference-invitation'] ?? []), 'status', 'learnerRef');
		ksort($statuses);

		return $statuses;
	}//end statuses()

	/**
	 * The open round.
	 *
	 * @return array<string, mixed>
	 */
	private function round(): array {
		return [
			'id' => self::ROUND,
			'name' => 'Oudergesprekken groep 4, oktober 2026',
			'bookingClosesAt' => '2026-10-16T17:00:00+02:00',
			'bookingMode' => 'direct',
			'lifecycle' => 'booking-open',
			'invitedLearnerRefs' => [self::VERA, self::SAMI],
			'tenant_id' => self::TENANT,
		];
	}//end round()

	/**
	 * The round's one time.
	 *
	 * @param string $state The slot state.
	 * @param string|null $child Who holds it.
	 *
	 * @return array<string, mixed>
	 */
	private function slot(string $state, ?string $child=null): array {
		$slot = [
			'id' => self::SLOT,
			'conferenceRoundId' => self::ROUND,
			'teacherId' => 'po-leerkracht-04',
			'startsAt' => '2026-10-29T18:00:00+01:00',
			'endsAt' => '2026-10-29T18:10:00+01:00',
			'eligibleLearnerRefs' => [self::VERA, self::SAMI],
			'lifecycle' => $state,
			'tenant_id' => self::TENANT,
		];
		if ($child !== null) {
			$slot['learnerRef'] = $child;
		}

		return $slot;
	}//end slot()

	/**
	 * The listener over the in-memory register.
	 *
	 * @return ConferenceInvitationSync
	 */
	private function listener(): ConferenceInvitationSync {
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('guardSchemaSlug')->willReturnCallback(static fn ($entity): string => (string)$entity->getSchema());

		return new ConferenceInvitationSync($resolver, $this->objectService(), new ConferenceInvitations($this->objectService()), new NullLogger());
	}//end listener()

	/**
	 * An ObjectService over the in-memory register.
	 *
	 * @return ObjectService
	 */
	private function objectService(): ObjectService {
		$store = $this->store;
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			static fn (array $config, bool $_rbac=true, bool $_multitenancy=true): array => $store->findAll($config, $_rbac, $_multitenancy)
		);
		$objectService->method('saveObject')->willReturnCallback(
			static fn (array $object, ?array $extend=[], $register=null, $schema=null, ?string $uuid=null) => $store->save((string)$schema, $object, $uuid, false)
		);
		$objectService->method('find')->willReturnCallback(
			static function (int|string $id, ?array $_extend=[], bool $files=false, $register=null, $schema=null) use ($store) {
				foreach (($store->rows[(string)$schema] ?? []) as $row) {
					if ($row['id'] === $id) {
						return OrEntityFactory::make($row, (string)$schema);
					}
				}

				return null;
			}
		);

		return $objectService;
	}//end objectService()
}//end class
