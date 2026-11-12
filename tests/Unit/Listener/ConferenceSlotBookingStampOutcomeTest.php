<?php

/**
 * ConferenceSlotBookingStamp outcome test: every refusal, the full stamp and
 * the order of every lookup, over recording doubles.
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

use OCA\Learniq\Listener\ConferenceSlotBookingStamp;
use OCA\Learniq\Service\ConferenceSlotClaim;
use OCA\Learniq\Service\LearnerRefResolver;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins what ConferenceSlotBookingStamp answers for each portal booking.
 */
class ConferenceSlotBookingStampOutcomeTest extends TestCase {

	/**
	 * Every lookup, claim and log line, in order.
	 *
	 * @var \ArrayObject<int,mixed>
	 */
	private \ArrayObject $calls;

	/**
	 * Profiles by ref.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $profiles = [
		'child' => ['id' => 'child', 'ncUserId' => 'pupil-1', 'guardianRefs' => ['mum']],
		'mum' => ['id' => 'mum', 'ncUserId' => 'parent-1'],
	];

	/**
	 * Rows by "schema:id".
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $rows = [];

	/**
	 * What the claim answers.
	 *
	 * @var array<string,mixed>
	 */
	private array $claimed = [];

	/**
	 * Fresh doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->calls   = new \ArrayObject();
		$this->rows    = [
			'conference-slot:s1' => ['id' => 's1', 'conferenceRoundId' => 'r1'],
			'conference-round:r1' => [
				'id' => 'r1',
				'bookingMode' => 'direct',
				'lifecycle' => 'booking-open',
				'invitedLearnerRefs' => ['child'],
				'tenant_id' => 't1',
			],
		];
		$this->claimed = [
			'slot' => ['teacherId' => 'teacher-9', 'teacherName' => 'Anna', 'startsAt' => '2026-10-08T18:00', 'endsAt' => '2026-10-08T18:10', 'slotLabel' => 'Thu 18:00'],
		];
	}//end setUp()

	/**
	 * The listener over recording doubles.
	 *
	 * @param bool $signedIn Whether a user is signed in.
	 *
	 * @return ConferenceSlotBookingStamp
	 */
	private function stamp(bool $signedIn=false): ConferenceSlotBookingStamp {
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('guardSchemaSlug')->willReturn('conference-signup');

		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null) {
				$this->calls[] = 'find ' . $schema . ' ' . $id;
				if ($id === 'boom') {
					throw new RuntimeException('store down');
				}

				$row = ($this->rows[$schema . ':' . $id] ?? null);
				if ($row === null) {
					return null;
				}

				return OrEntityFactory::make($row, (string)$schema);
			}
		);

		$profiles = $this->createMock(LearnerRefResolver::class);
		$profiles->method('byRef')->willReturnCallback(
			function (string $learnerRef): ?array {
				$this->calls[] = 'profile ' . $learnerRef;
				return ($this->profiles[$learnerRef] ?? null);
			}
		);

		$claim = $this->createMock(ConferenceSlotClaim::class);
		$claim->method('claim')->willReturnCallback(
			function (string $slotId, array $round, array $booking): array {
				$this->calls[] = ['claim', $slotId, $round['id'], $booking];
				return $this->claimed;
			}
		);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $this->createMock(IUser::class) : null);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->calls[] = $level . ' ' . $message . ' ' . json_encode($context);
				}
			);
		}

		return new ConferenceSlotBookingStamp($resolver, $profiles, $objects, $claim, $session, $logger);
	}//end stamp()

	/**
	 * Book through the listener.
	 *
	 * @param array<string,mixed> $payload  The signup as portaliq writes it.
	 * @param bool                $signedIn Whether a user is signed in.
	 *
	 * @return ObjectCreatingEvent
	 */
	private function book(array $payload, bool $signedIn=false): ObjectCreatingEvent {
		$entity = OrEntityFactory::make($payload, 'conference-signup');
		$entity->setUuid('signup-1');
		$event = new ObjectCreatingEvent($entity);
		$this->stamp(signedIn: $signedIn)->handle($event);

		return $event;
	}//end book()

	/**
	 * The refusal a booking gets, with its log line as the last call.
	 *
	 * @param string $reason The expected reason.
	 * @param ObjectCreatingEvent $event The handled event.
	 *
	 * @return void
	 */
	private function assertRefused(string $reason, ObjectCreatingEvent $event): void {
		self::assertTrue($event->isPropagationStopped());
		self::assertSame(['reason' => $reason, 'message' => ConferenceSlotBookingStamp::REFUSALS[$reason]], $event->getErrors());
		self::assertSame('info [ConferenceSlotBookingStamp] Refused a portal booking: {reason} {"reason":"' . $reason . '"}', $this->calls[count($this->calls) - 1]);
	}//end assertRefused()

	/**
	 * A booking for one's own child is claimed with the exact booking and
	 * stamped with every field, in this order of lookups.
	 *
	 * @return void
	 */
	public function testABookingIsClaimedAndStampedExactly(): void {
		$event = $this->book(['learnerRef' => 'child', 'guardianRef' => 'mum', 'slotId' => 's1', 'notes' => 'x']);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame(
			[
				'conferenceRoundId' => 'r1',
				'learnerId' => 'pupil-1',
				'learnerRef' => 'child',
				'guardianId' => 'parent-1',
				'tenant_id' => 't1',
				'requestedTeacherIds' => ['teacher-9'],
				'teacherName' => 'Anna',
				'startsAt' => '2026-10-08T18:00',
				'endsAt' => '2026-10-08T18:10',
				'slotLabel' => 'Thu 18:00',
				'lifecycle' => 'booked',
			],
			$event->getModifiedData()
		);
		self::assertSame(
			[
				'profile child',
				'find conference-slot s1',
				'find conference-round r1',
				['claim', 's1', 'r1', ['learnerRef' => 'child', 'learnerId' => 'pupil-1', 'guardianRef' => 'mum', 'signupId' => 'signup-1']],
				'profile mum',
			],
			$this->calls->getArrayCopy()
		);
	}//end testABookingIsClaimedAndStampedExactly()

	/**
	 * A stamp from sparse rows falls back to empty strings.
	 *
	 * @return void
	 */
	public function testASparseStampFallsBackToEmptyValues(): void {
		$this->profiles = ['child' => ['id' => 'child', 'guardianRefs' => ['mum']]];
		unset($this->rows['conference-round:r1']['tenant_id']);
		$this->claimed = ['slot' => []];

		$data = $this->book(['learnerRef' => 'child', 'guardianRef' => 'mum', 'slotId' => 's1'])->getModifiedData();

		self::assertSame(
			['conferenceRoundId' => 'r1', 'learnerId' => '', 'learnerRef' => 'child', 'guardianId' => '', 'tenant_id' => '', 'requestedTeacherIds' => [''],
				'teacherName' => '', 'startsAt' => '', 'endsAt' => '', 'slotLabel' => '', 'lifecycle' => 'booked'],
			$data
		);
	}//end testASparseStampFallsBackToEmptyValues()

	/**
	 * Every refusal is exact and stops before the next lookup.
	 *
	 * @return void
	 */
	public function testEachRefusalIsExact(): void {
		$this->assertRefused('signup-guardian-unknown', $this->book(['learnerRef' => 'nobody', 'guardianRef' => 'mum', 'slotId' => 's1']));
		self::assertSame(['profile nobody'], array_slice($this->calls->getArrayCopy(), 0, 1));

		$this->setUp();
		$this->assertRefused('signup-guardian-unknown', $this->book(['learnerRef' => 'child', 'guardianRef' => 'dad', 'slotId' => 's1']));

		$this->setUp();
		$this->assertRefused('slot-unknown', $this->book(['learnerRef' => 'child', 'guardianRef' => 'mum', 'slotId' => 's2']));

		$this->setUp();
		$this->rows['conference-round:r1']['lifecycle'] = 'planned';
		$this->assertRefused('slot-round-closed', $this->book(['learnerRef' => 'child', 'guardianRef' => 'mum', 'slotId' => 's1']));
		self::assertCount(4, $this->calls);

		$this->setUp();
		$this->claimed = ['refuse' => 'child-already-booked'];
		$this->assertRefused('child-already-booked', $this->book(['learnerRef' => 'child', 'guardianRef' => 'mum', 'slotId' => 's1']));

		$this->setUp();
		$this->claimed = [];
		$this->assertRefused('slot-taken', $this->book(['learnerRef' => 'child', 'guardianRef' => 'mum', 'slotId' => 's1']));
	}//end testEachRefusalIsExact()

	/**
	 * A lookup that throws is logged and refused; a signed-in write or a
	 * signup without a slot is not touched.
	 *
	 * @return void
	 */
	public function testAFailedLookupAndTheUntouchedWrites(): void {
		$this->assertRefused('slot-lookup-failed', $this->book(['learnerRef' => 'child', 'guardianRef' => 'mum', 'slotId' => 'boom']));
		self::assertSame('warning [ConferenceSlotBookingStamp] Could not check a portal booking: {msg} {"msg":"store down"}', $this->calls[2]);

		$this->setUp();
		$signedIn = $this->book(['learnerRef' => 'child', 'guardianRef' => 'mum', 'slotId' => 's1'], signedIn: true);
		$noSlot   = $this->book(['learnerRef' => 'child', 'guardianRef' => 'mum']);
		self::assertFalse($signedIn->isPropagationStopped());
		self::assertFalse($noSlot->isPropagationStopped());
		self::assertSame([], $signedIn->getModifiedData());
		self::assertSame([], $this->calls->getArrayCopy());

		$stopped = new ObjectCreatingEvent(OrEntityFactory::make(['learnerRef' => 'child', 'guardianRef' => 'mum', 'slotId' => 's1'], 'conference-signup'));
		$stopped->stopPropagation();
		$this->stamp()->handle($stopped);
		self::assertSame([], $this->calls->getArrayCopy());
	}//end testAFailedLookupAndTheUntouchedWrites()
}//end class
