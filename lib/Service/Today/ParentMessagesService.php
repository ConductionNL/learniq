<?php

/**
 * Learniq Parent Messages Service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Reads the "Messages from parents" card on Today through portaliq's staff
 * messaging, with the caller's own reference, so portaliq hands over only the
 * conversations she takes part in. Read only: Today never posts or marks a
 * message read. In the Mentor view the conversations narrow to the pupils of
 * the groups she is the mentor of; a teacher who mentors no group reads the
 * Teacher view, as the rest of Today does.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Today
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-25-today-first-per-school-role/tasks.md#T5
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Today;

use OCA\Learniq\Service\Attendance\RollCallAccess;
use OCA\Learniq\Service\Attendance\RollCallException;
use OCA\Learniq\Service\Pupil\PortaliqStaffMessages;
use OCP\IUser;

/**
 * The newest conversations with parents of the signed-in teacher.
 *
 * @spec openspec/changes/archive/2026-11-25-today-first-per-school-role/tasks.md#T5
 */
class ParentMessagesService {
	use RowValues;

	/**
	 * The view that narrows Today to the mentor class.
	 *
	 * @var string
	 */
	public const MENTOR_VIEW = 'mentor';

	/**
	 * Constructor.
	 *
	 * @param RollCallAccess        $access   Which groups the caller may open.
	 * @param FirstTodayReader      $reader   Reads rows with the caller's own rights.
	 * @param PortaliqStaffMessages $messages Portaliq's staff messaging.
	 */
	public function __construct(
		private readonly RollCallAccess $access,
		private readonly FirstTodayReader $reader,
		private readonly PortaliqStaffMessages $messages,
	) {
	}//end __construct()

	/**
	 * The caller's newest conversations with parents.
	 *
	 * Without portaliq (or when its messaging cannot be read) the answer says
	 * so with `available: false` and no conversations, so the card shows its
	 * empty text instead of an error.
	 *
	 * @param IUser  $user The signed-in user.
	 * @param string $view The Today view (`mentor` narrows to the mentor class).
	 *
	 * @return array<string, mixed> available, view, threads, unreadCount, total.
	 *
	 * @spec openspec/changes/archive/2026-11-25-today-first-per-school-role/tasks.md#T5
	 */
	public function for(IUser $user, string $view): array {
		$staffRef = $user->getUID();
		$pupilRefs = null;
		if ($view === self::MENTOR_VIEW) {
			$pupilRefs = $this->mentorPupilRefs(user: $user);
		}

		$threads = $this->messages->threads(staffRef: $staffRef);
		if ($threads === null) {
			return ['available' => false, 'view' => $this->viewOf(pupilRefs: $pupilRefs), 'threads' => [], 'unreadCount' => 0, 'total' => 0];
		}

		if ($pupilRefs !== null) {
			$threads = array_values(
				array_filter($threads, static fn (array $thread): bool => in_array((string)($thread['recordRef'] ?? ''), $pupilRefs, true) === true)
			);
		}

		$byThread = [];
		foreach ($threads as $thread) {
			$threadId = self::idOf(row: $thread);
			if ($threadId !== '') {
				$byThread[$threadId] = ($this->messages->messages(threadId: $threadId, staffRef: $staffRef) ?? []);
			}
		}

		return array_merge(
			['available' => true, 'view' => $this->viewOf(pupilRefs: $pupilRefs)],
			(new ParentMessages())->newest(threads: $threads, byThread: $byThread, staffRef: $staffRef)
		);
	}//end for()

	/**
	 * The references of the pupils in the groups the caller is the mentor of:
	 * their user ids, profile ids and enrolment ids, since a conversation is
	 * about one of those records. Null when she mentors no group.
	 *
	 * @param IUser $user The caller.
	 *
	 * @return list<string>|null
	 */
	private function mentorPupilRefs(IUser $user): ?array {
		try {
			$cohorts = $this->access->cohortsFor(user: $user);
		} catch (RollCallException) {
			return null;
		}

		$refs = [];
		$mentor = false;
		foreach ($cohorts as $cohort) {
			if ((string)($cohort['mentorId'] ?? '') !== $user->getUID()) {
				continue;
			}

			$mentor = true;
			$refs = array_merge($refs, array_map('strval', (array)($cohort['learnerIds'] ?? [])));
			$cohortId = self::idOf(row: $cohort);
			foreach ($this->reader->rows(schema: 'enrolment', filters: ['cohortId' => $cohortId]) as $enrolment) {
				$refs[] = (string)($enrolment['learnerRef'] ?? '');
				$refs[] = (string)($enrolment['learnerId'] ?? '');
				$refs[] = self::idOf(row: $enrolment);
			}
		}

		if ($mentor === false) {
			return null;
		}

		return array_values(array_unique(array_filter($refs, static fn (string $ref): bool => $ref !== '')));
	}//end mentorPupilRefs()

	/**
	 * The view the answer was read for.
	 *
	 * @param list<string>|null $pupilRefs The mentor class's pupils, or null.
	 *
	 * @return string
	 */
	private function viewOf(?array $pupilRefs): string {
		if ($pupilRefs === null) {
			return 'teacher';
		}

		return self::MENTOR_VIEW;
	}//end viewOf()
}//end class
