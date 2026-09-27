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

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Guards the OsoImportDossier `under-review → rejected` lifecycle transition.
 *
 * The transition proceeds only when BOTH of the following hold:
 *   1. The acting user is in one of the authorised groups (`admin`, `coordinator`).
 *   2. `transitionContext['payload']['rejectionReason']` is a non-empty string.
 *
 * On success it stamps `reviewedBy`/`reviewedAt` server-side.
 *
 * @spec openspec/changes/oso-inbound-contract/tasks.md#task-2
 * @spec openspec/changes/oso-inbound-contract/specs/data-exchange/spec.md#scenario-rejecting-without-a-reason-is-refused
 */
class OsoImportRejectGuard {

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
	 * Assert the reject preconditions and stamp reviewedBy/reviewedAt.
	 *
	 * @param array<string,mixed> $transitionContext Context provided by OR's
	 *                                               lifecycle engine. Expected
	 *                                               keys:
	 *                                               - 'object'  : the
	 *                                               OsoImportDossier data array
	 *                                               - 'actor'   : NC user ID of
	 *                                               the requester
	 *                                               - 'payload' : mutable array;
	 *                                               rejectionReason is read
	 *                                               from here, reviewedBy/
	 *                                               reviewedAt are written here
	 *
	 * @return bool True when the transition is allowed; false blocks it.
	 *
	 * @spec openspec/changes/oso-inbound-contract/tasks.md#task-2
	 */
	public function check(array &$transitionContext): bool {
		$dossier = $transitionContext['object'] ?? [];
		$dossierId = $dossier['id'] ?? ($dossier['uuid'] ?? '?');
		$actor = (string)($transitionContext['actor'] ?? '');

		if ($actor === '') {
			$this->logger->warning(
				'[OsoImportRejectGuard] No actor in transitionContext — denying reject of {id}.',
				['id' => $dossierId]
			);
			return false;
		}

		if ($this->actorIsAuthorised(actor: $actor) === false) {
			$this->logger->info(
				'[OsoImportRejectGuard] Actor {a} is not in an authorised group — denying reject of {id}.',
				['a' => $actor, 'id' => $dossierId]
			);
			return false;
		}

		$payload = $transitionContext['payload'] ?? [];
		if (is_array($payload) === false) {
			$payload = [];
		}

		$rejectionReason = $payload['rejectionReason'] ?? null;

		if (is_string($rejectionReason) === false || trim($rejectionReason) === '') {
			$this->logger->info(
				'[OsoImportRejectGuard] OsoImportDossier {id}: rejectionReason is empty — denying reject.',
				['id' => $dossierId]
			);
			return false;
		}

		$payload['reviewedBy'] = $actor;
		$payload['reviewedAt'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);

		$transitionContext['payload'] = $payload;

		return true;
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
