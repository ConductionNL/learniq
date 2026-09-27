<?php

/**
 * Learniq Rejection Resubmit Guard
 *
 * Lifecycle guard for the ExchangeRejection schema's `resubmit` transition
 * (`corrected` → `resubmitted`). Mirrors MunicipalityFeedbackGuard's
 * role-check + server-side-stamp shape, with one addition: on success it also
 * creates a single new DataExchangeJob scoped to exactly this rejection's
 * source object (`scope.filters.id = sourceObjectId`), reusing the existing
 * generic filter mechanism — never a batched multi-record resubmission (see
 * design.md "Per-rejection resubmission, not a multi-select batch action").
 *
 * ADR-031 legitimate exception: this register has no declarative mechanism
 * to express "on this transition, create a new sibling object scoped to a
 * $ref field on the transitioning object" — a PHP guard is the only proven
 * mechanism (same rationale as MunicipalityFeedbackGuard's field-scoped
 * write-authorization gap).
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
 * @spec openspec/changes/duo-afkeurmelding-correction/tasks.md#task-2.3
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Guards the ExchangeRejection `corrected → resubmitted` transition.
 *
 * The transition proceeds only when the acting user is in one of the
 * authorised groups (`admin`, `coordinators`), the rejection's sourceKind is
 * supported, and its source object and originating DataExchangeJob resolve.
 * The new scoped DataExchangeJob and the `resubmittedJobId` link are written by
 * RejectionResubmissionAction, declared on the same transition: OpenRegister
 * calls guards by value, so a guard can not write (learniq#983).
 *
 * @spec openspec/changes/duo-afkeurmelding-correction/tasks.md#task-2.3
 * @spec openspec/changes/duo-afkeurmelding-correction/specs/data-exchange/spec.md#scenario-resubmit-creates-exactly-one-scoped-job-and-stamps-the-link
 */
class RejectionResubmitGuard implements LifecycleGuardInterface {

	private const LEARNIQ_REGISTER = 'learniq';
	private const JOB_SCHEMA = 'data-exchange-job';

	/**
	 * Groups whose members may resubmit a corrected rejection.
	 *
	 * @var string[]
	 */
	private const AUTHORISED_GROUPS = [
		'admin',
		'coordinators',
	];

	/**
	 * Maps ExchangeRejection.sourceKind to the typed $ref id field carrying
	 * the source object's id. Mirrors RejectionMappingHandler's own map;
	 * RejectionResubmissionAction reads it too.
	 *
	 * @var array<string,string>
	 */
	public const SOURCE_KIND_FIELD_MAP = [
		'learner-profile' => 'learnerProfileId',
		'enrolment' => 'enrolmentId',
		'final-grade' => 'finalGradeId',
		'attendance-flag' => 'attendanceFlagId',
		'support-request' => 'supportRequestId',
	];

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR object access service.
	 * @param IGroupManager $groupManager NC group manager to resolve the acting user's role groups.
	 * @param IUserManager $userManager User manager to resolve the acting user object for membership checks.
	 * @param LoggerInterface $logger PSR logger for guard rejections.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly IGroupManager $groupManager,
		private readonly IUserManager $userManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Assert the resubmission preconditions.
	 *
	 * Called by OpenRegister's LifecycleValidationListener before the
	 * `corrected → resubmitted` transition is saved.
	 *
	 * @param array<string,mixed> $object The ExchangeRejection as it would be saved (status at `resubmitted`).
	 * @param string              $action The transition action (`resubmit`).
	 * @param string              $userId The caller's uid, or '' without a session.
	 *
	 * @return GuardResult Allow, or deny with what is missing.
	 *
	 * @spec openspec/changes/duo-afkeurmelding-correction/tasks.md#task-2.3
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleGuardInterface's.
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		$rejectionId = $object['id'] ?? ($object['uuid'] ?? '?');

		if ($userId === '') {
			$this->logger->warning(
				'[RejectionResubmitGuard] No session user — denying resubmit of {id}.',
				['id' => $rejectionId]
			);
			return GuardResult::deny('Only a signed-in admin or coordinator can resubmit a rejection.');
		}

		if ($this->actorIsAuthorised(actor: $userId) === false) {
			$this->logger->info(
				'[RejectionResubmitGuard] Actor {a} is not in an authorised group — denying resubmit of {id}.',
				['a' => $userId, 'id' => $rejectionId]
			);
			return GuardResult::deny('Only an admin or coordinator can resubmit a rejection.');
		}

		$sourceKind = (string)($object['sourceKind'] ?? '');
		$sourceField = self::SOURCE_KIND_FIELD_MAP[$sourceKind] ?? null;

		if ($sourceField === null) {
			$this->logger->warning(
				'[RejectionResubmitGuard] ExchangeRejection {id} has unsupported sourceKind "{kind}" — denying resubmit.',
				['id' => $rejectionId, 'kind' => $sourceKind]
			);
			return GuardResult::deny('This kind of rejection can not be resubmitted.');
		}

		$sourceObjectId = (string)($object[$sourceField] ?? '');
		$originalJobId = (string)($object['dataExchangeJobId'] ?? '');

		if ($sourceObjectId === '' || $originalJobId === '') {
			$this->logger->warning(
				'[RejectionResubmitGuard] ExchangeRejection {id} is missing {field} or dataExchangeJobId — denying resubmit.',
				['id' => $rejectionId, 'field' => $sourceField]
			);
			return GuardResult::deny('The rejection names no source record or no original exchange job, so it can not be resubmitted.');
		}

		$tenantId = (string)($object['tenant_id'] ?? '');
		if ($this->loadOriginalJob(jobId: $originalJobId, tenantId: $tenantId) === null) {
			$this->logger->warning(
				'[RejectionResubmitGuard] Originating DataExchangeJob {job} for rejection {id} could not be '
				. 'resolved — denying resubmit.',
				['job' => $originalJobId, 'id' => $rejectionId]
			);
			return GuardResult::deny('The original exchange job of this rejection can not be found.');
		}

		return GuardResult::allow();
	}//end check()

	/**
	 * Load the originating DataExchangeJob referenced by an ExchangeRejection.
	 *
	 * @param string $jobId UUID of the originating DataExchangeJob.
	 * @param string $tenantId Tenant ID to enforce as a mandatory filter.
	 *
	 * @return array<string,mixed>|null The job data, or null if not found.
	 *
	 * @spec openspec/changes/duo-afkeurmelding-correction/tasks.md#task-2.3
	 */
	private function loadOriginalJob(string $jobId, string $tenantId): ?array {
		$filters = ['id' => $jobId];
		if ($tenantId !== '') {
			$filters['tenant_id'] = $tenantId;
		}

		$results = $this->objectService->findAll(
			[
				'register' => self::LEARNIQ_REGISTER,
				'schema' => self::JOB_SCHEMA,
				'filters' => $filters,
				'limit' => 1,
			]
		);

		if (empty($results) === true) {
			return null;
		}

		if (is_array($results[0]) === true) {
			return $results[0];
		}

		return $results[0]->jsonSerialize();
	}//end loadOriginalJob()

	/**
	 * Whether the acting user is in one of the authorised groups.
	 *
	 * @param string $actor NC user ID of the requester.
	 *
	 * @return bool True when the user is in admin / coordinator.
	 *
	 * @spec openspec/changes/duo-afkeurmelding-correction/tasks.md#task-2.3
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
