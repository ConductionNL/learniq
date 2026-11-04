<?php

/**
 * Learniq EmployerBookingProjection
 *
 * Reads the rows a company's booking is made of and writes back what
 * EmployerBookingFacts derives from them: the booking's day, people, status
 * and note, and each participant's open task. Called after every change the
 * employer makes through the portal, and by the repair step for what staff
 * changed (employer-portal-audience). A row is written only when a value
 * moved, so a second run writes nothing.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Portal
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
 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-a-booking-tells-the-employer-what-still-waits-for-her
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Portal;

use DateTimeImmutable;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;

/**
 * Re-derives and stores one booking and its participants.
 *
 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-a-booking-tells-the-employer-what-still-waits-for-her
 */
class EmployerBookingProjection {

	private const REGISTER = 'learniq';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService Reads and writes the rows.
	 * @param IUserManager  $users         Names the trainer.
	 * @param ITimeFactory|null $time      Today, so a booking whose last day has passed is no longer coming.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly IUserManager $users,
		private readonly ?ITimeFactory $time=null,
	) {
	}//end __construct()

	/**
	 * Re-derive one booking; answers the stored booking, or null when it does not exist.
	 *
	 * @param string $bookingId The booking's uuid.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @throws \Throwable When OpenRegister cannot be read or written.
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-a-booking-tells-the-employer-what-still-waits-for-her
	 */
	public function project(string $bookingId): ?array {
		$booking = $this->one(schema: 'course-booking', id: $bookingId);
		if ($booking === null) {
			return null;
		}

		$cohort = ($this->one(schema: 'cohort', id: (string)($booking['cohortId'] ?? '')) ?? []);
		$courseId = (string)($booking['courseId'] ?? ($cohort['courseId'] ?? ''));
		$course = ($this->one(schema: 'course', id: $courseId) ?? []);
		$sessions = $this->many(schema: 'session', filters: ['cohortId' => (string)($booking['cohortId'] ?? '')]);
		$participants = [];
		$enrolmentIds = [];
		foreach ($this->many(schema: 'enrolment', filters: ['bookingRef' => $bookingId]) as $enrolment) {
			$participants[] = [
				'enrolment' => $enrolment,
				'profile' => ($this->one(schema: 'learner-profile', id: (string)($enrolment['learnerRef'] ?? '')) ?? []),
			];
			$enrolmentIds[] = (string)($enrolment['id'] ?? '');
		}

		$renewed = [];
		foreach ($enrolmentIds as $enrolmentId) {
			foreach ($this->many(schema: 'credential', filters: ['renewalEnrolmentId' => $enrolmentId]) as $credential) {
				$renewed[$enrolmentId] = $credential;
			}
		}

		$facts = (new EmployerBookingFacts())->derive(
			booking: $booking,
			course: $course,
			sessions: $sessions,
			participants: $participants,
			renewed: $renewed,
			context: ['trainerName' => $this->trainerName(cohort: $cohort), 'placeLabel' => $this->placeLabel(cohort: $cohort)],
			today: $this->today()
		);

		foreach ($participants as $row) {
			$id = (string)($row['enrolment']['id'] ?? '');
			$this->saveWhenMoved(schema: 'enrolment', row: $row['enrolment'], fields: ($facts['enrolments'][$id] ?? []));
		}

		$fields = $facts['booking'];
		if ($courseId !== '') {
			$fields['courseId'] = $courseId;
		}

		return $this->saveWhenMoved(schema: 'course-booking', row: $booking, fields: $fields);
	}//end project()

	/**
	 * Today, from the time factory, or the clock when there is none.
	 *
	 * @return DateTimeImmutable
	 */
	private function today(): DateTimeImmutable {
		if ($this->time !== null) {
			return $this->time->now();
		}

		return new DateTimeImmutable('now');
	}//end today()

	/**
	 * The edition's first trainer by display name, or null.
	 *
	 * @param array<string, mixed> $cohort The edition.
	 *
	 * @return string|null
	 */
	private function trainerName(array $cohort): ?string {
		$uid = (string)(((array)($cohort['teacherIds'] ?? []))[0] ?? '');
		if ($uid === '') {
			return null;
		}

		$name = trim((string)$this->users->getDisplayName($uid));
		if ($name === '' || $name === $uid) {
			return null;
		}

		return $name;
	}//end trainerName()

	/**
	 * "Praktijkhal Zuiddrecht, Energieweg 8": the edition's location, or null.
	 *
	 * @param array<string, mixed> $cohort The edition.
	 *
	 * @return string|null
	 *
	 * @throws \Throwable When OpenRegister cannot be read.
	 */
	private function placeLabel(array $cohort): ?string {
		$location = $this->one(schema: 'vestiging', id: (string)($cohort['locationId'] ?? ''));
		if ($location === null) {
			return null;
		}

		$parts = array_filter([trim((string)($location['name'] ?? '')), trim((string)($location['street'] ?? ''))]);

		if ($parts === []) {
			return null;
		}

		return implode(', ', $parts);
	}//end placeLabel()

	/**
	 * Store a row when one of the fields moved; answers the row as stored.
	 *
	 * @param string               $schema The schema slug.
	 * @param array<string, mixed> $row    The stored row.
	 * @param array<string, mixed> $fields The derived fields.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws \Throwable When OpenRegister cannot be written.
	 */
	private function saveWhenMoved(string $schema, array $row, array $fields): array {
		$moved = false;
		foreach ($fields as $key => $value) {
			if (($row[$key] ?? null) !== $value) {
				$moved = true;
				break;
			}
		}

		if ($moved === false) {
			return $row;
		}

		$saved = $this->objectService->saveObject(
			object: array_merge($row, $fields),
			register: self::REGISTER,
			schema: $schema,
			_rbac: false,
			_multitenancy: false
		);

		return $this->toRow(object: $saved);
	}//end saveWhenMoved()

	/**
	 * The row with this uuid in a schema, or null.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id     The uuid.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @throws \Throwable When OpenRegister cannot be read.
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-a-booking-tells-the-employer-what-still-waits-for-her
	 */
	public function one(string $schema, string $id): ?array {
		if ($id === '') {
			return null;
		}

		$objects = $this->objectService->findAll(
			config: ['filters' => ['register' => self::REGISTER, 'schema' => $schema], 'ids' => [$id], 'limit' => 1],
			_rbac: false,
			_multitenancy: false
		);
		foreach ($objects as $object) {
			$row = $this->toRow(object: $object);
			if (($row['id'] ?? ($row['uuid'] ?? null)) === $id) {
				return $row;
			}
		}

		return null;
	}//end one()

	/**
	 * The rows of a schema whose fields equal the filters.
	 *
	 * @param string                $schema  The schema slug.
	 * @param array<string, string|bool> $filters Field equals value.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @throws \Throwable When OpenRegister cannot be read.
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-a-booking-tells-the-employer-what-still-waits-for-her
	 */
	public function many(string $schema, array $filters): array {
		if (in_array('', $filters, true) === true) {
			return [];
		}

		$objects = $this->objectService->findAll(
			config: ['filters' => array_merge(['register' => self::REGISTER, 'schema' => $schema], $filters), 'limit' => 500],
			_rbac: false,
			_multitenancy: false
		);
		$rows = [];
		foreach ($objects as $object) {
			$row = $this->toRow(object: $object);
			// Never trust the store to have applied a filter on a new field.
			foreach ($filters as $field => $value) {
				if (($row[$field] ?? null) !== $value) {
					continue 2;
				}
			}

			$rows[] = $row;
		}

		return $rows;
	}//end many()

	/**
	 * An OpenRegister row as an array.
	 *
	 * @param mixed $object The row.
	 *
	 * @return array<string, mixed>
	 */
	private function toRow(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$row = $object->jsonSerialize();
			if (is_array($row) === true) {
				return $row;
			}
		}

		return [];
	}//end toRow()
}//end class
