<?php

/**
 * Learniq Catalogue Sign-up Service
 *
 * A learner signs up for a course or a whole programme from the catalogue,
 * or withdraws their own sign-up (enrolment-catalogue-self-signup). The rules
 * are checked here before anything is written, and every write is for the
 * caller only:
 *
 * - sign up: the course (or programme) is `published` and its `selfEnrolment`
 *   is `open` or `on-request`; the learner has no pending or active enrolment
 *   for the course. One Enrolment is created with `source: self`, `active` at
 *   once for an open course and `pending` for one on request (created in its
 *   state, so no `activate` transition fires and the mandatory-course message
 *   is never sent for a chosen course). EnrolmentPrerequisiteListener still
 *   runs on the create and its refusal is returned in its own words.
 * - programme: one enrolment per course of the programme the learner is not
 *   already on, each with `programmeId`; a course the prerequisite check
 *   refuses is reported, the others are created.
 * - withdraw: the enrolment is the learner's own, `source: self`, `pending`
 *   or `active`, and has no progress.
 *
 * The object API does not let a learner create or update enrolments, or they
 * could enrol someone else; so the writes skip that check, after these rules,
 * and run inside `ObjectService::runAs()` for the learner so the audit trail
 * names them (the receiver pattern of learniq #1096 and #1142, for the app and
 * the portal alike).
 *
 * @category Service
 * @package  OCA\Learniq\Service\Catalogue
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
 * @spec openspec/changes/enrolment-catalogue-self-signup/specs/enrolment/spec.md#requirement-a-learner-signs-up-from-the-catalogue
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Catalogue;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Learniq\Service\Portal\PortalLearner;
use OCA\Learniq\Service\Portal\PortalOutcome;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use Throwable;

/**
 * Sign-up and withdraw on the learner's own behalf.
 *
 * @spec openspec/changes/enrolment-catalogue-self-signup/specs/enrolment/spec.md#requirement-a-learner-signs-up-from-the-catalogue
 */
class CatalogueSignUpService {

	private const REGISTER = 'learniq';
	private const ENROLMENT = 'enrolment';

	/**
	 * Constructor.
	 *
	 * @param ObjectService   $objects OpenRegister object access.
	 * @param CatalogueReader $reader  The learner's enrolments per course.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objects,
		private readonly CatalogueReader $reader,
	) {
	}//end __construct()

	/**
	 * The catalogue for the learner.
	 *
	 * @param PortalLearner $learner The learner.
	 * @param string        $search  Free text, '' for all.
	 *
	 * @return PortalOutcome 200 `{courses, programmes}`.
	 *
	 * @spec openspec/changes/enrolment-catalogue-self-signup/specs/enrolment/spec.md#requirement-a-learner-signs-up-from-the-catalogue
	 */
	public function catalogue(PortalLearner $learner, string $search): PortalOutcome {
		return new PortalOutcome(status: Http::STATUS_OK, body: $this->reader->entries(userId: $learner->ncUserId, search: $search));
	}//end catalogue()

	/**
	 * Sign the learner up for one course.
	 *
	 * @param PortalLearner $learner  The learner (in the app: the session user and their profile).
	 * @param string        $courseId The course uuid.
	 *
	 * @return PortalOutcome 200 `{enrolmentId, lifecycle}`, or 404 / 409 / 422 with a reason.
	 *
	 * @spec openspec/changes/enrolment-catalogue-self-signup/specs/enrolment/spec.md#scenario-a-learner-signs-up-for-an-open-course
	 * @spec openspec/changes/enrolment-catalogue-self-signup/specs/enrolment/spec.md#scenario-a-prerequisite-blocks-a-sign-up
	 */
	public function signUpCourse(PortalLearner $learner, string $courseId): PortalOutcome {
		$course = $this->openEntry(schema: 'course', id: $courseId);
		if ($course instanceof PortalOutcome) {
			return $course;
		}

		$mine = $this->reader->enrolmentsByCourse(userId: $learner->ncUserId);
		if (in_array(($mine[$courseId]['lifecycle'] ?? ''), CatalogueReader::LIVE_STATES, true) === true) {
			return new PortalOutcome(status: Http::STATUS_CONFLICT, body: ['error' => 'already_signed_up'], reason: 'already-signed-up');
		}

		return $this->create(
			learner: $learner,
			courseId: $courseId,
			mode: (string)$course['selfEnrolment'],
			programmeId: null,
			tenantId: (string)($course['tenant_id'] ?? '')
		);
	}//end signUpCourse()

