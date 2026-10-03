<?php

/**
 * ConferenceSlotBookingSync test.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Listener
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
 *
 * @spec openspec/changes/direct-conference-booking/specs/parent-conferences/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\AppInfo\Registrar\CollaborationListenerRegistrar;
use OCA\Learniq\Listener\ConferenceSlotBookingSync;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IUser;
use OCP\IUserSession;
use OCA\Learniq\Tests\Support\RegisterSchemaPayloads;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The teacher acknowledges or declines a booked time, the parent cancels it:
 * the booking follows, and a released time is free again.
 */
class ConferenceSlotBookingSyncTest extends TestCase {

	use RegisterSchemaPayloads;

	private const TENANT = '00000000-0000-4000-8000-000000000000';

	private const ROUND_1 = 'aa000001-0000-4000-8000-000000000001';
	private const SLOT_1 = 'aa000002-0000-4000-8000-000000000001';
	private const SIGNUP_1 = 'aa000003-0000-4000-8000-000000000001';
	private const VERA = 'aa000004-0000-4000-8000-000000000001';
	private const DAAN = 'aa000004-0000-4000-8000-000000000002';
	private const FATIMA = 'aa000005-0000-4000-8000-000000000001';

	private RegisterFaithfulStore $store;

	/**
	 * A direct round open for booking, Vera's booking and its slot.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new RegisterFaithfulStore();
		$this->store->rows['conference-round'] = [
			['id' => self::ROUND_1, 'bookingMode' => 'direct', 'lifecycle' => 'booking-open', 'bookingClosesAt' => '2099-01-01T17:00:00+01:00', 'tenant_id' => self::TENANT],
		];
		$this->store->rows['conference-signup'] = [
			['id' => self::SIGNUP_1, 'conferenceRoundId' => self::ROUND_1, 'learnerRef' => self::VERA, 'guardianRef' => self::FATIMA, 'slotId' => self::SLOT_1, 'lifecycle' => 'booked', 'tenant_id' => self::TENANT],
		];
		$this->store->rows['conference-slot'] = [$this->bookedSlot()];
	}//end setUp()

	/**
	 * The teacher declines with a note: the booking is declined with that
	 * note for the parent to read, and the same time is free again as a new
	 * slot, while the declined slot keeps who booked it.
	 *
	 * @return void
	 */
	public function testADeclinedTimeTellsTheParentWhyAndIsFreeAgain(): void {
		$declined = array_merge($this->bookedSlot(), ['lifecycle' => 'declined', 'declineNote' => 'Ik ben die avond ziek.']);

		$this->sync()->handle($this->updated(old: $this->bookedSlot(), new: $declined));

		$signup = $this->store->rows['conference-signup'][0];
		$this->assertSame('declined', $signup['lifecycle']);
		$this->assertSame('Ik ben die avond ziek.', $signup['declineNote']);

		$free = $this->savedSlots();
		$this->assertCount(1, $free);
		$this->assertSame('free', $free[0]['lifecycle']);
		$this->assertSame('2026-10-08T18:00:00+02:00', $free[0]['startsAt']);
		$this->assertSame('po-leerkracht-09', $free[0]['teacherId']);
		$this->assertSame([self::VERA, self::DAAN], $free[0]['eligibleLearnerRefs']);
		$this->assertArrayNotHasKey('learnerRef', $free[0]);
		$this->assertArrayNotHasKey('guardianRef', $free[0]);

		foreach ($this->store->saves as $save) {
			$this->assertNull(self::schemaError($save['schema'], $save['object']), 'the real fragment accepts what the sync wrote');
		}
	}//end testADeclinedTimeTellsTheParentWhyAndIsFreeAgain()

	/**
	 * The teacher acknowledges: the moment is stamped before the write, and
	 * the booking follows after it. Nothing is freed.
	 *
	 * @return void
	 */
	public function testAnAcknowledgedTimeIsStampedAndTheBookingFollows(): void {
		$acknowledged = array_merge($this->bookedSlot(), ['lifecycle' => 'acknowledged']);
		$before = $this->updating(old: $this->bookedSlot(), new: $acknowledged);

		$this->sync(signedIn: true)->handle($before);
		$this->assertArrayHasKey('acknowledgedAt', $before->getModifiedData());

		$this->sync(signedIn: true)->handle($this->updated(old: $this->bookedSlot(), new: $acknowledged));
		$this->assertSame('acknowledged', $this->store->rows['conference-signup'][0]['lifecycle']);
		$this->assertSame([], $this->savedSlots());
	}//end testAnAcknowledgedTimeIsStampedAndTheBookingFollows()

	/**
	 * The parent cancels from the portal while booking is open: allowed, the
	 * booking is cancelled and the time is free again.
	 *
	 * @return void
	 */
	public function testAParentCancelsWhileBookingIsOpen(): void {
		$cancelled = array_merge($this->bookedSlot(), ['lifecycle' => 'cancelled']);
		$before = $this->updating(old: $this->bookedSlot(), new: $cancelled);
		$this->sync()->handle($before);
		$this->assertFalse($before->isPropagationStopped());

		$this->sync()->handle($this->updated(old: $this->bookedSlot(), new: $cancelled));
		$this->assertSame('cancelled', $this->store->rows['conference-signup'][0]['lifecycle']);
		$this->assertCount(1, $this->savedSlots());
	}//end testAParentCancelsWhileBookingIsOpen()

