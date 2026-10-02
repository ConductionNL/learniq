<?php

/**
 * ConferenceSlotBookingStamp and ConferenceSlotClaim test.
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
use OCA\Learniq\Listener\ConferenceSlotBookingStamp;
use OCA\Learniq\Service\ConferenceSlotClaim;
use OCA\Learniq\Service\LearnerRefResolver;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use OCA\Learniq\Tests\Support\RegisterSchemaPayloads;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A guardian books a free time from the portal: the slot is claimed for
 * their own child only, once, and never by two families.
 */
class ConferenceSlotBookingStampTest extends TestCase {

	use RegisterSchemaPayloads;

	private const TENANT = '00000000-0000-4000-8000-000000000000';

	private const ROUND_1 = 'aa000001-0000-4000-8000-000000000001';
	private const SLOT_1 = 'aa000002-0000-4000-8000-000000000001';
	private const SLOT_2 = 'aa000002-0000-4000-8000-000000000002';
	private const VERA = 'aa000004-0000-4000-8000-000000000001';
	private const DAAN = 'aa000004-0000-4000-8000-000000000002';
	private const FATIMA = 'aa000005-0000-4000-8000-000000000001';
	private const MARK = 'aa000005-0000-4000-8000-000000000002';

	private RegisterFaithfulStore $store;

	private InMemoryLocks $locks;

	/**
	 * Called while the claim reads the slot, to let a second request arrive
	 * at that exact moment.
	 *
	 * @var (callable(): void)|null
	 */
	private $onSlotRead = null;

	/**
	 * How often a slot was read.
	 */
	private int $slotReads = 0;

	/**
	 * Two families in group 7, a direct round open for booking, one free time.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->locks = new InMemoryLocks();
		$this->store = new RegisterFaithfulStore();
		$this->store->rows['learner-profile'] = [
			['id' => self::VERA, 'ncUserId' => 'po-leerling-147', 'guardianRefs' => [self::FATIMA], 'tenant_id' => self::TENANT],
			['id' => self::DAAN, 'ncUserId' => 'po-leerling-143', 'guardianRefs' => [self::MARK], 'tenant_id' => self::TENANT],
			['id' => self::FATIMA, 'ncUserId' => 'po-ouder-009', 'tenant_id' => self::TENANT],
			['id' => self::MARK, 'ncUserId' => 'po-ouder-010', 'tenant_id' => self::TENANT],
		];
		$this->store->rows['conference-round'] = [
			[
				'id' => self::ROUND_1,
				'bookingMode' => 'direct',
				'lifecycle' => 'booking-open',
				'invitedLearnerRefs' => [self::VERA, self::DAAN],
				'bookingClosesAt' => '2099-01-01T17:00:00+01:00',
				'tenant_id' => self::TENANT,
			],
		];
		$this->store->rows['conference-slot'] = [
			$this->freeSlot(id: self::SLOT_1, startsAt: '2026-10-08T18:00:00+02:00'),
			$this->freeSlot(id: self::SLOT_2, startsAt: '2026-10-08T18:12:00+02:00'),
		];
	}//end setUp()

	/**
	 * A guardian books a free time for her own child: the slot becomes
	 * `booked` for that child and guardian, pointing at the booking, and the
	 * booking carries the time and `booked`.
	 *
	 * @return void
	 */
	public function testAGuardianBooksAFreeTimeForHerOwnChild(): void {
		$event = $this->booking(child: self::VERA, guardian: self::FATIMA, slot: self::SLOT_1);

		$this->stamp()->handle($event);

		$this->assertFalse($event->isPropagationStopped(), json_encode($event->getErrors()));
		$data = $event->getModifiedData();
		$this->assertSame('booked', $data['lifecycle']);
		$this->assertSame(self::ROUND_1, $data['conferenceRoundId']);
		$this->assertSame('po-leerling-147', $data['learnerId']);
		$this->assertSame('po-ouder-009', $data['guardianId']);
		$this->assertSame(['po-leerkracht-09'], $data['requestedTeacherIds']);
		$this->assertSame('2026-10-08T18:00:00+02:00', $data['startsAt']);
		$this->assertSame(self::TENANT, $data['tenant_id']);

		$slot = $this->slot(id: self::SLOT_1);
		$this->assertSame('booked', $slot['lifecycle']);
		$this->assertSame(self::VERA, $slot['learnerRef']);
		$this->assertSame('po-leerling-147', $slot['learnerId']);
		$this->assertSame(self::FATIMA, $slot['guardianRef']);
		$this->assertSame($event->getObject()->getUuid(), $slot['signupId']);
		$this->assertNotSame('', (string)$slot['signupId']);
		$this->assertSame([], $this->locks->held, 'every lock is released');

		$this->assertNull(
			self::schemaError('conference-signup', ['learnerRef' => self::VERA, 'guardianRef' => self::FATIMA, 'slotId' => self::SLOT_1, 'notes' => 'Graag over rekenen']),
			'the real fragment accepts the booking as portaliq sends it, before the stamp (OpenRegister validates first)'
		);
		$this->assertNull(self::schemaError('conference-slot', $this->store->saves[0]['object']), 'the real fragment accepts the booked slot');
		$this->assertNull(
			self::schemaError('conference-signup', array_merge($event->getObject()->getObject(), $data)),
			'the real fragment accepts the stamped booking'
		);
	}//end testAGuardianBooksAFreeTimeForHerOwnChild()

