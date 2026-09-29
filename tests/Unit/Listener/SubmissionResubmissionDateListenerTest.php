<?php

/**
 * Learniq SubmissionResubmissionDateListener unit tests.
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
 * @spec openspec/specs/assignments/spec.md#requirement-only-staff-set-a-resubmission-date
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\AppInfo\Registrar\IntegrityListenerRegistrar;
use OCA\Learniq\Listener\SubmissionResubmissionDateListener;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for SubmissionResubmissionDateListener::handle().
 */
class SubmissionResubmissionDateListenerTest extends TestCase {

	private const TEACHER_DATE = '2026-10-05T17:00:00+00:00';
	private const LATER_DATE = '2027-06-30T17:00:00+00:00';

	/**
	 * Build the listener for one caller.
	 *
	 * @param string|null $uid The caller, null for system context.
	 * @param array<int, string> $groups The caller's groups.
	 * @param bool $isAdmin Whether the caller is an admin.
	 * @param string $slug What the schema resolver answers.
	 *
	 * @return SubmissionResubmissionDateListener
	 */
	private function makeListener(?string $uid, array $groups = [], bool $isAdmin = false, string $slug = 'submission'): SubmissionResubmissionDateListener {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($isAdmin);
		$groupManager->method('isInGroup')->willReturnCallback(
			static fn (string $who, string $group): bool => in_array($group, $groups, true)
		);

		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('guardSchemaSlug')->willReturn($slug);

		return new SubmissionResubmissionDateListener(
			schemaResolver: $resolver,
			userSession: $session,
			groupManager: $groupManager,
			logger: new NullLogger(),
		);
	}//end makeListener()

	/**
	 * An update of the learner's own draft.
	 *
	 * @param array<string, mixed> $new New fields.
	 * @param array<string, mixed> $old Stored fields.
	 *
	 * @return ObjectUpdatingEvent
	 */
	private function updating(array $new, array $old): ObjectUpdatingEvent {
		$base = ['id' => 'sub-1', 'assignmentId' => 'asn-1', 'learnerIds' => ['alice'], 'lifecycle' => 'draft'];
		return new ObjectUpdatingEvent(
			OrEntityFactory::make(array_merge($base, $new), 'submission'),
			OrEntityFactory::make(array_merge($base, $old), 'submission')
		);
	}//end updating()

	/**
	 * A learner cannot give themselves a later date; the rest of the write stays.
	 *
	 * @return void
	 */
	public function testALearnerCannotMoveTheirOwnDate(): void {
		$event = $this->updating(
			['resubmissionDueAt' => self::LATER_DATE, 'attachmentRefs' => ['file-1']],
			['resubmissionDueAt' => self::TEACHER_DATE]
		);
		$this->makeListener(uid: 'alice')->handle($event);

		self::assertSame(self::TEACHER_DATE, $event->getModifiedData()['resubmissionDueAt']);
		self::assertArrayNotHasKey('attachmentRefs', $event->getModifiedData());
		self::assertFalse($event->isPropagationStopped());
	}//end testALearnerCannotMoveTheirOwnDate()

	/**
	 * A learner cannot clear the date either.
	 *
	 * @return void
	 */
	public function testALearnerCannotClearTheDate(): void {
		$event = $this->updating(['resubmissionDueAt' => null], ['resubmissionDueAt' => self::TEACHER_DATE]);
		$this->makeListener(uid: 'alice')->handle($event);

		self::assertSame(self::TEACHER_DATE, $event->getModifiedData()['resubmissionDueAt']);
	}//end testALearnerCannotClearTheDate()

	/**
	 * A learner's create carrying a date is stored without it.
	 *
	 * @return void
	 */
	public function testALearnerCannotCreateWorkWithADate(): void {
		$event = new ObjectCreatingEvent(
			OrEntityFactory::make(['assignmentId' => 'asn-1', 'learnerIds' => ['alice'], 'resubmissionDueAt' => self::LATER_DATE], 'submission')
		);
		$this->makeListener(uid: 'alice')->handle($event);

		self::assertArrayHasKey('resubmissionDueAt', $event->getModifiedData());
		self::assertNull($event->getModifiedData()['resubmissionDueAt']);
		self::assertFalse($event->isPropagationStopped());
	}//end testALearnerCannotCreateWorkWithADate()

	/**
	 * A learner's write that leaves the date alone is untouched.
	 *
	 * @return void
	 */
	public function testALearnerWriteWithoutADateChangeIsUntouched(): void {
		$event = $this->updating(
			['resubmissionDueAt' => self::TEACHER_DATE, 'attachmentRefs' => ['file-1']],
			['resubmissionDueAt' => self::TEACHER_DATE]
		);
		$this->makeListener(uid: 'alice')->handle($event);

		self::assertSame([], $event->getModifiedData());
	}//end testALearnerWriteWithoutADateChangeIsUntouched()

	/**
	 * Staff, admins and system context write the date.
	 *
	 * @return void
	 */
	public function testStaffAdminsAndSystemWriteTheDate(): void {
		$listeners = [
			'teacher' => $this->makeListener(uid: 'tom', groups: ['instructors']),
			'team lead' => $this->makeListener(uid: 'tina', groups: ['team-leads']),
			'compliance' => $this->makeListener(uid: 'cor', groups: ['compliance-officers']),
			'admin' => $this->makeListener(uid: 'root', isAdmin: true),
			'system' => $this->makeListener(uid: null),
		];
		foreach ($listeners as $who => $listener) {
			$event = $this->updating(['resubmissionDueAt' => self::LATER_DATE, 'lifecycle' => 'draft'], ['resubmissionDueAt' => null, 'lifecycle' => 'returned']);
			$listener->handle($event);

			self::assertSame([], $event->getModifiedData(), $who . ' must be able to set the date');
		}
	}//end testStaffAdminsAndSystemWriteTheDate()

	/**
	 * Another schema's write is left alone.
	 *
	 * @return void
	 */
	public function testAnotherSchemaIsUntouched(): void {
		$event = new ObjectCreatingEvent(OrEntityFactory::make(['resubmissionDueAt' => self::LATER_DATE], 'assignment'));
		$this->makeListener(uid: 'alice', slug: 'assignment')->handle($event);

		self::assertSame([], $event->getModifiedData());
	}//end testAnotherSchemaIsUntouched()

	/**
	 * The listener is wired on create and update, asserted from the caller.
	 *
	 * @return void
	 */
	public function testTheListenerIsRegisteredForCreateAndUpdate(): void {
		$registered = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$registered): void {
				$registered[] = $event . ' => ' . $listener;
			}
		);

		(new IntegrityListenerRegistrar())->register(context: $context);

		self::assertContains(ObjectCreatingEvent::class . ' => ' . SubmissionResubmissionDateListener::class, $registered);
		self::assertContains(ObjectUpdatingEvent::class . ' => ' . SubmissionResubmissionDateListener::class, $registered);
	}//end testTheListenerIsRegisteredForCreateAndUpdate()
}//end class
