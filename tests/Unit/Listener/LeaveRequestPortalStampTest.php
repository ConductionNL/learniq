<?php

/**
 * Learniq leave request portal stamp tests.
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
 * @spec openspec/changes/leave-requests-decided-by-the-school/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\Kernel\Contracts\LearnerRefResolverInterface;
use OCA\Learniq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Learniq\Kernel\Contracts\PupilGroupTeachersInterface;
use OCA\Learniq\Listener\LeaveRequestPortalStamp;
use OCA\Learniq\Listener\Registrar\EventListenerWiring;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A guardian asks leave for her own child only; the pupil's teachers are stamped.
 *
 * @covers \OCA\Learniq\Listener\LeaveRequestPortalStamp
 */
class LeaveRequestPortalStampTest extends TestCase {

	private const FATIMA = 'g0000000-0000-4000-8000-000000000001';

	private const VERA = 'b0000000-0000-4000-8000-000000000001';

	private const CLASSMATE = 'b0000000-0000-4000-8000-000000000002';

	/**
	 * The profiles the resolver knows: Vera lists Fatima, the classmate does not.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const PROFILES = [
		self::VERA => ['id' => self::VERA, 'ncUserId' => 'vera', 'guardianRefs' => [self::FATIMA], 'tenant_id' => 't-wilgenboom'],
		self::CLASSMATE => ['id' => self::CLASSMATE, 'ncUserId' => 'mila', 'guardianRefs' => ['g-other'], 'tenant_id' => 't-wilgenboom'],
	];

	/**
	 * The listener over the profiles above.
	 *
	 * @param string                         $slug     What the schema resolver answers.
	 * @param PupilGroupTeachersInterface|null $teachers The group teachers, or a resolver answering ['daan'].
	 * @param LearnerRefResolverInterface|null $profiles The profiles, or the ones above.
	 *
	 * @return LeaveRequestPortalStamp
	 */
	private function stamp(string $slug = 'leave-request', ?PupilGroupTeachersInterface $teachers = null, ?LearnerRefResolverInterface $profiles = null): LeaveRequestPortalStamp {
		$schemaResolver = $this->createMock(ListenerSchemaResolverInterface::class);
		$schemaResolver->method('guardSchemaSlug')->willReturn($slug);

		if ($profiles === null) {
			$profiles = $this->createMock(LearnerRefResolverInterface::class);
			$profiles->method('byRef')->willReturnCallback(static fn (string $learnerRef): ?array => (self::PROFILES[$learnerRef] ?? null));
		}

		if ($teachers === null) {
			$teachers = $this->createMock(PupilGroupTeachersInterface::class);
			$teachers->method('forLearner')->willReturnCallback(static fn (string $learnerId): array => ($learnerId === 'vera' ? ['daan'] : []));
		}

		return new LeaveRequestPortalStamp(
			schemaResolver: $schemaResolver,
			profiles: $profiles,
			groupTeachers: $teachers,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end stamp()

	/**
	 * Fatima's request for Vera, as portaliq writes it: her own uuid stamped into requestedByRef.
	 *
	 * @param string $learnerRef The child the form names.
	 *
	 * @return ObjectCreatingEvent
	 */
	private static function request(string $learnerRef = self::VERA): ObjectCreatingEvent {
		return new ObjectCreatingEvent(
			OrEntityFactory::make(
				[
					'learnerRef' => $learnerRef,
					'requestedByRef' => self::FATIMA,
					'dateFrom' => '2026-11-06',
					'dateTo' => '2026-11-06',
					'kind' => 'family-circumstance',
					'reason' => 'Familiefeest',
				],
				'leave-request'
			)
		);
	}//end request()

	/**
	 * Fatima asks leave for Vera: it is kept, with Vera's group teacher and school stamped.
	 *
	 * @return void
	 */
	public function testAGuardianAsksLeaveForHerOwnChild(): void {
		$event = self::request();

		$this->stamp()->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame(['learnerRef' => self::VERA, 'teacherIds' => ['daan'], 'tenant_id' => 't-wilgenboom'], $event->getModifiedData());
	}//end testAGuardianAsksLeaveForHerOwnChild()

	/**
	 * A request that names a classmate of Vera is refused and nothing is stamped.
	 *
	 * @return void
	 */
	public function testARequestForAClassmateIsRefused(): void {
		$event = self::request(learnerRef: self::CLASSMATE);

		$this->stamp()->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame(['reason' => 'leave-guardian-unknown', 'message' => 'Verlof vragen kan alleen voor uw eigen kind.'], $event->getErrors());
		self::assertSame([], $event->getModifiedData());
	}//end testARequestForAClassmateIsRefused()

	/**
	 * An unknown pupil, or a lookup that fails, refuses the write rather than letting it through.
	 *
	 * @return void
	 */
	public function testAnUnknownPupilOrAFailedLookupIsRefused(): void {
		$unknown = self::request(learnerRef: 'nobody');
		$this->stamp()->handle($unknown);
		self::assertTrue($unknown->isPropagationStopped());
		self::assertSame('leave-learner-unknown', $unknown->getErrors()['reason']);

		$broken = $this->createMock(LearnerRefResolverInterface::class);
		$broken->method('byRef')->willThrowException(new RuntimeException('register offline'));
		$failed = self::request();
		$this->stamp(profiles: $broken)->handle($failed);
		self::assertTrue($failed->isPropagationStopped());
		self::assertSame(LeaveRequestPortalStamp::REFUSALS['leave-lookup-failed'], $failed->getErrors()['message']);
		self::assertSame([], $failed->getModifiedData());
	}//end testAnUnknownPupilOrAFailedLookupIsRefused()

	/**
	 * A member of staff records a request without requestedByRef; teachers that cannot be resolved stamp nobody.
	 *
	 * @return void
	 */
	public function testAStaffRequestIsKeptAndUnresolvedTeachersStampNobody(): void {
		$teachers = $this->createMock(PupilGroupTeachersInterface::class);
		$teachers->method('forLearner')->willThrowException(new RuntimeException('groups offline'));
		$event = new ObjectCreatingEvent(OrEntityFactory::make(['learnerRef' => self::CLASSMATE, 'dateFrom' => '2026-11-06', 'dateTo' => '2026-11-06', 'kind' => 'other', 'reason' => 'x'], 'leave-request'));

		$this->stamp(teachers: $teachers)->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame(['learnerRef' => self::CLASSMATE, 'teacherIds' => [], 'tenant_id' => 't-wilgenboom'], $event->getModifiedData());
	}//end testAStaffRequestIsKeptAndUnresolvedTeachersStampNobody()

	/**
	 * Another schema, a stopped write and an update are left alone.
	 *
	 * @return void
	 */
	public function testOtherWritesAreLeftAlone(): void {
		$other = self::request(learnerRef: self::CLASSMATE);
		$this->stamp(slug: 'excuse-request')->handle($other);
		self::assertFalse($other->isPropagationStopped());
		self::assertSame([], $other->getModifiedData());

		$stopped = self::request(learnerRef: self::CLASSMATE);
		$stopped->stopPropagation();
		$this->stamp()->handle($stopped);
		self::assertSame([], $stopped->getErrors());

		$stored = OrEntityFactory::make(['learnerRef' => self::CLASSMATE, 'requestedByRef' => self::FATIMA], 'leave-request');
		$update = new ObjectUpdatingEvent($stored, $stored);
		$this->stamp()->handle($update);
		self::assertFalse($update->isPropagationStopped());
	}//end testOtherWritesAreLeftAlone()

	/**
	 * The check is wired on create.
	 *
	 * @return void
	 */
	public function testTheCheckIsRegisteredOnCreate(): void {
		$registered = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$registered): void {
				$registered[] = $event . ' => ' . $listener;
			}
		);

		(new EventListenerWiring())->registerAll(context: $context);

		self::assertContains(ObjectCreatingEvent::class . ' => ' . LeaveRequestPortalStamp::class, $registered);
		self::assertNotContains(ObjectUpdatingEvent::class . ' => ' . LeaveRequestPortalStamp::class, $registered);
	}//end testTheCheckIsRegisteredOnCreate()
}//end class