	/**
	 * A guardian cannot book for a child that does not list her, and nothing
	 * is written.
	 *
	 * @return void
	 */
	public function testAGuardianCannotBookForAnotherFamilysChild(): void {
		$event = $this->booking(child: self::DAAN, guardian: self::FATIMA, slot: self::SLOT_1);

		$this->stamp()->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame('signup-guardian-unknown', $event->getErrors()['reason']);
		$this->assertSame('free', $this->slot(id: self::SLOT_1)['lifecycle']);
		$this->assertSame([], $this->store->saves);
	}//end testAGuardianCannotBookForAnotherFamilysChild()

	/**
	 * A time offered to other pupils, a closed window, or a round where the
	 * school plans the times is refused.
	 *
	 * @return void
	 */
	public function testATimeOutsideTheChildsOfferOrAClosedOrPlannedRoundIsRefused(): void {
		$this->store->rows['conference-slot'][0]['eligibleLearnerRefs'] = [self::DAAN];
		$notOffered = $this->booking(child: self::VERA, guardian: self::FATIMA, slot: self::SLOT_1);
		$this->stamp()->handle($notOffered);
		$this->assertSame('slot-unknown', $notOffered->getErrors()['reason']);

		$this->store->rows['conference-round'][0]['bookingClosesAt'] = '2020-01-01T17:00:00+01:00';
		$late = $this->booking(child: self::VERA, guardian: self::FATIMA, slot: self::SLOT_2);
		$this->stamp()->handle($late);
		$this->assertSame('slot-round-closed', $late->getErrors()['reason']);

		$this->store->rows['conference-round'][0]['bookingClosesAt'] = '2099-01-01T17:00:00+01:00';
		$this->store->rows['conference-round'][0]['bookingMode'] = 'preference';
		$planned = $this->booking(child: self::VERA, guardian: self::FATIMA, slot: self::SLOT_2);
		$this->stamp()->handle($planned);
		$this->assertSame('slot-round-closed', $planned->getErrors()['reason']);

		$this->assertSame([], $this->store->saves);
	}//end testATimeOutsideTheChildsOfferOrAClosedOrPlannedRoundIsRefused()

	/**
	 * Two families pick the same time one after the other: the second is
	 * refused and the time stays with the first.
	 *
	 * @return void
	 */
	public function testTheSecondFamilyOnTheSameTimeIsRefused(): void {
		$first = $this->booking(child: self::VERA, guardian: self::FATIMA, slot: self::SLOT_1);
		$this->stamp()->handle($first);
		$second = $this->booking(child: self::DAAN, guardian: self::MARK, slot: self::SLOT_1);
		$this->stamp()->handle($second);

		$this->assertFalse($first->isPropagationStopped());
		$this->assertTrue($second->isPropagationStopped());
		$this->assertSame('slot-taken', $second->getErrors()['reason']);
		$this->assertSame(self::VERA, $this->slot(id: self::SLOT_1)['learnerRef']);
		$this->assertCount(1, $this->store->saves);
	}//end testTheSecondFamilyOnTheSameTimeIsRefused()

