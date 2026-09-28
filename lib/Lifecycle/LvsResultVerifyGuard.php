<?php

/**
 * Learniq LVS Result Verify Guard
 *
 * Lifecycle guard for the LvsResult schema's `verify` transition
 * (`imported → verified`). An LVS (leerlingvolgsysteem) result arrives via an
 * automated UWLR import (DataExchangeJob target: lvs-results) — the import
 * itself is not proof the row is trustworthy report-card/trend input, so an
 * admin/coordinator confirms it once before other features treat it as
 * verified. Mirrors MunicipalityFeedbackGuard's role-check shape (same
 * AUTHORISED_GROUPS), without the field-stamping half — `verify` has no
 * payload fields to stamp, unlike the recordMunicipalityFeedback self-loop.
 *
 * Referenced from LvsResult.x-openregister-lifecycle.transitions.verify.requires.
 * OR resolves guards by fully-qualified class name from the schema — no
 * Application.php registration needed.
 *
 * ADR-031: single-responsibility guard — solely decides whether the `verify`
 * transition is permitted based on the actor's role.
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
 * @spec openspec/changes/archive/2026-09-28-lvs-import-contract/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Guards the LvsResult `imported → verified` lifecycle transition.
 *
 * Only an admin/coordinator may confirm an imported LVS result as verified.
 *
 * @spec openspec/changes/archive/2026-09-28-lvs-import-contract/tasks.md#task-2
 */
class LvsResultVerifyGuard implements LifecycleGuardInterface {

	/**
	 * Reason shown to the caller when the transition is refused.
	 *
	 * @var string
	 */
	private const DENIAL = 'Only an administrator or coordinator can verify an imported result.';

	/**
	 * Groups whose members may verify an imported LvsResult.
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
	 * Authorise or deny the transition this guard is named on (LifecycleGuardInterface).
	 *
	 * @param array<string,mixed> $object The object at its target state, transition inputs merged in.
	 * @param string $action The transition action being applied.
	 * @param string $userId The uid of the caller.
	 *
	 * @return GuardResult Allow, or deny with the reason shown to the caller.
	 *
	 * @spec openspec/changes/archive/2026-09-28-lvs-import-contract/tasks.md#task-2
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleGuardInterface's.
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		if ($this->allows(object: $object, userId: $userId) === true) {
			return GuardResult::allow();
		}

		return GuardResult::deny(self::DENIAL);
	}//end check()

	/**
	 * Allow the `imported → verified` transition.
	 *
	 * Called by OpenRegister's lifecycle engine before executing the `verify`
	 * transition. Returns true only when the acting user is in one of
	 * AUTHORISED_GROUPS.
	 *
	 * @param array<string,mixed> $object The object at its target state, transition inputs merged in.
	 * @param string $userId The uid of the caller.
	 *
	 * @return bool True when the actor is admin/coordinator; false otherwise.
	 *
	 * @spec openspec/changes/archive/2026-09-28-lvs-import-contract/tasks.md#task-2
	 */
	private function allows(array $object, string $userId): bool {
		$actor = $userId;

		if ($actor === '') {
			$this->logger->warning('[LvsResultVerifyGuard] No acting user — denying verify.');
			return false;
		}

		if ($this->actorIsAuthorised(actor: $actor) === false) {
			$this->logger->info(
				'[LvsResultVerifyGuard] Actor {a} is not in an authorised group — denying verify of LvsResult {id}.',
				['a' => $actor, 'id' => $object['id'] ?? '?']
			);
			return false;
		}

		return true;
	}//end allows()

	/**
	 * Whether the acting user is in one of the authorised groups.
	 *
	 * @param string $actor NC user ID of the requester.
	 *
	 * @return bool True when the user is in admin / coordinators.
	 *
	 * @spec openspec/changes/archive/2026-09-28-lvs-import-contract/tasks.md#task-2
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
