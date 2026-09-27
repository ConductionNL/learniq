<?php

/**
 * Learniq RejectionResubmissionAction
 *
 * Transition action for ExchangeRejection.resubmit: creates the one
 * DataExchangeJob scoped to the rejection's source object and links it.
 *
 * @category Lifecycle
 * @package  OCA\Learniq\Lifecycle\Action
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle\Action;

use OCA\Learniq\Lifecycle\RejectionResubmitGuard;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUserSession;
use RuntimeException;

/**
 * Creates exactly one new DataExchangeJob (target and mappingProfileId copied
 * from the originating job, scope narrowed to the rejection's source object)
 * and writes its id to `resubmittedJobId`, always server-side.
 *
 * This used to happen inside RejectionResubmitGuard, which wrote the id into a
 * mutable payload. OpenRegister calls guards by value and its guard interface
 * forbids mutation, so the guard now only checks and this action writes:
 * OpenRegister's LifecycleActionListener merges the returned array into the
 * rejection it saves (learniq#983). It runs after the guard allowed the
 * transition, so it throws on anything it can not do rather than skip.
 *
 * @spec openspec/specs/data-exchange/spec.md#requirement-inline-correction-worklist-with-per-rejection-resubmission
 */
class RejectionResubmissionAction implements LifecycleActionInterface {

	private const LEARNIQ_REGISTER = 'learniq';
	private const JOB_SCHEMA = 'data-exchange-job';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR object access service.
	 * @param IUserSession  $userSession   The session whose user resubmits (the new job's requestedBy).
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * Create the scoped job and stamp its id onto the rejection.
	 *
	 * @param array<string,mixed> $objectData   The rejection after its status moved to `resubmitted`.
	 * @param array<string,mixed> $previousData The rejection before the transition (unused).
	 * @param array<string,mixed> $parameters   The declared `actionParameters` (unused).
	 * @param string              $actionName   The declared action name.
	 *
	 * @return array<string,mixed> The rejection with `resubmittedJobId` set.
	 *
	 * @throws RuntimeException When there is no session user, the source or originating job can not be resolved, or the save yields no id.
	 *
	 * @spec openspec/changes/duo-afkeurmelding-correction/specs/data-exchange/spec.md#scenario-resubmit-creates-exactly-one-scoped-job-and-stamps-the-link
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleActionInterface's.
	 */
	public function execute(array $objectData, array $previousData, array $parameters, string $actionName): array {
		$rejectionId = (string)($objectData['id'] ?? ($objectData['uuid'] ?? '?'));

		$actor = (string)($this->userSession->getUser()?->getUID() ?? '');
		if ($actor === '') {
			throw new RuntimeException(sprintf('Resubmitting rejection %s needs a signed-in user.', $rejectionId));
		}

		$sourceKind = (string)($objectData['sourceKind'] ?? '');
		$sourceField = (RejectionResubmitGuard::SOURCE_KIND_FIELD_MAP[$sourceKind] ?? null);
		$sourceObjectId = '';
		if ($sourceField !== null) {
			$sourceObjectId = (string)($objectData[$sourceField] ?? '');
		}

		$originalJobId = (string)($objectData['dataExchangeJobId'] ?? '');
		$tenantId = (string)($objectData['tenant_id'] ?? '');

		$originalJob = null;
		if ($sourceObjectId !== '' && $originalJobId !== '') {
			$originalJob = $this->loadOriginalJob(jobId: $originalJobId, tenantId: $tenantId);
		}

		if ($originalJob === null) {
			throw new RuntimeException(
				sprintf('Rejection %s names no resolvable source object or originating job, so it can not be resubmitted.', $rejectionId)
			);
		}

		$saved = $this->objectService->saveObject(
			register: self::LEARNIQ_REGISTER,
			schema: self::JOB_SCHEMA,
			object: [
				'direction' => 'export',
				'target' => $originalJob['target'] ?? '',
				'mappingProfileId' => $originalJob['mappingProfileId'] ?? null,
				'scope' => [
					'schema' => $sourceKind,
					'filters' => ['id' => $sourceObjectId],
					'cohortId' => null,
					'period' => null,
				],
				'requestedBy' => $actor,
				'requestedAt' => date('c'),
				'lifecycle' => 'queued',
				'tenant_id' => $tenantId,
			]
		);

		$savedJob = $saved->jsonSerialize();
		$newJobId = $savedJob['id'] ?? ($savedJob['uuid'] ?? null);

		if (is_string($newJobId) === false || $newJobId === '') {
			throw new RuntimeException(sprintf('The resubmission job for rejection %s was saved without an id.', $rejectionId));
		}

		$objectData['resubmittedJobId'] = $newJobId;

		return $objectData;
	}//end execute()

	/**
	 * Load the originating DataExchangeJob referenced by an ExchangeRejection.
	 *
	 * @param string $jobId    UUID of the originating DataExchangeJob.
	 * @param string $tenantId Tenant ID to enforce as a mandatory filter.
	 *
	 * @return array<string,mixed>|null The job data, or null if not found.
	 */
	private function loadOriginalJob(string $jobId, string $tenantId): ?array {
		$filters = ['id' => $jobId];
		if ($tenantId !== '') {
			$filters['tenant_id'] = $tenantId;
		}

		$results = $this->objectService->findAll(
			[
				'filters' => array_merge(
					$filters,
					[
						'register' => self::LEARNIQ_REGISTER,
						'schema' => self::JOB_SCHEMA,
					]
				),
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
}//end class