	/**
	 * Two families book the same time at the same moment: the second request
	 * arrives while the first has read the slot as free and not yet written
	 * it. The lock refuses the second; the first books.
	 *
	 * @return void
	 */
	public function testTwoFamiliesAtTheSameMomentNeverBothGetTheTime(): void {
		$second = $this->booking(child: self::DAAN, guardian: self::MARK, slot: self::SLOT_1);
		$stamp = $this->stamp();
		$this->onSlotRead = function () use ($stamp, $second): void {
			$this->onSlotRead = null;
			$stamp->handle($second);
		};

		$first = $this->booking(child: self::VERA, guardian: self::FATIMA, slot: self::SLOT_1);
		$stamp->handle($first);

		$this->assertFalse($first->isPropagationStopped(), json_encode($first->getErrors()));
		$this->assertTrue($second->isPropagationStopped());
		$this->assertSame('slot-taken', $second->getErrors()['reason']);
		$this->assertSame(self::VERA, $this->slot(id: self::SLOT_1)['learnerRef']);
		$this->assertCount(1, $this->store->saves, 'only one family\'s booking was written');
		$this->assertSame([], $this->locks->held);
	}//end testTwoFamiliesAtTheSameMomentNeverBothGetTheTime()

	/**
	 * One booking per child per round, unless the round allows more.
	 *
	 * @return void
	 */
	public function testAChildIsBookedOncePerRoundUnlessTheRoundAllowsMore(): void {
		$this->stamp()->handle($this->booking(child: self::VERA, guardian: self::FATIMA, slot: self::SLOT_1));
		$again = $this->booking(child: self::VERA, guardian: self::FATIMA, slot: self::SLOT_2);
		$this->stamp()->handle($again);
		$this->assertSame('child-already-booked', $again->getErrors()['reason']);
		$this->assertSame('free', $this->slot(id: self::SLOT_2)['lifecycle']);

		$this->store->rows['conference-round'][0]['maxBookingsPerChild'] = 2;
		$allowed = $this->booking(child: self::VERA, guardian: self::FATIMA, slot: self::SLOT_2);
		$this->stamp()->handle($allowed);
		$this->assertFalse($allowed->isPropagationStopped());
		$this->assertSame('booked', $this->slot(id: self::SLOT_2)['lifecycle']);
	}//end testAChildIsBookedOncePerRoundUnlessTheRoundAllowsMore()

	/**
	 * A signed-in write, and a portal signup that names no time (the
	 * preference flow), are left alone.
	 *
	 * @return void
	 */
	public function testASignedInWriteOrAPreferenceSignupIsNotTouched(): void {
		$signedIn = $this->booking(child: self::VERA, guardian: self::FATIMA, slot: self::SLOT_1);
		$this->stamp(signedIn: true)->handle($signedIn);
		$this->assertSame([], $signedIn->getModifiedData());

		$preference = new ObjectCreatingEvent(
			OrEntityFactory::make(['learnerRef' => self::VERA, 'guardianRef' => self::FATIMA, 'conferenceRoundId' => self::ROUND_1], 'conference-signup')
		);
		$this->stamp()->handle($preference);
		$this->assertSame([], $preference->getModifiedData());
		$this->assertFalse($preference->isPropagationStopped());
		$this->assertSame([], $this->store->saves);
	}//end testASignedInWriteOrAPreferenceSignupIsNotTouched()