	/**
	 * Sign the learner up for every course of a programme they are not on yet.
	 *
	 * @param PortalLearner $learner     The learner.
	 * @param string        $programmeId The programme uuid.
	 *
	 * @return PortalOutcome 200 `{created: [...], refused: [...]}`, or 404 / 422.
	 *
	 * @spec openspec/changes/enrolment-catalogue-self-signup/specs/enrolment/spec.md#scenario-a-learner-signs-up-for-a-track
	 */
	public function signUpProgramme(PortalLearner $learner, string $programmeId): PortalOutcome {
		$programme = $this->openEntry(schema: 'programme', id: $programmeId);
		if ($programme instanceof PortalOutcome) {
			return $programme;
		}

		$mine = $this->reader->enrolmentsByCourse(userId: $learner->ncUserId);
		$created = [];
		$refused = [];
		foreach (array_unique(array_map('strval', (array)($programme['courseIds'] ?? []))) as $courseId) {
			if ($courseId === '' || in_array(($mine[$courseId]['lifecycle'] ?? ''), CatalogueReader::LIVE_STATES, true) === true) {
				continue;
			}

			$outcome = $this->create(
				learner: $learner,
				courseId: $courseId,
				mode: (string)$programme['selfEnrolment'],
				programmeId: $programmeId,
				tenantId: (string)($programme['tenant_id'] ?? '')
			);
			if ($outcome->status === Http::STATUS_OK) {
				$created[] = $outcome->body;
				continue;
			}

			$refused[] = ['courseId' => $courseId, 'message' => (string)($outcome->body['message'] ?? '')];
		}

		return new PortalOutcome(status: Http::STATUS_OK, body: ['created' => $created, 'refused' => $refused]);
	}//end signUpProgramme()

	/**
	 * Withdraw the learner's own sign-up.
	 *
	 * @param PortalLearner $learner     The learner.
	 * @param string        $enrolmentId The enrolment uuid.
	 *
	 * @return PortalOutcome 200 `{enrolmentId, lifecycle}`, or 404 / 422.
	 *
	 * @spec openspec/changes/enrolment-catalogue-self-signup/specs/enrolment/spec.md#scenario-a-learner-changes-their-mind
	 */
	public function withdraw(PortalLearner $learner, string $enrolmentId): PortalOutcome {
		$enrolment = $this->read(schema: self::ENROLMENT, id: $enrolmentId);
		if ($enrolment === null || ($enrolment['learnerId'] ?? '') !== $learner->ncUserId) {
			return new PortalOutcome(status: Http::STATUS_NOT_FOUND, body: ['error' => 'not_found'], reason: 'not-found');
		}

		$withdrawable = ($enrolment['source'] ?? '') === 'self'
			&& in_array(($enrolment['lifecycle'] ?? ''), CatalogueReader::LIVE_STATES, true) === true
			&& (float)($enrolment['progressPercent'] ?? 0) <= 0.0;
		if ($withdrawable === false) {
			return new PortalOutcome(status: Http::STATUS_UNPROCESSABLE_ENTITY, body: ['error' => 'not_withdrawable'], reason: 'not-withdrawable');
		}

		$row = $enrolment;
		unset($row['@self']);
		$row['lifecycle'] = 'withdrawn';
		$this->objects->runAs(
			user: $learner->user,
			operation: fn () => $this->objects->saveObject(object: $row, register: self::REGISTER, schema: self::ENROLMENT, uuid: $enrolmentId, _rbac: false)
		);

		return new PortalOutcome(status: Http::STATUS_OK, body: ['enrolmentId' => $enrolmentId, 'lifecycle' => 'withdrawn']);
	}//end withdraw()

