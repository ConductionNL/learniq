<?php

/**
 * Learniq School Advies Send To ROD Handler
 *
 * IEventListener for SchoolAdvies lifecycle -> `verzonden-naar-rod`
 * (the OR ObjectTransitionedEvent with schema=school-advies,
 * action=verzendenNaarRod).
 *
 * Algorithm:
 * 1. Create a DataExchangeJob in `queued` state: target=bron-rod,
 *    scope.schema=school-advies, scope.filters={learnerId, schoolAdviesId}.
 * 2. Stamp the new job's UUID back onto the SchoolAdvies' dataExchangeJobId.
 *
 * Unlike `SupportRequestSubmitHandler`'s `swv` job, `bron-rod` is not one of
 * `DataExchangeRunHandler`'s gated targets (`DataExchangeJob.lifecycle`'s own
 * description: "Other targets: queued -> running -> ..."), so no
 * pending-parent-review advance is needed here — queued is the job's
 * terminal state from this listener's own point of view; `bron-rod`'s
 * DataExchangeRunHandler/OpenConnector delegation takes it from there,
 * mirroring the same job-type-owns-mapping pattern already established.
 *
 * ADR-031 legitimate exception: cross-schema object creation in response to a
 * lifecycle transition cannot be expressed as schema metadata declarations.
 * Mirrors SupportRequestSubmitHandler's "queue a DataExchangeJob on this
 * trigger" shape exactly, minus the SWV-specific pending-parent-review step.
 *
 * @category Listener
 * @package  OCA\Learniq\Listener
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
 * @spec openspec/changes/po-schooladvies-flow/specs/enrolment/spec.md#requirement-sending-a-definitief-schooladvies-to-rod-auto-queues-the-existing-bron-rod-dataexchangejob
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Auto-queues a DataExchangeJob (target: bron-rod) when a SchoolAdvies is sent to ROD.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/po-schooladvies-flow/specs/enrolment/spec.md#requirement-sending-a-definitief-schooladvies-to-rod-auto-queues-the-existing-bron-rod-dataexchangejob
 */
class SchoolAdviesSendToRodHandler implements IEventListener {

	private const LEARNIQ_REGISTER = 'learniq';
	private const SCHOOL_ADVIES_SCHEMA = 'school-advies';
	private const JOB_SCHEMA = 'data-exchange-job';