	/**
	 * The listener is wired for creates, asserted from the registrar the app
	 * actually runs.
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

		$this->assertContains([ObjectCreatingEvent::class, ConferenceSlotBookingStamp::class], $wired);
	}//end testTheRegistrarWiresTheListener()

	/**
	 * A free slot of group 7's teacher, bookable for both children.
	 *
	 * @param string $id The slot uuid.
	 * @param string $startsAt The start.
	 *
	 * @return array<string, mixed>
	 */
	private function freeSlot(string $id, string $startsAt): array {
		return [
			'id' => $id,
			'conferenceRoundId' => self::ROUND_1,
			'teacherId' => 'po-leerkracht-09',
			'teacherName' => 'Anna de Vries',
			'startsAt' => $startsAt,
			'endsAt' => date(DATE_ATOM, (int)strtotime($startsAt) + 600),
			'slotLabel' => '08-10-2026 18:00-18:10, Anna de Vries',
			'eligibleLearnerRefs' => [self::VERA, self::DAAN],
			'tenant_id' => self::TENANT,
			'lifecycle' => 'free',
		];
	}//end freeSlot()

	/**
	 * A slot row from the store.
	 *
	 * @param string $id The slot uuid.
	 *
	 * @return array<string, mixed>
	 */
	private function slot(string $id): array {
		foreach ($this->store->rows['conference-slot'] as $row) {
			if ($row['id'] === $id) {
				return $row;
			}
		}

		$this->fail('No slot ' . $id);
	}//end slot()

	/**
	 * A portal booking create event, as portaliq writes it.
	 *
	 * @param string $child The child's LearnerProfile uuid.
	 * @param string $guardian The guardian reference portaliq stamped.
	 * @param string $slot The picked slot.
	 *
	 * @return ObjectCreatingEvent
	 */
	private function booking(string $child, string $guardian, string $slot): ObjectCreatingEvent {
		$entity = OrEntityFactory::make(['learnerRef' => $child, 'guardianRef' => $guardian, 'slotId' => $slot, 'notes' => 'Graag over rekenen'], 'conference-signup');

		return new ObjectCreatingEvent($entity);
	}//end booking()

	/**
	 * The listener with the real claim, over the in-memory register and locks.
	 *
	 * @param bool $signedIn Whether a Nextcloud user is signed in.
	 *
	 * @return ConferenceSlotBookingStamp
	 */
	private function stamp(bool $signedIn=false): ConferenceSlotBookingStamp {
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('guardSchemaSlug')->willReturn('conference-signup');

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $this->store->findAll($config, $_rbac, $_multitenancy)
		);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null) {
				foreach (($this->store->rows[(string)$schema] ?? []) as $row) {
					if ($row['id'] === $id) {
						if ($schema === 'conference-slot') {
							$this->slotReads++;
							// The first request's second slot read is the claim's,
							// between its check and its write.
							if ($this->onSlotRead !== null && $this->slotReads === 2) {
								($this->onSlotRead)();
							}
						}

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

		return new ConferenceSlotBookingStamp(
			$resolver,
			new LearnerRefResolver(objectService: $objectService),
			$objectService,
			new ConferenceSlotClaim($objectService, $this->locks),
			$session,
			new NullLogger()
		);
	}//end stamp()
}//end class

/**
 * Exclusive locks in memory, with Nextcloud's semantics: acquiring a held
 * lock throws LockedException.
 */
class InMemoryLocks implements ILockingProvider {

	/**
	 * Held lock paths.
	 *
	 * @var array<string, int>
	 */
	public array $held = [];

	/**
	 * {@inheritDoc}
	 */
	public function isLocked(string $path, int $type): bool {
		return isset($this->held[$path]);
	}//end isLocked()

	/**
	 * {@inheritDoc}
	 */
	public function acquireLock(string $path, int $type, ?string $readablePath = null): void {
		if (isset($this->held[$path]) === true) {
			throw new LockedException($path);
		}

		$this->held[$path] = $type;
	}//end acquireLock()

	/**
	 * {@inheritDoc}
	 */
	public function releaseLock(string $path, int $type): void {
		unset($this->held[$path]);
	}//end releaseLock()

	/**
	 * {@inheritDoc}
	 */
	public function changeLock(string $path, int $targetType): void {
		$this->held[$path] = $targetType;
	}//end changeLock()

	/**
	 * {@inheritDoc}
	 */
	public function releaseAll(): void {
		$this->held = [];
	}//end releaseAll()
}//end class
