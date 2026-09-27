<?php

/**
 * Learniq OSO Import Reject Guard
 *
 * Lifecycle guard for the OsoImportDossier schema's `reject` transition
 * (`under-review → rejected`). Mirrors RejectionWaiveGuard's mandatory-reason
 * enforcement and MunicipalityFeedbackGuard's role-check + server-side-stamp
 * shape: requires a non-empty `rejectionReason` and stamps
 * `reviewedBy`/`reviewedAt` server-side, never trusting a caller-supplied
 * identity/timestamp for this compliance-sensitive field.
 *
 * ADR-031 legitimate exception: this register has no declarative mechanism
 * to express "block this transition unless a companion payload field is a
 * non-empty string" — a PHP guard is the only proven mechanism (same
 * rationale as RejectionWaiveGuard/PupilVoiceGuard).
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
 * @spec openspec/changes/oso-inbound-contract/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Guards the OsoImportDossier `under-review → rejected` lifecycle transition.
 *
 * The transition proceeds only when BOTH of the following hold:
 *   1. The acting user is in one of the authorised groups (`admin`, `coordinator`).
 *   2. `rejectionReason` is a non-empty string. It arrives as a declared input
 *      of the reject transition, merged into the object before the guard runs.
 *
 * `reviewedBy`/`reviewedAt` are stamped by StampTransitionActorAction, declared
 * on the same transition: OpenRegister calls guards by value, so a guard can
 * not write onto the object (learniq#983).
 *
 * @spec openspec/changes/oso-inbound-contract/tasks.md#task-2
 * @spec openspec/changes/oso-inbound-contract/specs/data-exchange/spec.md#scenario-rejecting-without-a-reason-is-refused
 */
class OsoImportRejectGuard implements LifecycleGuardInterface {

	/**
	 * The transition inputs the caller sends that this guard reads; each is
	 * declared in `inputs` on every transition naming this class.
	 *
	 * @var list<string>
	 */
	public const TRANSITION_INPUTS = ['rejectionReason'];

	/**
	 * Groups whose members may reject an OsoImportDossier.
	 *
	 * @var string[]
	 */
	private const AUTHORISED_GROUPS = [
		'admin',
		'coordinator',
	];

	/**
	 * Constructor.
	 *
	 * @param IGroupManager $groupManager NC group manager to resolve the acting user's role groups.
	 * @param IUserManager $userManager User manager to resolve the acting user object for membership checks.
	 * @param LoggerInterface $logger PSR logger for guard rejections.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IGroupManager $groupManager,
		private readonly IUserManager $userManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Assert the reject preconditions.
	 *
	 * @param array<string,mixed> $object The OsoImportDossier as it would be saved (status at `rejected`, inputs merged).
	 * @param string              $action The transition action (`reject`).
	 * @param string              $userId The caller's uid, or '' without a session.
	 *
	 * @return GuardResult Allow, or deny with what is missing.
	 *
	 * @spec openspec/changes/oso-inbound-contract/tasks.md#task-2
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		$dossierId = $object['id'] ?? ($object['uuid'] ?? '?');

		if ($userId === '') {
			$this->logger->warning(
				'[OsoImportRejectGuard] No session user — denying reject of {id}.',
				['id' => $dossierId]
			);
			return GuardResult::deny('Only a signed-in admin or coordinator can reject a transfer dossier.');
		}

		if ($this->actorIsAuthorised(actor: $userId) === false) {
			$this->logger->info(
				'[OsoImportRejectGuard] Actor {a} is not in an authorised group — denying reject of {id}.',
				['a' => $userId, 'id' => $dossierId]
			);
			return GuardResult::deny('Only an admin or coordinator can reject a transfer dossier.');
		}

		$rejectionReason = $object['rejectionReason'] ?? null;

		if (is_string($rejectionReason) === false || trim($rejectionReason) === '') {
			$this->logger->info(
				'[OsoImportRejectGuard] OsoImportDossier {id}: rejectionReason is empty — denying reject.',
				['id' => $dossierId]
			);
			return GuardResult::deny('A transfer dossier can only be rejected with a reason.');
		}

		return GuardResult::allow();
	}//end check()

	/**
	 * Whether the acting user is in one of the authorised groups.
	 *
	 * @param string $actor NC user ID of the requester.
	 *
	 * @return bool True when the user is in admin / coordinator.
	 *
	 * @spec openspec/changes/oso-inbound-contract/tasks.md#task-2
	 */
	private function actorIsAuthorised(string $actor): bool {
		$user = $this->userManager->get($actor);
		if ($user === null) {
			return false;
		}

		$actorGroups = $this->groupManager->getUserGroupIds($user);

		return count(array_intersect($actorGroups, self::AUTHORISED_GROUPS)) > 0;
	}//end actorIsAuthorised()
}//end class
