<?php

/**
 * Learniq Elective Service
 *
 * Reads optional lesson offers and their sign-ups for the rules listener, the
 * learner's page and the coordinator's roster: the lessons of an offer with
 * their times, the sign-up window of each lesson, the places taken, and who
 * of the eligible groups signed up. Every read is as the system, because the
 * rules must hold whoever writes and a learner may not read other learners'
 * sign-ups. Nothing here writes.
 *
 * Under D10 an offer names learniq sessions (`sessionIds`) or planninq
 * lessons (`timetableSessionRefs`, `{sourceSystem, externalRef, startsAt,
 * endsAt, title}`). A lesson is keyed as its session uuid or as
 * `sourceSystem:externalRef`.
 *
 * @category Service
 * @package  OCA\Learniq\Service
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
 * @spec openspec/specs/enrolment/spec.md#requirement-every-sign-up-obeys-the-same-rules-whoever-writes-it
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Service\ObjectService;
use Throwable;

/**
 * Offers, lessons, windows and places.
 *
 * @spec openspec/specs/enrolment/spec.md#requirement-every-sign-up-obeys-the-same-rules-whoever-writes-it
 */
class ElectiveService {

	public const REGISTER = 'learniq';
	public const OFFER_SCHEMA = 'elective-offer';
	public const SIGN_UP_SCHEMA = 'elective-sign-up';
	public const WITHDRAWN = 'withdrawn';
	public const STAFF_GROUPS = ['instructors', 'team-leads', 'compliance-officers', 'coordinators'];
	public const INTEGRATION_GROUP = 'elective-integrations';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister objects, read as the system.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * One offer, or null.
	 *
	 * @param string $offerId The offer's uuid.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/enrolment/spec.md#requirement-a-school-offers-optional-lessons-with-a-window-and-a-capacity
	 */
	public function offer(string $offerId): ?array {
		return $this->one(id: $offerId, schema: self::OFFER_SCHEMA);
	}//end offer()

	/**
	 * One sign-up, or null.
	 *
	 * @param string $signUpId The sign-up's uuid.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/enrolment/spec.md#requirement-a-learner-signs-up-for-an-optional-lesson-inside-the-window
	 */
	public function signUp(string $signUpId): ?array {
		return $this->one(id: $signUpId, schema: self::SIGN_UP_SCHEMA);
	}//end signUp()

	/**
	 * The key of the lesson a sign-up or request names.
	 *
	 * @param array<string, mixed> $row A sign-up, or a request body.
	 *
	 * @return string The session uuid, `sourceSystem:externalRef`, or ''.
	 *
	 * @spec openspec/specs/enrolment/spec.md#requirement-every-sign-up-obeys-the-same-rules-whoever-writes-it
	 */
	public function lessonKey(array $row): string {
		$sessionId = $row['sessionId'] ?? null;
		if (is_string($sessionId) === true && $sessionId !== '') {
			return $sessionId;
		}

		$ref = $row['timetableSessionRef'] ?? null;
		if (is_array($ref) === false || ($ref['externalRef'] ?? '') === '') {
			return '';
		}

		return (string)($ref['sourceSystem'] ?? '').':'.(string)$ref['externalRef'];
	}//end lessonKey()

