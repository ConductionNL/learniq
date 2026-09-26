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

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Guards the OsoImportDossier `under-review → accepted` lifecycle transition.
 *
 * Only an admin/coordinator may accept a received overstapdossier; on
 * success `reviewedBy`/`reviewedAt` are stamped into the payload.
 *
 * @spec openspec/changes/oso-inbound-contract/tasks.md#task-2
 */
class OsoImportAcceptGuard {

	/**
	 * Groups whose members may accept an OsoImportDossier.
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
	 * Allow the `under-review → accepted` transition and stamp the reviewer.
	 *
	 * @param array<string,mixed> $transitionContext Context provided by OR's lifecycle engine:
	 *                                               - 'object'     : the OsoImportDossier data array
	 *                                               - 'actor'      : NC user ID of the requester
	 *                                               - 'transition' : 'accept'
	 *                                               - 'payload'    : mutable array; reviewedBy/reviewedAt
	 *                                               are written here
	 *
	 * @return bool True when the actor is admin/coordinator; false otherwise.
	 *
	 * @spec openspec/changes/oso-inbound-contract/tasks.md#task-2
	 */
	public function check(array &$transitionContext): bool {
		$object = $transitionContext['object'] ?? [];
		$actor = (string)($transitionContext['actor'] ?? '');

		if ($actor === '') {
			$this->logger->warning('[OsoImportAcceptGuard] No actor in transitionContext — denying accept.');
			return false;
		}

		if ($this->actorIsAuthorised(actor: $actor) === false) {
			$this->logger->info(
				'[OsoImportAcceptGuard] Actor {a} is not in an authorised group — denying accept of OsoImportDossier {id}.',
				['a' => $actor, 'id' => $object['id'] ?? '?']
			);
			return false;
		}

		$transitionContext['payload']['reviewedBy'] = $actor;
		$transitionContext['payload']['reviewedAt'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);

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