	private const ROD_TARGET = 'bron-rod';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR object access service.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an ObjectTransitionedEvent.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/po-schooladvies-flow/specs/enrolment/spec.md#scenario-sending-a-definitief-advies-creates-and-links-a-bron-rod-dataexchangejob
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectTransitionedEvent) === false) {
			return;
		}

		if ($event->getRegister() !== self::LEARNIQ_REGISTER) {
			return;
		}

		if ($event->getSchema() !== self::SCHOOL_ADVIES_SCHEMA) {
			return;
		}

		if ($event->getTo() !== 'verzonden-naar-rod') {
			return;
		}

		$this->queueRodJob(event: $event);

	}//end handle()

	/**
	 * Create and queue the bron-rod DataExchangeJob for the sent SchoolAdvies.
	 *
	 * @param ObjectTransitionedEvent $event The verzonden-naar-rod transition event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/po-schooladvies-flow/specs/enrolment/spec.md#scenario-sending-a-definitief-advies-creates-and-links-a-bron-rod-dataexchangejob
	 */
	private function queueRodJob(ObjectTransitionedEvent $event): void {
		$schoolAdvies = $event->getObject()->jsonSerialize();
		$schoolAdviesId = $schoolAdvies['id'] ?? ($schoolAdvies['uuid'] ?? '');

		if ($schoolAdviesId === '') {
			$this->logger->error(
				'[SchoolAdviesSendToRodHandler] SchoolAdvies has no id — cannot queue bron-rod DataExchangeJob.'
			);
			return;
		}

		$learnerId = (string)($schoolAdvies['learnerId'] ?? '');
		$tenantId = (string)($schoolAdvies['tenant_id'] ?? '');

		if ($learnerId === '') {
			$this->logger->warning(
				'[SchoolAdviesSendToRodHandler] SchoolAdvies {id} has no learnerId — cannot queue bron-rod job.',
				['id' => $schoolAdviesId]
			);
			return;
		}

		$job = $this->buildRodJobPayload(
			learnerId: $learnerId,
			schoolAdviesId: $schoolAdviesId,
			tenantId: $tenantId
		);

		$jobId = $this->persistRodJob(job: $job, schoolAdviesId: $schoolAdviesId);

		if ($jobId === null) {
			return;
		}

		$this->saveSchoolAdviesFields(
			schoolAdviesId: $schoolAdviesId,
			fields: ['dataExchangeJobId' => $jobId]
		);

	}//end queueRodJob()

	/**
	 * Build the DataExchangeJob payload array for the ROD schooladvies delivery.
	 *
	 * @param string $learnerId NC user ID of the learner.
	 * @param string $schoolAdviesId UUID of the SchoolAdvies being sent.
	 * @param string $tenantId Tenant UUID.
	 *
	 * @return array<string,mixed>
	 */
	private function buildRodJobPayload(string $learnerId, string $schoolAdviesId, string $tenantId): array {
		return [
			'direction' => 'export',
			'target' => self::ROD_TARGET,
			'mappingProfileId' => null,
			'scope' => [
				'schema' => self::SCHOOL_ADVIES_SCHEMA,
				'filters' => [
					'learnerId' => $learnerId,
					'schoolAdviesId' => $schoolAdviesId,
				],
				'cohortId' => null,
				'period' => null,
			],
			'requestedBy' => 'system',
			'requestedAt' => date('c'),
			'lifecycle' => 'queued',
			'tenant_id' => $tenantId,
		];

	}//end buildRodJobPayload()

	/**
	 * Save the DataExchangeJob and return its UUID, logging and returning null
	 * on any failure (save failure or a saved row with no resolvable id).
	 *
	 * @param array<string,mixed> $job The job payload to save.
	 * @param string $schoolAdviesId UUID of the originating SchoolAdvies (for logging).
	 *
	 * @return string|null UUID of the saved job, or null on failure.
	 */
	private function persistRodJob(array $job, string $schoolAdviesId): ?string {
		$saved = $this->objectService->saveObject(
			register: self::LEARNIQ_REGISTER,
			schema: self::JOB_SCHEMA,
			object: $job
		);

		$savedJob = $saved->jsonSerialize();

		$jobId = $savedJob['id'] ?? ($savedJob['uuid'] ?? null);

		if (is_string($jobId) === false || $jobId === '') {
			$this->logger->error(
				'[SchoolAdviesSendToRodHandler] bron-rod DataExchangeJob saved but returned no id for SchoolAdvies {id}.',
				['id' => $schoolAdviesId]
			);
			return null;
		}

		return $jobId;
	}//end persistRodJob()

	/**
	 * Persist updated fields on the SchoolAdvies without triggering a lifecycle
	 * event loop (mirrors SupportRequestSubmitHandler::saveSupportRequestFields()).
	 *
	 * @param string $schoolAdviesId UUID of the SchoolAdvies.
	 * @param array<string,mixed> $fields Fields to update.
	 *
	 * @return void
	 */
	private function saveSchoolAdviesFields(string $schoolAdviesId, array $fields): void {
		$existing = $this->objectService->findAll(
			[
				'register' => self::LEARNIQ_REGISTER,
				'schema' => self::SCHOOL_ADVIES_SCHEMA,
				'filters' => ['id' => $schoolAdviesId],
				'limit' => 1,
			]
		);

		if (empty($existing) === true) {
			$this->logger->warning(
				'[SchoolAdviesSendToRodHandler] SchoolAdvies {id} not found for field update.',
				['id' => $schoolAdviesId]
			);
			return;
		}

		$current = $existing[0];
		if (is_array($existing[0]) === false) {
			$current = $existing[0]->jsonSerialize();
		}

		$updated = array_merge($current, $fields);

		$this->objectService->saveObject(
			register: self::LEARNIQ_REGISTER,
			schema: self::SCHOOL_ADVIES_SCHEMA,
			object: $updated
		);

	}//end saveSchoolAdviesFields()
}//end class
