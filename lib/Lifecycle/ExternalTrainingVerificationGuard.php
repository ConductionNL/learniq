<?php

/**
 * Learniq External Training Verification Guard
 *
 * Lifecycle guard for the ExternalTrainingRecord schema's
 * `submitted → verified` transition. Called by OpenRegister's lifecycle
 * engine when an officer/HR/admin verifies an externally-completed training.
 *
 * This is a legitimate PHP lifecycle seam per ADR-031 §"Lifecycle guards": the
 * verification gate combines an actor-role check, an evidence-attachment
 * precondition, and a self-verification guard that cannot be expressed
 * declaratively. Per ADR-008 OR emits the audit-trail entry automatically when
 * the transition completes — this guard records nothing itself.
 *
 * Per ADR-022: evidence files are OpenRegister file attachments on the object;
 * this guard reads the attachment list via OR's ObjectService and never stores
 * bytes locally.
 *
 * @category Lifecycle
 * @package  OCA\Learniq\Lifecycle
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/external-training-recording/tasks.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Guards the ExternalTrainingRecord `submitted → verified` transition.
 *
 * The transition proceeds only when ALL of the following hold:
 *   1. The acting user is in one of the privileged groups
 *      (`compliance-officers`, `hr`, `admin`).
 *   2. At least one OpenRegister file attachment (evidence) is present on the
 *      record.
 *   3. The verifier is not the same person who submitted the record when the
 *      record was self-submitted by the learner (`verifiedBy != submittedBy`).
 *
 * `verifiedBy` and `verifiedAt` are stamped by StampTransitionActorAction,
 * declared on the same transition: OpenRegister calls guards by value, so a
 * guard can not write onto the object (learniq#983).
 *
 * @spec openspec/changes/external-training-recording/tasks.md
 */
class ExternalTrainingVerificationGuard implements LifecycleGuardInterface {
	/**
	 * Groups whose members may verify an external-training record.
	 *
	 * @var string[]
	 */
	private const VERIFIER_GROUPS = [
		'admin',
		'compliance-officers',
		'hr',
	];

	/**
	 * Constructor.
	 *
	 * @param IGroupManager $groupManager OR/NC group manager to resolve the
	 *                                    acting user's role groups.
	 * @param IUserManager $userManager User manager to resolve the acting
	 *                                  user object for membership checks.
	 * @param LoggerInterface $logger PSR logger for guard rejections.
	 */
	public function __construct(
		private readonly IGroupManager $groupManager,
		private readonly IUserManager $userManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Assert the verification preconditions.
	 *
	 * Called by OpenRegister's LifecycleValidationListener before the
	 * `submitted → verified` transition is saved.
	 *
	 * @param array<string,mixed> $object The record as it would be saved (lifecycle at `verified`).
	 * @param string              $action The transition action (`verify`).
	 * @param string              $userId The verifier's uid, or '' without a session.
	 *
	 * @return GuardResult Allow, or deny with what is missing.
	 *
	 * @spec openspec/changes/external-training-recording/tasks.md
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleGuardInterface's.
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		$submittedBy = (string)($object['submittedBy'] ?? '');

		if ($userId === '') {
			$this->logger->warning('[ExternalTrainingVerificationGuard] No session user — denying verify.');
			return GuardResult::deny('Only a signed-in compliance officer, HR member or admin can verify outside training.');
		}

		// Step 1 — actor must be in a privileged verifier group.
		if ($this->actorIsVerifier(actor: $userId) === false) {
			$this->logger->info(
				'[ExternalTrainingVerificationGuard] Actor is not in a verifier group — denying verify.',
				['actor' => $userId]
			);
			return GuardResult::deny('Only a compliance officer, HR member or admin can verify outside training.');
		}

		// Step 2 — at least one evidence file attachment must be present.
		if ($this->hasEvidenceAttachment(object: $object) === false) {
			$this->logger->info(
				'[ExternalTrainingVerificationGuard] No evidence attachment present — denying verify.',
				['record' => ($object['id'] ?? '')]
			);
			return GuardResult::deny('The record needs at least one evidence attachment before it can be verified.');
		}

		// Step 3 — a learner self-submission may not be self-verified.
		if ($submittedBy !== '' && $submittedBy === $userId) {
			$this->logger->info(
				'[ExternalTrainingVerificationGuard] Verifier equals submitter (self-verification) — denying verify.',
				['actor' => $userId]
			);
			return GuardResult::deny('The person who submitted the record can not also verify it.');
		}

		return GuardResult::allow();
	}//end check()

	/**
	 * Whether the acting user is in one of the verifier groups.
	 *
	 * @param string $actor NC user ID of the verifier.
	 *
	 * @return bool True when the user is in admin / compliance-officer / hr.
	 */
	private function actorIsVerifier(string $actor): bool {
		$user = $this->userManager->get($actor);
		if ($user === null) {
			return false;
		}

		$actorGroups = $this->groupManager->getUserGroupIds($user);

		return count(array_intersect($actorGroups, self::VERIFIER_GROUPS)) > 0;
	}//end actorIsVerifier()

	/**
	 * Whether the record carries at least one OpenRegister file attachment.
	 *
	 * OR exposes attachments on the serialised object under `@self.files` (the
	 * canonical attachment list) or a legacy `files` array. A non-empty list of
	 * either satisfies the evidence precondition.
	 *
	 * @param array<string,mixed> $object The record property array.
	 *
	 * @return bool True when one or more evidence attachments are present.
	 */
	private function hasEvidenceAttachment(array $object): bool {
		$self = $object['@self'] ?? [];
		if (is_array($self) === true && empty($self['files'] ?? []) === false) {
			return true;
		}

		if (empty($object['files'] ?? []) === false && is_array($object['files']) === true) {
			return true;
		}

		return false;
	}//end hasEvidenceAttachment()
}//end class