	/**
	 * Create one self enrolment, active for `open`, pending for `on-request`.
	 *
	 * @param PortalLearner $learner     The learner.
	 * @param string        $courseId    The course uuid.
	 * @param string        $mode        `open` or `on-request`.
	 * @param string|null   $programmeId The programme it came from, if any.
	 * @param string        $tenantId    The tenant of the course or programme.
	 *
	 * @return PortalOutcome
	 */
	private function create(PortalLearner $learner, string $courseId, string $mode, ?string $programmeId, string $tenantId): PortalOutcome {
		$lifecycle = 'pending';
		if ($mode === 'open') {
			$lifecycle = 'active';
		}

		$profile = $this->read(schema: 'learner-profile', id: $learner->profileRef);
		if ($tenantId === '') {
			$tenantId = $learner->tenantId;
		}

		$enrolment = array_filter(
			[
				'learnerId' => $learner->ncUserId,
				'learnerRef' => $profile['id'] ?? null,
				'courseId' => $courseId,
				'programmeId' => $programmeId,
				'source' => 'self',
				'mandatory' => false,
				'managerId' => $profile['managerId'] ?? null,
				'requestedAt' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
				'lifecycle' => $lifecycle,
				'tenant_id' => $tenantId,
			],
			static fn (mixed $value): bool => $value !== null
		);

		try {
			$saved = $this->objects->runAs(
				user: $learner->user,
				operation: fn () => $this->objects->saveObject(object: $enrolment, register: self::REGISTER, schema: self::ENROLMENT, _rbac: false)
			);
		} catch (Throwable $exception) {
			$message = $this->vetoMessage(exception: $exception);
			if ($message === null) {
				throw $exception;
			}

			return new PortalOutcome(status: Http::STATUS_UNPROCESSABLE_ENTITY, body: ['error' => 'prerequisite_missing', 'message' => $message]);
		}

		$enrolmentId = '';
		if (is_object($saved) === true && method_exists($saved, 'getUuid') === true) {
			$enrolmentId = (string)$saved->getUuid();
		}

		return new PortalOutcome(status: Http::STATUS_OK, body: ['enrolmentId' => $enrolmentId, 'courseId' => $courseId, 'lifecycle' => $lifecycle]);
	}//end create()

	/**
	 * A published course or programme open for sign-up, or the refusal.
	 *
	 * @param string $schema `course` or `programme`.
	 * @param string $id     The uuid.
	 *
	 * @return array<string, mixed>|PortalOutcome
	 */
	private function openEntry(string $schema, string $id): array|PortalOutcome {
		$row = $this->read(schema: $schema, id: $id);
		if ($row === null || ($row['lifecycle'] ?? '') !== 'published') {
			return new PortalOutcome(status: Http::STATUS_NOT_FOUND, body: ['error' => 'not_found'], reason: 'not-found');
		}

		if (in_array(($row['selfEnrolment'] ?? 'closed'), CatalogueReader::OPEN_VALUES, true) === false) {
			return new PortalOutcome(status: Http::STATUS_UNPROCESSABLE_ENTITY, body: ['error' => 'closed'], reason: 'closed');
		}

		return $row;
	}//end openEntry()

	/**
	 * The listener's message when an exception is a create veto (OpenRegister's
	 * HookStoppedException carrying `errors.message`), else null.
	 *
	 * @param Throwable $exception The exception from the create.
	 *
	 * @return string|null
	 */
	private function vetoMessage(Throwable $exception): ?string {
		if (method_exists($exception, 'getErrors') === false) {
			return null;
		}

		$errors = $exception->getErrors();
		if (is_array($errors) === false || is_string($errors['message'] ?? null) === false) {
			return null;
		}

		return $errors['message'];
	}//end vetoMessage()

	/**
	 * One learniq object by uuid as an array, or null.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id     The uuid.
	 *
	 * @return array<string, mixed>|null
	 */
	private function read(string $schema, string $id): ?array {
		if ($id === '') {
			return null;
		}

		try {
			$object = $this->objects->find(id: $id, register: self::REGISTER, schema: $schema, _rbac: false, _multitenancy: false, _render: false);
		} catch (DoesNotExistException) {
			return null;
		}

		$row = $object?->jsonSerialize();
		if (is_array($row) === false) {
			return null;
		}

		$row['id'] = (string)($row['id'] ?? ($row['@self']['id'] ?? $id));

		return $row;
	}//end read()
}//end class