	/**
	 * The offer's lessons by key, with their times.
	 *
	 * @param array<string, mixed> $offer The offer.
	 *
	 * @return array<string, array{key: string, sessionId: ?string, timetableSessionRef: ?array, title: string, startsAt: string, endsAt: string}>
	 *
	 * @spec openspec/specs/enrolment/spec.md#requirement-a-school-offers-optional-lessons-with-a-window-and-a-capacity
	 */
	public function lessons(array $offer): array {
		$lessons = [];
		$ids = array_values(array_filter((array)($offer['sessionIds'] ?? []), 'is_string'));
		if ($ids !== []) {
			foreach ($this->read(config: ['ids' => $ids, 'filters' => ['register' => self::REGISTER, 'schema' => 'session']]) as $session) {
				$key = (string)($session['id'] ?? '');
				$lessons[$key] = [
					'key' => $key,
					'sessionId' => $key,
					'timetableSessionRef' => null,
					'title' => (string)($session['title'] ?? ''),
					'startsAt' => (string)($session['startsAt'] ?? ''),
					'endsAt' => (string)($session['endsAt'] ?? ''),
				];
			}
		}

		foreach ((array)($offer['timetableSessionRefs'] ?? []) as $ref) {
			$key = $this->lessonKey(row: ['timetableSessionRef' => $ref]);
			if ($key === '') {
				continue;
			}

			$lessons[$key] = [
				'key' => $key,
				'sessionId' => null,
				'timetableSessionRef' => ['sourceSystem' => (string)($ref['sourceSystem'] ?? ''), 'externalRef' => (string)$ref['externalRef']],
				'title' => (string)($ref['title'] ?? ''),
				'startsAt' => (string)($ref['startsAt'] ?? ''),
				'endsAt' => (string)($ref['endsAt'] ?? ''),
			];
		}

		unset($lessons['']);
		uasort($lessons, static fn (array $left, array $right): int => strcmp($left['startsAt'], $right['startsAt']));

		return $lessons;
	}//end lessons()

	/**
	 * When sign-up for a lesson opens and closes.
	 *
	 * @param array<string, mixed> $offer    The offer.
	 * @param string               $startsAt The lesson's start.
	 *
	 * @return array{opensAt: ?DateTimeImmutable, closesAt: ?DateTimeImmutable}
	 *
	 * @spec openspec/specs/enrolment/spec.md#requirement-a-school-offers-optional-lessons-with-a-window-and-a-capacity
	 */
	public function window(array $offer, string $startsAt): array {
		if (($offer['windowMode'] ?? 'fixed') !== 'relative') {
			return ['opensAt' => $this->date(value: $offer['opensAt'] ?? null), 'closesAt' => $this->date(value: $offer['closesAt'] ?? null)];
		}

		$start = $this->date(value: $startsAt);
		if ($start === null) {
			return ['opensAt' => null, 'closesAt' => null];
		}

		return [
			'opensAt' => $start->modify('-'.(int)($offer['opensDaysBefore'] ?? 0).' days'),
			'closesAt' => $start->modify('-'.(int)($offer['closesHoursBefore'] ?? 0).' hours'),
		];
	}//end window()

	/**
	 * Whether the window is open now; an unset edge does not close it.
	 *
	 * @param array{opensAt: ?DateTimeImmutable, closesAt: ?DateTimeImmutable} $window The window.
	 * @param DateTimeImmutable                                                  $now    Now.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/enrolment/spec.md#requirement-a-learner-signs-up-for-an-optional-lesson-inside-the-window
	 */
	public function isOpen(array $window, DateTimeImmutable $now): bool {
		if ($window['opensAt'] !== null && $now < $window['opensAt']) {
			return false;
		}

		return $window['closesAt'] === null || $now < $window['closesAt'];
	}//end isOpen()

	/**
	 * The non-withdrawn sign-ups of an offer, optionally for one lesson.
	 *
	 * @param string      $offerId The offer.
	 * @param string|null $key     The lesson key, or null for all lessons.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/enrolment/spec.md#requirement-every-sign-up-obeys-the-same-rules-whoever-writes-it
	 */
	public function activeSignUps(string $offerId, ?string $key=null): array {
		$rows = $this->read(config: ['filters' => ['register' => self::REGISTER, 'schema' => self::SIGN_UP_SCHEMA, 'offerId' => $offerId]]);

		return array_values(
			array_filter(
				$rows,
				fn (array $row): bool => ($row['status'] ?? '') !== self::WITHDRAWN
					&& (string)($row['offerId'] ?? '') === $offerId
					&& ($key === null || $this->lessonKey(row: $row) === $key)
			)
		);
	}//end activeSignUps()

