<?php

/**
 * Learniq OSO Import Accept Guard
 *
 * Lifecycle guard for the OsoImportDossier schema's `accept` transition
 * (`under-review → accepted`). An incoming overstapdossier is not proof the
 * transferred data is correct or complete for this school's record — an
 * admin/coordinator confirms it once before it counts as accepted. Mirrors
 * MunicipalityFeedbackGuard's role-check-plus-stamp shape: on success it
 * stamps `reviewedBy`/`reviewedAt` server-side, never trusting a
 * caller-supplied identity/timestamp for this compliance-sensitive field.
 *
 * Accepting does NOT itself create or modify a LearnerProfile — a
 * coordinator who accepts completes the real LearnerProfile through the
 * existing object UI, mirroring LearningRecordImport's own scope boundary
 * (oso-inbound-contract's proposal.md "Why").
 *
 * Referenced from OsoImportDossier.x-openregister-lifecycle.transitions.accept.requires.
 * OR resolves guards by fully-qualified class name from the schema — no
 * Application.php registration needed.
 *
 * ADR-031: single-responsibility guard — solely decides whether `accept` is
 * permitted and stamps the reviewer identity/timestamp.
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
 * Guards the OsoImportDossier `under-review → accepted` lifecycle transition.
 *
 * Only an admin/coordinator may accept a received overstapdossier.
 * `reviewedBy`/`reviewedAt` are stamped by StampTransitionActorAction, declared
 * on the same transition: OpenRegister calls guards by value, so a guard can
 * not write onto the object (learniq#983).
 *
 * @spec openspec/changes/oso-inbound-contract/tasks.md#task-2
 */
class OsoImportAcceptGuard implements LifecycleGuardInterface {

	/**
	 * Groups whose members may accept an OsoImportDossier.
	 *
	 * @var string[]
	 */
	private const AUTHORISED_GROUPS = [
		'admin',
		'coordinators',
	];

	/**
	 * Constructor.
	 *
	 * @param IGroupManager $groupManager OR/NC group manager to resolve the
	 *                                    acting user's role groups.
	 * @param IUserManager $userManager User manager to resolve the acting
	 *                                  user object for membership checks.
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
	 * Allow the `under-review → accepted` transition for an admin/coordinator.
	 *
	 * @param array<string,mixed> $object The OsoImportDossier as it would be saved (status at `accepted`).
	 * @param string              $action The transition action (`accept`).
	 * @param string              $userId The caller's uid, or '' without a session.
	 *
	 * @return GuardResult Allow, or deny when the caller may not accept.
	 *
	 * @spec openspec/changes/oso-inbound-contract/tasks.md#task-2
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleGuardInterface's.
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		if ($userId === '') {
			$this->logger->warning('[OsoImportAcceptGuard] No session user — denying accept.');
			return GuardResult::deny('Only a signed-in admin or coordinator can accept a transfer dossier.');
		}

		if ($this->actorIsAuthorised(actor: $userId) === false) {
			$this->logger->info(
				'[OsoImportAcceptGuard] Actor {a} is not in an authorised group — denying accept of OsoImportDossier {id}.',
				['a' => $userId, 'id' => $object['id'] ?? '?']
			);
			return GuardResult::deny('Only an admin or coordinator can accept a transfer dossier.');
		}

		return GuardResult::allow();
	}//end check()

	/**
	 * Whether the acting user is in one of the authorised groups.
	 *
	 * @param string $actor NC user ID of the requester.
	 *
	 * @return bool True when the user is in admin / coordinators.
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
