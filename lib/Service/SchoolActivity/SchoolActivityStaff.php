<?php

/**
 * Learniq School Activity Staff
 *
 * The staff side of term-long school activities (school-activities-staff-side):
 * the board of activities with their places, confirmed sign-ups and waiting
 * list; opening an activity, refused with `no_places` when it has no place;
 * the roster with each child's photo consent where photos are taken; and
 * attendance per session for a child with a confirmed place, a second mark
 * updating the first. Guardians sign up in portaliq; this class never writes
 * a sign-up.
 *
 * @category Service
 * @package  OCA\Learniq\Service\SchoolActivity
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
 * @spec openspec/changes/school-activities-staff-side/specs/school-activities/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\SchoolActivity;

use OCA\Learniq\Kernel\Contracts\SchoolActivityStaffInterface;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Places, roster and attendance of school activities, for staff.
 *
 * @spec openspec/changes/school-activities-staff-side/specs/school-activities/spec.md
 */
class SchoolActivityStaff implements SchoolActivityStaffInterface {

	/**
	 * The attendance statuses staff may mark.
	 */
	private const STATUSES = ['present', 'absent', 'excused'];

	/**
	 * The consent purpose school photos are shared through.
	 */
	private const PHOTO_PURPOSE = 'news';

	/**
	 * Constructor.
	 *
	 * @param ActivityRecords $records The activity rows.
	 * @param ActivityPlaces  $places  Places and lists.
	 * @param ITimeFactory    $time    The clock for `markedAt`.
	 */
	public function __construct(
		private readonly ActivityRecords $records,
		private readonly ActivityPlaces $places,
		private readonly ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * Every activity with its places, confirmed sign-ups and waiting list size.
	 *
	 * @return array<int, array<string, mixed>>|null
	 *
	 * @spec openspec/changes/school-activities-staff-side/specs/school-activities/spec.md#requirement-staff-must-set-an-activitys-places-by-capacity-and-supervision
	 */
	public function board(): ?array {
		$activities = $this->records->rows(schema: ActivityRecords::OFFER);
		$signups    = $this->records->rows(schema: ActivityRecords::SIGNUP);
		if ($activities === null || $signups === null) {
			return null;
		}

		$board = [];
		foreach ($activities as $activity) {
			$keys = ActivityRecords::keys(row: $activity);
			$own  = array_values(array_filter($signups, static fn (array $row): bool => in_array((string)($row['activityRef'] ?? ''), $keys, true)));
			$board[] = [
				'id' => ActivityRecords::idOf(row: $activity),
				'title' => (string)($activity['title'] ?? ''),
				'status' => (string)($activity['status'] ?? ''),
				'termStart' => ($activity['termStart'] ?? null),
				'places' => $this->places->placesFor(activity: $activity),
				'confirmed' => count($this->places->confirmed(signups: $own)),
				'waitlist' => count($this->places->waitlist(signups: $own)),
			];
		}

		return $board;
	}//end board()

	/**
	 * Open a draft activity, refused with `no_places` when it has no place.
	 *
	 * @param string $activityId The activity.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/school-activities-staff-side/specs/school-activities/spec.md#requirement-staff-must-set-an-activitys-places-by-capacity-and-supervision
	 */
	public function open(string $activityId): array {
		$activity = $this->records->activity(id: $activityId);
		if ($activity === null) {
			return ['error' => self::REASON_NOT_FOUND];
		}

		if ($this->places->placesFor(activity: $activity) < 1) {
			return ['error' => self::REASON_NO_PLACES];
		}

		$saved = $this->records->save(
			schema: ActivityRecords::OFFER,
			data: array_merge($activity, ['status' => 'open']),
			id: ActivityRecords::idOf(row: $activity)
		);
		if ($saved === null) {
			return ['error' => self::REASON_UNAVAILABLE];
		}

		return $saved;
	}//end open()

	/**
	 * The roster: places, confirmed children and waiting list.
	 *
	 * @param string $activityId The activity.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/school-activities-staff-side/specs/school-activities/spec.md#requirement-where-photos-are-taken-the-staff-roster-must-show-each-childs-photo-consent
	 */
	public function roster(string $activityId): array {
		$activity = $this->records->activity(id: $activityId);
		if ($activity === null) {
			return ['error' => self::REASON_NOT_FOUND];
		}

		$signups = $this->records->signupsOf(activity: $activity);
		if ($signups === null) {
			return ['error' => self::REASON_UNAVAILABLE];
		}

		$confirmed = $this->places->confirmed(signups: $signups);
		$waitlist  = $this->places->waitlist(signups: $signups);
		if (($activity['photosTaken'] ?? false) === true) {
			$confirmed = $this->withPhotoConsent(signups: $confirmed);
			$waitlist  = $this->withPhotoConsent(signups: $waitlist);
		}

		return [
			'activity' => ActivityRecords::idOf(row: $activity),
			'places' => $this->places->placesFor(activity: $activity),
			'photosTaken' => (($activity['photosTaken'] ?? false) === true),
			'confirmed' => $confirmed,
			'waitlist' => $waitlist,
		];
	}//end roster()

	/**
	 * Mark one confirmed child for one session; a second mark updates the first.
	 *
	 * @param string $activityId  The activity.
	 * @param string $sessionId   One of the activity's sessions.
	 * @param string $childRef    The child.
	 * @param string $status      present, absent or excused.
	 * @param string $markedByRef Who marks.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/school-activities-staff-side/specs/school-activities/spec.md#requirement-staff-must-be-able-to-mark-attendance-per-session
	 */
	public function mark(string $activityId, string $sessionId, string $childRef, string $status, string $markedByRef): array {
		$activity = $this->records->activity(id: $activityId);
		if ($activity === null) {
			return ['error' => self::REASON_NOT_FOUND];
		}

		$refusal = $this->markRefusal(activity: $activity, sessionId: $sessionId, childRef: $childRef, status: $status);
		if ($refusal !== null) {
			return ['error' => $refusal];
		}

		$activityRef = ActivityRecords::idOf(row: $activity);
		$existing    = $this->records->rows(
			schema: ActivityRecords::ATTENDANCE,
			filters: ['activityRef' => $activityRef, 'sessionId' => $sessionId, 'childRef' => $childRef]
		);
		if ($existing === null) {
			return ['error' => self::REASON_UNAVAILABLE];
		}

		$data = [
			'activityRef' => $activityRef,
			'sessionId' => $sessionId,
			'childRef' => $childRef,
			'status' => $status,
			'markedByRef' => $markedByRef,
			'markedAt' => gmdate('c', $this->time->getTime()),
		];
		$id   = '';
		$prior = $this->sameMark(rows: $existing, activity: $activity, sessionId: $sessionId, childRef: $childRef);
		if ($prior !== null) {
			$data = array_merge($prior, $data);
			$id   = ActivityRecords::idOf(row: $prior);
		}

		$saved = $this->records->save(schema: ActivityRecords::ATTENDANCE, data: $data, id: $id);
		if ($saved === null) {
			return ['error' => self::REASON_UNAVAILABLE];
		}

		return $saved;
	}//end mark()

	/**
	 * Why a mark is refused, or null when it may be written.
	 *
	 * @param array<string, mixed> $activity  The activity.
	 * @param string               $sessionId The session.
	 * @param string               $childRef  The child.
	 * @param string               $status    The status.
	 *
	 * @return string|null
	 */
	private function markRefusal(array $activity, string $sessionId, string $childRef, string $status): ?string {
		if (in_array($status, self::STATUSES, true) === false) {
			return self::REASON_INVALID_STATUS;
		}

		if ($this->hasSession(activity: $activity, sessionId: $sessionId) === false) {
			return self::REASON_UNKNOWN_SESSION;
		}

		$signups = $this->records->signupsOf(activity: $activity);
		if ($signups === null) {
			return self::REASON_UNAVAILABLE;
		}

		foreach ($this->places->confirmed(signups: $signups) as $signup) {
			if ($childRef !== '' && (string)($signup['childRef'] ?? '') === $childRef) {
				return null;
			}
		}

		return self::REASON_NOT_CONFIRMED;
	}//end markRefusal()

	/**
	 * Whether the session is one the activity declares.
	 *
	 * @param array<string, mixed> $activity  The activity.
	 * @param string               $sessionId The session.
	 *
	 * @return bool
	 */
	private function hasSession(array $activity, string $sessionId): bool {
		if ($sessionId === '') {
			return false;
		}

		foreach ((array)($activity['sessions'] ?? []) as $session) {
			if (is_array($session) === true && ($session['id'] ?? null) === $sessionId) {
				return true;
			}
		}

		return false;
	}//end hasSession()

	/**
	 * The stored mark for this child and session, if any.
	 *
	 * The filter already narrows the rows; this re-checks every reference the
	 * activity may be stored under, so a row written with the uuid and one
	 * written with the slug are the same mark.
	 *
	 * @param array<int, array<string, mixed>> $rows      Candidate rows.
	 * @param array<string, mixed>             $activity  The activity.
	 * @param string                           $sessionId The session.
	 * @param string                           $childRef  The child.
	 *
	 * @return array<string, mixed>|null
	 */
	private function sameMark(array $rows, array $activity, string $sessionId, string $childRef): ?array {
		$keys = ActivityRecords::keys(row: $activity);
		foreach ($rows as $row) {
			if (in_array((string)($row['activityRef'] ?? ''), $keys, true) === true
				&& (string)($row['sessionId'] ?? '') === $sessionId
				&& (string)($row['childRef'] ?? '') === $childRef
			) {
				return $row;
			}
		}

		return null;
	}//end sameMark()

	/**
	 * Each sign-up with `photoConsent`: the signing guardian's consent for the
	 * child, purpose `news`. An absent consent reads as no consent.
	 *
	 * @param array<int, array<string, mixed>> $signups The sign-ups.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function withPhotoConsent(array $signups): array {
		$consents = ($this->records->rows(schema: ActivityRecords::CONSENT) ?? []);
		foreach ($signups as $index => $signup) {
			$signups[$index]['photoConsent'] = self::consented(
				rows: $consents,
				guardianRef: (string)($signup['guardianRef'] ?? ''),
				childRef: (string)($signup['childRef'] ?? '')
			);
		}

		return $signups;
	}//end withPhotoConsent()

	/**
	 * Whether the guardian's consent row grants photos of the child for purpose `news`.
	 *
	 * @param array<int, array<string, mixed>> $rows        The consent rows.
	 * @param string                           $guardianRef The signing guardian.
	 * @param string                           $childRef    The child.
	 *
	 * @return bool
	 */
	private static function consented(array $rows, string $guardianRef, string $childRef): bool {
		if ($guardianRef === '' || $childRef === '') {
			return false;
		}

		foreach ($rows as $row) {
			if ((string)($row['guardianRef'] ?? '') !== $guardianRef) {
				continue;
			}

			$consent = $row['photoConsent'] ?? [];
			return is_array($consent) === true && is_array($consent[$childRef] ?? null) === true
				&& ($consent[$childRef][self::PHOTO_PURPOSE] ?? false) === true;
		}

		return false;
	}//end consented()
}//end class
