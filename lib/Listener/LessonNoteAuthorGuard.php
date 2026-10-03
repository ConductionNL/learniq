<?php

/**
 * Learniq Lesson Note Author Guard
 *
 * Only a lesson's own teachers write notes on it: a teacher of the lesson's
 * cohort (`Cohort.teacherIds`), the lesson's substitute
 * (`Session.substituteTeacherId`), or a member of `team-leads` or
 * `compliance-officers`. Every other instructor is refused, so one teacher
 * cannot write on another teacher's lessons. This is the check
 * {@see \OCA\Learniq\Lifecycle\SessionChangeGuard} makes for a lesson change,
 * run on the `lesson-note` create and update, because OpenRegister runs no
 * lifecycle guard on create (ADR-031 guard exception: a rule across rows).
 *
 * A note on a learniq lesson must name that lesson's own cohort, so a
 * teacher cannot pass their own cohort with another cohort's lesson. On
 * create the author is stamped from the session, whatever the client sent.
 *
 * @category Listener
 * @package  OCA\Learniq\Listener
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
 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-teacher-adds-a-note-to-a-lesson
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Refuses a lesson note written by someone who does not teach or cover the lesson.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-teacher-adds-a-note-to-a-lesson
 */
class LessonNoteAuthorGuard implements IEventListener {

	/**
	 * Schema slug this listener guards.
	 */
	private const NOTE_SCHEMA = 'lesson-note';

	/**
	 * Groups whose members may write a note on any lesson.
	 */
	private const STAFF_GROUPS = ['team-leads', 'compliance-officers'];

	private const REGISTER = 'learniq';

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolver $schemaResolver Resolves the event entity's schema slug.
	 * @param IUserSession           $userSession    The acting user.
	 * @param IGroupManager          $groupManager   Admin and staff checks.
	 * @param ObjectService          $objectService  Reads the lesson and its cohort.
	 * @param LoggerInterface        $logger         PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an OpenRegister creating or updating event.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-teacher-adds-a-note-to-a-lesson
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === false && $event instanceof ObjectUpdatingEvent === false) {
			return;
		}

		$entity = $this->writtenEntity(event: $event);
		if ($this->isLessonNote(entity: $entity) === false) {
			return;
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			// A system write (import, repair) has nobody to police.
			return;
		}

		$uid = $user->getUID();
		$note = ($entity->getObject() ?? []);

		if ($this->mayWrite(uid: $uid, note: $note) === false) {
			$event->setErrors(
				[
					'reason'  => 'lesson-note-not-your-lesson',
					'message' => 'Only the teachers of a lesson can add a note to it.',
				]
			);
			$event->stopPropagation();
			$this->logger->info('[LessonNoteAuthorGuard] Refused a lesson note by {uid}.', ['uid' => $uid]);
			return;
		}

		// The author is the server's to write: the caller on create, and
		// unchanged on update.
		$author = $uid;
		if ($event instanceof ObjectUpdatingEvent === true) {
			$previous = (string)(($event->getOldObject()?->getObject() ?? [])['authorId'] ?? '');
			if ($previous !== '') {
				$author = $previous;
			}
		}

		$note['authorId'] = $author;
		$entity->setObject($note);
	}//end handle()

	/**
	 * Whether the caller may write this note.
	 *
	 * @param string              $uid  The caller.
	 * @param array<string,mixed> $note The note as it will be stored.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-teacher-adds-a-note-to-a-lesson
	 */
	private function mayWrite(string $uid, array $note): bool {
		if ($this->isStaff(uid: $uid) === true) {
			return true;
		}

		$cohortId = (string)($note['cohortId'] ?? '');
		$sessionId = (string)($note['sessionId'] ?? '');

		if ($sessionId !== '') {
			$session = $this->load(schema: 'session', id: $sessionId);
			if ($session === null) {
				return false;
			}

			// The note must name the lesson's own cohort.
			if ((string)($session['cohortId'] ?? '') !== $cohortId) {
				return false;
			}

			if ((string)($session['substituteTeacherId'] ?? '') === $uid) {
				return true;
			}
		}

		if ($cohortId === '') {
			return false;
		}

		$cohort = $this->load(schema: 'cohort', id: $cohortId);
		$teacherIds = ($cohort['teacherIds'] ?? []);

		return is_array($teacherIds) === true && in_array($uid, $teacherIds, true) === true;
	}//end mayWrite()

	/**
	 * Whether the caller is an admin or in a staff group that writes on any lesson.
	 *
	 * @param string $uid The caller.
	 *
	 * @return bool
	 */
	private function isStaff(string $uid): bool {
		if ($this->groupManager->isAdmin($uid) === true) {
			return true;
		}

		foreach (self::STAFF_GROUPS as $group) {
			if ($this->groupManager->isInGroup($uid, $group) === true) {
				return true;
			}
		}

		return false;
	}//end isStaff()

	/**
	 * Read one learniq object without the caller's RBAC: the check itself
	 * decides, so a teacher who cannot list a cohort is refused, not crashed.
	 *
	 * @param string $schema Schema slug.
	 * @param string $id     Object uuid.
	 *
	 * @return array<string,mixed>|null The object, or null when it cannot be found.
	 */
	private function load(string $schema, string $id): ?array {
		try {
			$object = $this->objectService->find(id: $id, register: self::REGISTER, schema: $schema, _rbac: false);
		} catch (Throwable $exception) {
			$this->logger->debug(
				'[LessonNoteAuthorGuard] {schema} {id} not readable: {msg}',
				['schema' => $schema, 'id' => $id, 'msg' => $exception->getMessage()]
			);
			return null;
		}

		if ($object === null) {
			return null;
		}

		return (array)$object->jsonSerialize();
	}//end load()

	/**
	 * Whether the entity is a lesson note.
	 *
	 * @param ObjectEntity $entity The object being written.
	 *
	 * @return bool
	 */
	private function isLessonNote(ObjectEntity $entity): bool {
		try {
			return $this->schemaResolver->guardSchemaSlug(entity: $entity) === self::NOTE_SCHEMA;
		} catch (Throwable $exception) {
			// Not knowing the schema is not knowing it is ours: never break
			// another app's writes.
			return false;
		}
	}//end isLessonNote()

	/**
	 * The object being written, read through the accessor each event really has.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The pre-write event.
	 *
	 * @return ObjectEntity The object as it will be stored.
	 */
	private function writtenEntity(ObjectCreatingEvent|ObjectUpdatingEvent $event): ObjectEntity {
		if ($event instanceof ObjectUpdatingEvent === true) {
			return $event->getNewObject();
		}

		return $event->getObject();
	}//end writtenEntity()
}//end class
