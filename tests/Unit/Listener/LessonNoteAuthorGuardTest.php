<?php

/**
 * Tests for LessonNoteAuthorGuard.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/timetabling-lesson-note/specs/personal-timetable/spec.md#requirement-a-teacher-adds-a-note-to-a-lesson
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\AppInfo\Registrar\IntegrityListenerRegistrar;
use OCA\Learniq\Listener\LessonNoteAuthorGuard;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A lesson's own teachers, its substitute and staff write notes on it; nobody else.
 */
class LessonNoteAuthorGuardTest extends TestCase {

	/**
	 * Objects the guard can read, by schema and id.
	 *
	 * @var array<string,array<string,array<string,mixed>>>
	 */
	private array $objects = [
		'cohort' => [
			'cohort-1' => ['id' => 'cohort-1', 'teacherIds' => ['tom']],
			'cohort-2' => ['id' => 'cohort-2', 'teacherIds' => ['other']],
		],
		'session' => [
			's-1' => ['id' => 's-1', 'cohortId' => 'cohort-1', 'substituteTeacherId' => 'eva'],
			's-2' => ['id' => 's-2', 'cohortId' => 'cohort-2', 'substituteTeacherId' => null],
		],
	];

	/**
	 * Build the guard for a caller.
	 *
	 * @param string|null       $uid    The signed-in user, or null for a system write.
	 * @param array<int,string> $groups The caller's groups.
	 * @param string            $slug   The schema the resolver reports.
	 *
	 * @return LessonNoteAuthorGuard
	 */
	private function guard(?string $uid, array $groups = [], string $slug = 'lesson-note'): LessonNoteAuthorGuard {
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('guardSchemaSlug')->willReturn($slug);

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(false);
		$groupManager->method('isInGroup')->willReturnCallback(static fn (string $u, string $group): bool => in_array($group, $groups, true));

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, mixed $register = null, mixed $schema = null): mixed {
				$data = ($this->objects[(string)$schema][(string)$id] ?? null);
				if ($data === null) {
					return null;
				}

				return OrEntityFactory::make($data, (string)$schema);
			}
		);

		return new LessonNoteAuthorGuard(
			schemaResolver: $resolver,
			userSession: $session,
			groupManager: $groupManager,
			objectService: $objectService,
			logger: new NullLogger(),
		);
	}//end guard()

	/**
	 * A create event for a note.
	 *
	 * @param array<string,mixed> $note The note.
	 *
	 * @return ObjectCreatingEvent
	 */
	private function create(array $note): ObjectCreatingEvent {
		return new ObjectCreatingEvent(OrEntityFactory::make($note, 'lesson-note'));
	}//end create()

	/**
	 * The teacher of the lesson's cohort writes a note, and is stamped as author.
	 *
	 * @return void
	 */
	public function testOwnLessonIsAllowed(): void {
		$event = $this->create(['sessionId' => 's-1', 'cohortId' => 'cohort-1', 'text' => 'Hoofdstuk 4', 'audience' => 'learners', 'authorId' => 'someone-else']);

		$this->guard(uid: 'tom')->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame('tom', $event->getObject()->getObject()['authorId']);
	}//end testOwnLessonIsAllowed()

	/**
	 * An instructor who does not teach or cover the lesson is refused.
	 *
	 * @return void
	 */
	public function testOtherCohortTeacherIsRefused(): void {
		$event = $this->create(['sessionId' => 's-1', 'cohortId' => 'cohort-1', 'text' => 'x', 'audience' => 'learners']);

		$this->guard(uid: 'other', groups: ['instructors'])->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame('lesson-note-not-your-lesson', $event->getErrors()['reason']);
	}//end testOtherCohortTeacherIsRefused()

	/**
	 * Naming your own cohort with another cohort's lesson does not get round it.
	 *
	 * @return void
	 */
	public function testOwnCohortWithAnotherCohortsLessonIsRefused(): void {
		$event = $this->create(['sessionId' => 's-2', 'cohortId' => 'cohort-1', 'text' => 'x', 'audience' => 'learners']);

		$this->guard(uid: 'tom')->handle($event);

		self::assertTrue($event->isPropagationStopped());
	}//end testOwnCohortWithAnotherCohortsLessonIsRefused()

	/**
	 * The lesson's substitute writes a note on the lesson they cover.
	 *
	 * @return void
	 */
	public function testSubstituteIsAllowed(): void {
		$event = $this->create(['sessionId' => 's-1', 'cohortId' => 'cohort-1', 'text' => 'Klaar', 'audience' => 'cover']);

		$this->guard(uid: 'eva', groups: ['instructors'])->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testSubstituteIsAllowed()

	/**
	 * A team lead writes on any lesson.
	 *
	 * @return void
	 */
	public function testTeamLeadIsAllowed(): void {
		$event = $this->create(['sessionId' => 's-2', 'cohortId' => 'cohort-2', 'text' => 'x', 'audience' => 'cover']);

		$this->guard(uid: 'lead', groups: ['team-leads'])->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testTeamLeadIsAllowed()

	/**
	 * A note on a planninq lesson is checked against the cohort's teachers.
	 *
	 * @return void
	 */
	public function testPlanninqLessonUsesCohortTeachers(): void {
		$ref = ['sourceSystem' => 'roster-zermelo', 'externalRef' => 'zm-1'];
		$allowed = $this->create(['timetableSessionRef' => $ref, 'cohortId' => 'cohort-1', 'text' => 'x', 'audience' => 'learners']);
		$refused = $this->create(['timetableSessionRef' => $ref, 'cohortId' => 'cohort-1', 'text' => 'x', 'audience' => 'learners']);

		$this->guard(uid: 'tom')->handle($allowed);
		$this->guard(uid: 'other')->handle($refused);

		self::assertFalse($allowed->isPropagationStopped());
		self::assertTrue($refused->isPropagationStopped());
	}//end testPlanninqLessonUsesCohortTeachers()

	/**
	 * An update keeps the original author and is checked the same way.
	 *
	 * @return void
	 */
	public function testUpdateKeepsTheAuthorAndIsGuarded(): void {
		$old = OrEntityFactory::make(['sessionId' => 's-1', 'cohortId' => 'cohort-1', 'text' => 'x', 'audience' => 'learners', 'authorId' => 'tom'], 'lesson-note');
		$new = OrEntityFactory::make(['sessionId' => 's-1', 'cohortId' => 'cohort-1', 'text' => 'y', 'audience' => 'learners', 'authorId' => 'eva'], 'lesson-note');
		$event = new ObjectUpdatingEvent($new, $old);

		$this->guard(uid: 'eva')->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame('tom', $event->getNewObject()->getObject()['authorId']);

		$refused = new ObjectUpdatingEvent(OrEntityFactory::make(['sessionId' => 's-1', 'cohortId' => 'cohort-1', 'text' => 'z', 'audience' => 'learners'], 'lesson-note'), $old);
		$this->guard(uid: 'other')->handle($refused);
		self::assertTrue($refused->isPropagationStopped());
	}//end testUpdateKeepsTheAuthorAndIsGuarded()

	/**
	 * Other schemas and system writes pass untouched.
	 *
	 * @return void
	 */
	public function testOtherSchemasAndSystemWritesPass(): void {
		$other = $this->create(['cohortId' => 'cohort-1']);
		$this->guard(uid: 'other', slug: 'session')->handle($other);
		self::assertFalse($other->isPropagationStopped());

		$system = $this->create(['sessionId' => 's-1', 'cohortId' => 'cohort-1', 'text' => 'x', 'audience' => 'learners']);
		$this->guard(uid: null)->handle($system);
		self::assertFalse($system->isPropagationStopped());
	}//end testOtherSchemasAndSystemWritesPass()

	/**
	 * The registrar wires the guard on create and on update.
	 *
	 * @return void
	 */
	public function testRegistrarWiresTheGuardOnCreateAndUpdate(): void {
		$wired = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$wired): void {
				$wired[] = $event . ' => ' . $listener;
			}
		);

		(new IntegrityListenerRegistrar())->register(context: $context);

		self::assertContains(ObjectCreatingEvent::class . ' => ' . LessonNoteAuthorGuard::class, $wired);
		self::assertContains(ObjectUpdatingEvent::class . ' => ' . LessonNoteAuthorGuard::class, $wired);
	}//end testRegistrarWiresTheGuardOnCreateAndUpdate()
}//end class
