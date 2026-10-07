<?php

/**
 * Learniq Course Evaluation Eligibility Guard
 *
 * Lifecycle guard for the CourseEvaluationResponse schema's `submit` transition
 * (`draft → submitted`). Enforces that the caller holds an eligible, not-yet-
 * responded EvaluationInvitation for the response's campaignId — the same
 * check structurally prevents both an uninvited submission and a second
 * submission from the same learner for the same campaign.
 *
 * Legitimate PHP per ADR-031: "Lifecycle guard — business rule that must run
 * before a state transition and cannot be expressed as a schema declaration."
 * Mirrors ConferenceSignupGuardianGuard's shape exactly: resolves the
 * caller's identity server-side via IUserSession (never a client-supplied
 * claim), looks it up against a *different* schema (EvaluationInvitation)
 * via ObjectService::findAll(), and never reads or writes any identity
 * field onto the CourseEvaluationResponse object itself — the object this
 * guard runs against carries no learnerId/submittedBy property to read from
 * or write to in the first place (design.md Decision 2, "anonymity vs.
 * targeted reminders — the crux mechanism").
 *
 * Referenced from CourseEvaluationResponse's
 * x-openregister-lifecycle.transitions.submit.requires in learniq_register.json.
 *
 * @category Lifecycle
 * @package  OCA\Learniq\Lifecycle
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
 * @spec openspec/specs/course-evaluation/spec.md#requirement-eligibility-and-duplicate-submission-are-blocked-by-a-lifecycle-guard
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Guards the CourseEvaluationResponse `submit` transition.
 *
 * Passes only when the caller (resolved via IUserSession) holds exactly one
 * EvaluationInvitation for the response's campaignId with
 * hasResponded:false. Fails closed on any lookup miss — no session, no
 * matching invitation, or an already-responded invitation all block the
 * transition.
 */
class CourseEvaluationEligibilityGuard implements LifecycleGuardInterface {

	/**
	 * Reason shown to the caller when the transition is refused.
	 *
	 * @var string
	 */
	private const DENIAL = 'You have no open invitation for this course evaluation.';

	/**
	 * OR register slug for Learniq objects.
	 */
	private const LEARNIQ_REGISTER = 'learniq';

	/**
	 * OR schema slug for EvaluationInvitation.
	 */
	private const EVALUATION_INVITATION_SCHEMA = 'evaluation-invitation';

	/**
	 * The fields of a response that must equal the caller's invitation.
	 *
	 * Read from the shipped fragments: evaluation-invitation carries these
	 * (plus campaignId and tenant_id, which the lookup already filters on)
	 * and CourseEvaluationResponseBuilder copies exactly these onto the row.
	 *
	 * @var array<int, string>
	 */
	private const PINNED_FIELDS = ['courseId', 'cohortId', 'academicYear', 'period'];