	/**
	 * After the booking window closes, a portal cancel is refused. The
	 * school, signed in, can still cancel.
	 *
	 * @return void
	 */
	public function testAPortalCancelAfterTheWindowIsRefused(): void {
		$this->store->rows['conference-round'][0]['bookingClosesAt'] = '2020-01-01T17:00:00+01:00';
		$cancelled = array_merge($this->bookedSlot(), ['lifecycle' => 'cancelled']);

		$portal = $this->updating(old: $this->bookedSlot(), new: $cancelled);
		$this->sync()->handle($portal);
		$this->assertTrue($portal->isPropagationStopped());
		$this->assertSame('cancel-window-closed', $portal->getErrors()['reason']);

		$school = $this->updating(old: $this->bookedSlot(), new: $cancelled);
		$this->sync(signedIn: true)->handle($school);
		$this->assertFalse($school->isPropagationStopped());
	}//end testAPortalCancelAfterTheWindowIsRefused()

	/**
	 * A released time after booking closed is not offered again, and a round
	 * where the school plans the times is left alone.
	 *
	 * @return void
	 */
	public function testNoFreeTimeAfterClosingAndNothingInAPreferenceRound(): void {
		$declined = array_merge($this->bookedSlot(), ['lifecycle' => 'declined', 'declineNote' => 'Ziek.']);
		$this->store->rows['conference-round'][0]['lifecycle'] = 'booking-closed';
		$this->sync(signedIn: true)->handle($this->updated(old: $this->bookedSlot(), new: $declined));
		$this->assertSame('declined', $this->store->rows['conference-signup'][0]['lifecycle']);
		$this->assertSame([], $this->savedSlots());

		$this->setUp();
		$this->store->rows['conference-round'][0]['bookingMode'] = 'preference';
		$this->sync(signedIn: true)->handle($this->updated(old: $this->bookedSlot(), new: $declined));
		$this->assertSame([], $this->store->saves);
	}//end testNoFreeTimeAfterClosingAndNothingInAPreferenceRound()

	/**
	 * The listener is wired before and after updates, from the registrar the
	 * app runs.
	 *
	 * @return void
	 */
	public function testTheRegistrarWiresTheListener(): void {
		$wired = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$wired): void {
				$wired[] = [$event, $listener];
			}
		);

		(new CollaborationListenerRegistrar())->register($context);

		$this->assertContains([ObjectUpdatingEvent::class, ConferenceSlotBookingSync::class], $wired);
		$this->assertContains([ObjectUpdatedEvent::class, ConferenceSlotBookingSync::class], $wired);
	}//end testTheRegistrarWiresTheListener()

	/**
	 * Vera's booked slot.
	 *
	 * @return array<string, mixed>
	 */
	private function bookedSlot(): array {
		return [
			'id' => self::SLOT_1,
			'conferenceRoundId' => self::ROUND_1,
			'teacherId' => 'po-leerkracht-09',
			'teacherName' => 'Anna de Vries',
			'startsAt' => '2026-10-08T18:00:00+02:00',
			'endsAt' => '2026-10-08T18:10:00+02:00',
			'slotLabel' => '08-10-2026 18:00-18:10, Anna de Vries',
			'eligibleLearnerRefs' => [self::VERA, self::DAAN],
			'learnerRef' => self::VERA,
			'learnerId' => 'po-leerling-147',
			'guardianRef' => self::FATIMA,
			'signupId' => self::SIGNUP_1,
			'tenant_id' => self::TENANT,
			'lifecycle' => 'booked',
		];
	}//end bookedSlot()

	/**
	 * The slots this listener wrote.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function savedSlots(): array {
		return array_values(
			array_map(
				static fn (array $save): array => $save['object'],
				array_filter($this->store->saves, static fn (array $save): bool => $save['schema'] === 'conference-slot')
			)
		);
	}//end savedSlots()

	/**
	 * The before-write event.
	 *
	 * @param array<string, mixed> $old The slot before.
	 * @param array<string, mixed> $new The slot after.
	 *
	 * @return ObjectUpdatingEvent
	 */
	private function updating(array $old, array $new): ObjectUpdatingEvent {
		return new ObjectUpdatingEvent(OrEntityFactory::make($new, 'conference-slot'), OrEntityFactory::make($old, 'conference-slot'));
	}//end updating()

	/**
	 * The after-write event.
	 *
	 * @param array<string, mixed> $old The slot before.
	 * @param array<string, mixed> $new The slot after.
	 *
	 * @return ObjectUpdatedEvent
	 */
	private function updated(array $old, array $new): ObjectUpdatedEvent {
		return new ObjectUpdatedEvent(OrEntityFactory::make($new, 'conference-slot'), OrEntityFactory::make($old, 'conference-slot'));
	}//end updated()

	/**
	 * The listener over the in-memory register.
	 *
	 * @param bool $signedIn Whether a Nextcloud user is signed in (the teacher or the school).
	 *
	 * @return ConferenceSlotBookingSync
	 */
	private function sync(bool $signedIn=false): ConferenceSlotBookingSync {
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('guardSchemaSlug')->willReturnCallback(static fn ($entity): string => (string)$entity->getSchema());

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null) {
				foreach (($this->store->rows[(string)$schema] ?? []) as $row) {
					if ($row['id'] === $id) {
						return OrEntityFactory::make($row, (string)$schema);
					}
				}

				return null;
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			fn (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null) => $this->store->save((string)$schema, $object, $uuid)
		);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $this->createMock(IUser::class) : null);

		return new ConferenceSlotBookingSync($resolver, $objectService, $session, new NullLogger());
	}//end sync()
}//end class
