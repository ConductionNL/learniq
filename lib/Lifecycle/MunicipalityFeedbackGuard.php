<?php

/**
 * Learniq Municipality Feedback Guard
 *
 * Lifecycle guard for the DataExchangeJob schema's `recordMunicipalityFeedback`
 * transition — a self-loop (`succeeded` → `succeeded`) used solely to attach a
 * PHP authorisation check to a plain field write. This register has no
 * declarative field-scoped write-authorization extension (`x-property-rbac`
 * only expresses whole-object `read` gates, and `x-openregister-authorization`
 * only expresses whole-operation `create` gates — verified at HEAD, see
 * design.md "Security Considerations"). Mirrors the pattern already used by
 * ExternalTrainingVerificationGuard (role-group check + server-side stamping
 * of identity/timestamp fields, never trusting caller-supplied values).
 *
 * ADR-031 legitimate exception: no `x-openregister-*` extension expresses a
 * field-scoped write-authorization gate on a non-transition update.
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
 * @spec openspec/changes/verzuim-report-composer/tasks.md#task-2.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Guards the DataExchangeJob `recordMunicipalityFeedback` self-loop transition.
 *
 * The transition proceeds only when ALL of the following hold:
 *   1. The acting user is in one of the authorised groups (`admin`, `coordinators`).
 *   2. The job's `target` is `leerplicht` — municipalityFeedback (the MAS-route)
 *      only makes sense for a verzuimloket report to a municipality.
 *
 * `municipalityFeedback.recordedBy` (always the acting user) and
 * `.receivedAt` (only when not supplied) are stamped by
 * MunicipalityFeedbackStampListener after the save: OpenRegister calls guards
 * by value, so a guard can not write (learniq#983).
 *
 * NOTE: this is a self-loop, and OpenRegister's LifecycleValidationListener
 * returns before resolving a guard when the lifecycle value does not change,
 * so today this check does not run (reported on learniq#983). It is kept as a
 * guard so it runs the day OpenRegister guards self-loops.
 *
 * @spec openspec/changes/verzuim-report-composer/tasks.md#task-2.2
 */
class MunicipalityFeedbackGuard implements LifecycleGuardInterface {

	/**
	 * The only DataExchangeJob target municipalityFeedback applies to.
	 */
	private const LEERPLICHT_TARGET = 'leerplicht';

	/**
	 * Groups whose members may record municipality feedback.
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
	 * Assert the recording preconditions.
	 *
	 * @param array<string,mixed> $object The DataExchangeJob as it would be saved (lifecycle stays `succeeded`).
	 * @param string              $action The transition action (`recordMunicipalityFeedback`).
	 * @param string              $userId The caller's uid, or '' without a session.
	 *
	 * @return GuardResult Allow, or deny when the caller or the job does not qualify.
	 *
	 * @spec openspec/changes/verzuim-report-composer/tasks.md#task-2.2
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleGuardInterface's.
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		$target = (string)($object['target'] ?? '');

		if ($userId === '') {
			$this->logger->warning(
				'[MunicipalityFeedbackGuard] No session user — denying recordMunicipalityFeedback.'
			);
			return GuardResult::deny('Only a signed-in admin or coordinator can record municipality feedback.');
		}

		if ($target !== self::LEERPLICHT_TARGET) {
			$this->logger->info(
				'[MunicipalityFeedbackGuard] Job {id} target is {t}, not leerplicht — denying recordMunicipalityFeedback.',
				['id' => $object['id'] ?? '?', 't' => $target]
			);
			return GuardResult::deny('Municipality feedback can only be recorded on a leerplicht report.');
		}

		if ($this->actorIsAuthorised(actor: $userId) === false) {
			$this->logger->info(
				'[MunicipalityFeedbackGuard] Actor {a} is not in an authorised group — denying recordMunicipalityFeedback.',
				['a' => $userId]
			);
			return GuardResult::deny('Only an admin or coordinator can record municipality feedback.');
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
	 * @spec openspec/changes/verzuim-report-composer/tasks.md#task-2.2
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
