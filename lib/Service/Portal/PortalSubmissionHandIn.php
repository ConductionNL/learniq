<?php

/**
 * Learniq Portal Submission Hand-in
 *
 * Hands in a pupil's draft submission for portaliq's `handIn` forward
 * (portal-assignment-hand-in-endpoint). The pupil must own the submission and
 * it must still be a draft. SubmissionWindowGuard decides which hand-in
 * applies, `submit` inside the window or `submitLate` after it when the
 * assignment accepts late work, and the transition runs as the pupil through
 * OpenRegister's TransitionEngine, so the guard runs again on the write. The
 * guard stays the only place the deadline and the late rule live.
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
 * @spec openspec/changes/portal-assignment-hand-in-endpoint/specs/assignments/spec.md#requirement-a-pupil-hands-in-a-portal-draft-through-learniqs-own-endpoint
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Portal;

use OCA\Learniq\Lifecycle\SubmissionWindowGuard;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Checks and runs one portal hand-in.
 *
 * @spec openspec/changes/portal-assignment-hand-in-endpoint/specs/assignments/spec.md#requirement-a-pupil-hands-in-a-portal-draft-through-learniqs-own-endpoint
 */
class PortalSubmissionHandIn {

	private const REGISTER = 'learniq';
	private const SCHEMA = 'submission';
	private const DRAFT = 'draft';

	/**
	 * The error code OpenRegister's lifecycle listener stamps on a guard refusal.
	 */
	private const GUARD_DENIED = 'lifecycle-guard-denied';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object access.
	 * @param TransitionEngine $transitionEngine OpenRegister lifecycle transitions.
	 * @param SubmissionWindowGuard $guard The hand-in rules.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly TransitionEngine $transitionEngine,
		private readonly SubmissionWindowGuard $guard,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Hand in the pupil's draft.
	 *
	 * @param PortalLearner $learner The pupil.
	 * @param string $submissionId The submission's uuid, from the forward.
	 *
	 * @return PortalOutcome 200 `{submissionId, lifecycle}`, or 404 / 409 / 422 with a reason.
	 *
	 * @spec openspec/changes/portal-assignment-hand-in-endpoint/specs/assignments/spec.md#requirement-a-pupil-hands-in-a-portal-draft-through-learniqs-own-endpoint
	 */
	public function handIn(PortalLearner $learner, string $submissionId): PortalOutcome {
		$submission = $this->submission(id: $submissionId);
		if ($submission === null || $this->owns(learner: $learner, submission: $submission) === false) {
			return new PortalOutcome(status: Http::STATUS_NOT_FOUND, body: ['error' => 'not_found'], reason: 'submission-not-found');
		}

		if (($submission['lifecycle'] ?? self::DRAFT) !== self::DRAFT) {
			return new PortalOutcome(status: Http::STATUS_CONFLICT, body: ['error' => 'already_handed_in'], reason: 'already-handed-in');
		}

		$submit = $this->guard->check($submission, 'submit', $learner->ncUserId);
		$action = 'submit';
		if ($submit->isAllowed() === false) {
			$late = $this->guard->check($submission, SubmissionWindowGuard::LATE_ACTION, $learner->ncUserId);
			if ($late->isAllowed() === false) {
				return $this->refused(message: (string)$late->getMessage());
			}

			$action = SubmissionWindowGuard::LATE_ACTION;
		}

		return $this->fire(learner: $learner, submission: $submission, action: $action);
	}//end handIn()

	/**
	 * Run the transition as the pupil; a guard refusal on the write is
	 * answered like one before it.
	 *
	 * @param PortalLearner $learner The pupil.
	 * @param array<string, mixed> $submission The draft.
	 * @param string $action `submit` or `submitLate`.
	 *
	 * @return PortalOutcome
	 *
	 * @throws Throwable Any failure that is not a guard refusal.
	 */
	private function fire(PortalLearner $learner, array $submission, string $action): PortalOutcome {
		$id = (string)$submission['id'];
		try {
			$saved = $this->objectService->runAs(
				user: $learner->user,
				operation: fn () => $this->transitionEngine->transition(objectId: $id, action: $action)
			);
		} catch (Throwable $exception) {
			$message = $this->guardRefusal(exception: $exception);
			if ($message === null) {
				throw $exception;
			}

			return $this->refused(message: $message);
		}

		$lifecycle = 'submitted';
		if ($action === SubmissionWindowGuard::LATE_ACTION) {
			$lifecycle = 'late';
		}

		if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {
			$lifecycle = (string)($saved->jsonSerialize()['lifecycle'] ?? $lifecycle);
		}

		$this->logger->info('[PortalSubmissionHandIn] Handed in submission {id} through the portal ({action}).', ['id' => $id, 'action' => $action]);

		return new PortalOutcome(status: Http::STATUS_OK, body: ['submissionId' => $id, 'lifecycle' => $lifecycle]);
	}//end fire()

	/**
	 * The refusal for a guard message: the late rule gets its own code.
	 *
	 * @param string $message The guard's refusal text.
	 *
	 * @return PortalOutcome
	 */
	private function refused(string $message): PortalOutcome {
		if ($message === SubmissionWindowGuard::DENY_LATE_NOT_ACCEPTED) {
			return new PortalOutcome(
				status: Http::STATUS_UNPROCESSABLE_ENTITY,
				body: ['error' => 'late_not_accepted'],
				reason: 'late-not-accepted'
			);
		}

		return new PortalOutcome(status: Http::STATUS_UNPROCESSABLE_ENTITY, body: ['error' => 'hand_in_refused'], reason: 'hand-in-refused');
	}//end refused()

	/**
	 * The guard's message when an exception is OpenRegister's guard refusal
	 * (its HookStoppedException carries `code: lifecycle-guard-denied`), else null.
	 *
	 * @param Throwable $exception The exception from the transition.
	 *
	 * @return string|null
	 */
	private function guardRefusal(Throwable $exception): ?string {
		if (method_exists($exception, 'getErrors') === false) {
			return null;
		}

		$errors = $exception->getErrors();
		if (is_array($errors) === false || ($errors['code'] ?? '') !== self::GUARD_DENIED) {
			return null;
		}

		return (string)($errors['message'] ?? '');
	}//end guardRefusal()

	/**
	 * Whether the submission is the pupil's: their `learnerRef`, or their
	 * Nextcloud id in `learnerIds`.
	 *
	 * @param PortalLearner $learner The pupil.
	 * @param array<string, mixed> $submission The submission.
	 *
	 * @return bool
	 */
	private function owns(PortalLearner $learner, array $submission): bool {
		if (($submission['learnerRef'] ?? '') === $learner->profileRef) {
			return true;
		}

		$learnerIds = ($submission['learnerIds'] ?? []);

		return is_array($learnerIds) === true && in_array($learner->ncUserId, $learnerIds, true) === true;
	}//end owns()

	/**
	 * One submission by uuid, RBAC off (the receiver has no session), or null.
	 *
	 * @param string $id The uuid.
	 *
	 * @return array<string, mixed>|null
	 */
	private function submission(string $id): ?array {
		if ($id === '') {
			return null;
		}

		try {
			$object = $this->objectService->find(
				id: $id,
				register: self::REGISTER,
				schema: self::SCHEMA,
				_rbac: false,
				_multitenancy: false,
				_render: false
			);
		} catch (DoesNotExistException) {
			return null;
		}

		$row = $object?->jsonSerialize();
		if (is_array($row) === false) {
			return null;
		}

		$row['id'] = (string)($row['id'] ?? ($row['@self']['id'] ?? $id));

		return $row;
	}//end submission()
}//end class