	/**
	 * The learners of the offer's eligible groups; empty means every learner.
	 *
	 * @param array<string, mixed> $offer The offer.
	 *
	 * @return array<int, string>|null The learner ids, or null when the offer is open to everyone.
	 *
	 * @spec openspec/specs/enrolment/spec.md#requirement-every-sign-up-obeys-the-same-rules-whoever-writes-it
	 */
	public function eligibleLearners(array $offer): ?array {
		$cohortIds = array_values(array_filter((array)($offer['eligibleCohortIds'] ?? []), 'is_string'));
		if ($cohortIds === []) {
			return null;
		}

		$learners = [];
		foreach ($this->read(config: ['ids' => $cohortIds, 'filters' => ['register' => self::REGISTER, 'schema' => 'cohort']]) as $cohort) {
			foreach ((array)($cohort['learnerIds'] ?? []) as $learnerId) {
				if (is_string($learnerId) === true && $learnerId !== '') {
					$learners[$learnerId] = true;
				}
			}
		}

		return array_keys($learners);
	}//end eligibleLearners()

	/**
	 * The open offers.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/enrolment/spec.md#requirement-a-learner-signs-up-for-an-optional-lesson-inside-the-window
	 */
	public function openOffers(): array {
		return $this->read(config: ['filters' => ['register' => self::REGISTER, 'schema' => self::OFFER_SCHEMA, 'lifecycle' => 'open']]);
	}//end openOffers()

	/**
	 * Who is writing.
	 *
	 * @param string             $uid    The writer's user id.
	 * @param array<int, string> $groups The writer's groups.
	 *
	 * @return array{uid: string, admin: bool, staff: bool, integration: bool, via: string}
	 *
	 * @spec openspec/specs/enrolment/spec.md#requirement-another-system-signs-learners-up-through-the-api
	 */
	public function caller(string $uid, array $groups): array {
		$admin = in_array('admin', $groups, true);
		$staff = ($admin === true || array_intersect($groups, self::STAFF_GROUPS) !== []);
		$integration = in_array(self::INTEGRATION_GROUP, $groups, true);

		$via = 'learner';
		if ($integration === true) {
			$via = 'integration';
		}

		if ($staff === true) {
			$via = 'coordinator';
		}

		return ['uid' => $uid, 'admin' => $admin, 'staff' => $staff, 'integration' => $integration, 'via' => $via];
	}//end caller()

	/**
	 * A date-time, or null.
	 *
	 * @param mixed $value An ISO 8601 string.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function date(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || $value === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Throwable $exception) {
			unset($exception);
			return null;
		}
	}//end date()

	/**
	 * One row as the system.
	 *
	 * @param string $id     The uuid.
	 * @param string $schema The schema slug.
	 *
	 * @return array<string, mixed>|null
	 */
	private function one(string $id, string $schema): ?array {
		try {
			$row = $this->objectService->find(id: $id, register: self::REGISTER, schema: $schema, _rbac: false, _multitenancy: false);
		} catch (Throwable $exception) {
			unset($exception);
			return null;
		}

		if ($row === null) {
			return null;
		}

		$data = (array)$row->jsonSerialize();
		$data['id'] = (string)($data['id'] ?? $id);

		return $data;
	}//end one()

	/**
	 * Rows as arrays, as the system.
	 *
	 * @param array<string, mixed> $config A findAll config.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function read(array $config): array {
		try {
			$rows = $this->objectService->findAll($config, _rbac: false, _multitenancy: false);
		} catch (Throwable $exception) {
			unset($exception);
			return [];
		}

		$out = [];
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				$out[] = $row;
				continue;
			}

			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$out[] = (array)$row->jsonSerialize();
			}
		}

		return $out;
	}//end read()
}//end class