	/**
	 * Constructor.
	 *
	 * @param IUserSession $userSession Current NC user session (server-resolved caller identity).
	 * @param ObjectService $objectService OR object access service.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Authorise or deny the transition this guard is named on (LifecycleGuardInterface).
	 *
	 * @param array<string,mixed> $object The object at its target state, transition inputs merged in.
	 * @param string $action The transition action being applied.
	 * @param string $userId The uid of the caller.
	 *
	 * @return GuardResult Allow, or deny with the reason shown to the caller.
	 *
	 * @spec openspec/specs/course-evaluation/spec.md#requirement-eligibility-and-duplicate-submission-are-blocked-by-a-lifecycle-guard
	 * @spec openspec/specs/course-evaluation/spec.md#requirement-a-response-is-anonymous-by-schema-shape-not-by-rbac
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleGuardInterface's.
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		if ($this->allows(object: $object) === true) {
			return GuardResult::allow();
		}

		return GuardResult::deny(self::DENIAL);
	}//end check()

	/**
	 * The rule behind check(), answered as a boolean.
	 *
	 * Called by OpenRegister's lifecycle engine before executing the `submit`
	 * transition on a CourseEvaluationResponse object. Resolves the caller's
	 * NC user id from the session (never from the request payload, and never
	 * from the CourseEvaluationResponse object itself — it has no identity
	 * field to read from) and passes only when that user holds an eligible,
	 * not-yet-responded EvaluationInvitation for the response's campaignId.
	 *
	 * @param array<string,mixed> $object The object at its target state, transition inputs merged in.
	 *
	 * @return bool True if the caller may submit this response; false blocks the transition.
	 *
	 * @spec openspec/specs/course-evaluation/spec.md#requirement-eligibility-and-duplicate-submission-are-blocked-by-a-lifecycle-guard
	 * @spec openspec/specs/course-evaluation/spec.md#requirement-a-response-is-anonymous-by-schema-shape-not-by-rbac
	 */
	private function allows(array $object): bool {
		$campaignId = $object['campaignId'] ?? '';

		if ($campaignId === '') {
			$this->logger->warning(
				'[CourseEvaluationEligibilityGuard] CourseEvaluationResponse has no campaignId; blocking submit.'
			);
			return false;
		}

		$user = $this->userSession->getUser();

		if ($user === null) {
			$this->logger->info(
				'[CourseEvaluationEligibilityGuard] No authenticated user in session; blocking submit.'
			);
			return false;
		}

		$callerUid = $user->getUID();
		$tenantId = $object['tenant_id'] ?? '';

		$filters = [
			'campaignId' => $campaignId,
			'learnerId' => $callerUid,
		];
		if ($tenantId !== '') {
			$filters['tenant_id'] = $tenantId;
		}

		$invitations = $this->objectService->findAll(
			[
				'filters' => array_merge(
					$filters,
					[
						'register' => self::LEARNIQ_REGISTER,
						'schema' => self::EVALUATION_INVITATION_SCHEMA,
					]
				),
				'limit' => 50,
			]
		);

		// Answered invitations are skipped here, not filtered in the query:
		// OpenRegister binds a `hasResponded => false` filter as '' and
		// PostgreSQL refuses that for a boolean column (live pass D5).
		$invitations = array_values(
			array_filter(
				$invitations,
				static function ($invitation): bool {
					$data = $invitation;
					if (is_array($invitation) === false) {
						$data = $invitation->jsonSerialize();
					}

					return ($data['hasResponded'] ?? false) !== true;
				}
			)
		);

		if (empty($invitations) === true) {
			$this->logger->info(
				'[CourseEvaluationEligibilityGuard] Caller {caller} has no eligible EvaluationInvitation for '
				. 'campaign {campaignId} (no invitation, or already responded); blocking submit (fail closed).',
				['caller' => $callerUid, 'campaignId' => $campaignId]
			);
			return false;
		}

		foreach ($invitations as $invitation) {
			if (self::describes(invitation: $invitation, object: $object) === true) {
				return true;
			}
		}

		$this->logger->info(
			'[CourseEvaluationEligibilityGuard] The response submitted by {caller} for campaign {campaignId} does not '
			. 'match any of their open invitations (course, cohort, teacher, year or period differs); blocking submit.',
			['caller' => $callerUid, 'campaignId' => $campaignId]
		);
		return false;
	}//end allows()

	/**
	 * Whether the response row is the one this invitation describes.
	 *
	 * An invitation pins the course, the cohort, the academic year and the
	 * period (campaignId and tenant are already in the lookup). It names no
	 * teacher, and the answer page writes none, so a row naming a teacher is
	 * not one an invitation describes. Without this, a draft pointed at another
	 * course, cohort or teacher of the same campaign could be submitted. Since
	 * the submit runs as the system (DECISIONS row 63) this guard is the only
	 * caller check inside the transition, so it stays strict.
	 *
	 * @param mixed               $invitation An open invitation of the caller (entity or array).
	 * @param array<string,mixed> $object     The response row at its target state.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/course-evaluation/spec.md#requirement-eligibility-and-duplicate-submission-are-blocked-by-a-lifecycle-guard
	 */
	private static function describes(mixed $invitation, array $object): bool {
		$data = $invitation;
		if (is_array($invitation) === false) {
			$data = $invitation->jsonSerialize();
		}

		foreach (self::PINNED_FIELDS as $field) {
			if (self::text(value: ($data[$field] ?? null)) !== self::text(value: ($object[$field] ?? null))) {
				return false;
			}
		}

		return self::text(value: ($object['teacherId'] ?? null)) === '';
	}//end describes()

	/**
	 * A field value as comparable text; absent, null and '' are all ''.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return string
	 */
	private static function text(mixed $value): string {
		if (is_scalar($value) === false) {
			return '';
		}

		return trim((string)$value);
	}//end text()
}//end class
