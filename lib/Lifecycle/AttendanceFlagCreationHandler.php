<?php

/**
 * Learniq Attendance Flag Creation Handler
 *
 * Listens for OpenRegister's ObjectTransitionedEvent for the special
 * `threshold-crossed` marker event when an AttendanceThreshold's
 * `isThresholdCrossed` calculatedChange flips to true.
 *
 * On a crossing event this handler:
 * 1. Resolves the learner's mentor from LearnerProfile.managerId.
 * 2. Creates an AttendanceFlag (`open`) with windowStart/windowEnd/
 *    metricValue/breachingRecordIds/mentorId.
 * 3. When the threshold names a dataExchangeTarget, asks integriq for the
 *    exchange job and stamps its id on the flag. The job waits in integriq
 *    until a person takes the flag up (learniq's exchange gate).
 *
 * IMPORTANT: This handler ONLY creates the flag. It NEVER auto-acts
 * against the learner. The mentor's intervention and any outbound report
 * are tracked via the flag's own lifecycle (open → in-handling → reported
 * → resolved). Human-in-the-loop throughout — mirrors the proctoring-flag
 * rule from the assessment spec (ADR-008).
 *
 * ADR-031 legitimate exception: new-object creation in response to a
 * calculatedChange event cannot be expressed as schema metadata declarations.
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
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-10
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-11
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use OCA\Learniq\Service\IntegriqExchangeClient;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Creates an AttendanceFlag when an AttendanceThreshold crossing is detected.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/data-exchange-to-integriq/specs/data-exchange/spec.md#requirement-learniq-asks-integriq-to-carry-an-exchange
 */
class AttendanceFlagCreationHandler implements IEventListener {

	private const LEARNIQ_REGISTER = 'learniq';
	private const ATTENDANCE_THRESHOLD_SCHEMA = 'attendance-threshold';
	private const ATTENDANCE_FLAG_SCHEMA = 'attendance-flag';
	private const LEARNER_PROFILE_SCHEMA = 'learner-profile';
	private const LEERPLICHT_TARGET = 'leerplicht';
	private const LEERPLICHT_MAPPING = 'learniq-leerplicht-export-melding';

	/**
	 * The guarded manual transition action that records a real per-learner
	 * crossing (attendance-threshold-calculation). Corrected from an earlier
	 * `to = 'threshold-crossed'` state check: no such state (or any automatic
	 * calculatedChange-to-transition bridge) exists in OpenRegister at HEAD —
	 * `check-threshold` is a genuine `active` -> `active` self-loop, so the
	 * transition's ACTION name is the only reliable discriminator, exactly
	 * as `getTo()` would always read `active` for this transition.
	 */
	private const CHECK_THRESHOLD_ACTION = 'check-threshold';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR object access service.
	 * @param IntegriqExchangeClient $integriq Asks integriq for the exchange job.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly IntegriqExchangeClient $integriq,
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
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-10
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectTransitionedEvent) === false) {
			return;
		}

		if ($event->getRegister() !== self::LEARNIQ_REGISTER) {
			return;
		}

		if ($event->getSchema() !== self::ATTENDANCE_THRESHOLD_SCHEMA) {
			return;
		}

		// The guarded manual check-threshold transition is the only path that
		// can supply the per-learner crossing detail this handler needs (see
		// AttendanceThresholdCrossingGuard and design.md Decision 2/3).
		if ($event->getAction() !== self::CHECK_THRESHOLD_ACTION) {
			return;
		}

		$this->createFlag(event: $event);

	}//end handle()

	/**
	 * Create the AttendanceFlag for the crossing. The check-threshold
	 * transition's `inputs` are merged onto the object before this event
	 * fires, so the per-learner crossing detail lives on the object itself
	 * (see extractCrossingDetail()) — there is no separate event context.
	 *
	 * @param ObjectTransitionedEvent $event The check-threshold transition event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/attendance-threshold-calculation/specs/attendance/spec.md#scenario-a-guarded-manual-check-records-a-real-per-learner-crossing-and-creates-an-attendanceflag
	 */
	private function createFlag(ObjectTransitionedEvent $event): void {
		$threshold = $event->getObject()->jsonSerialize();
		$detail = $this->extractCrossingDetail(threshold: $threshold);

		if ($detail['learnerId'] === '' || $detail['thresholdId'] === '') {
			$this->logger->warning(
				'[AttendanceFlagCreationHandler] Threshold {id}: crossing event missing learnerId — skipping.',
				['id' => $detail['thresholdId']]
			);
			return;
		}

		$duplicate = $this->flagAlreadyExists(
			learnerId: $detail['learnerId'],
			thresholdId: $detail['thresholdId'],
			windowStart: $detail['windowStart']
		);
		if ($duplicate === true) {
			return;
		}

		$this->saveFlag(detail: $detail, onCross: $threshold['onCross'] ?? []);

	}//end createFlag()

	/**
	 * Extract the per-learner crossing detail from a (possibly `checked*`-
	 * input-merged) AttendanceThreshold payload.
	 *
	 * @param array<string,mixed> $threshold The AttendanceThreshold data after the transition.
	 *
	 * @return array{thresholdId:string,cohortId:mixed,learnerId:string,windowStart:string,windowEnd:string,metricValue:mixed,breachingIds:mixed,tenantId:string}
	 */
	private function extractCrossingDetail(array $threshold): array {
		$thresholdId = $threshold['id'] ?? '';
		if ($thresholdId === '') {
			$thresholdId = $threshold['uuid'] ?? '';
		}

		$learnerId = $threshold['checkedLearnerId'] ?? '';
		if ($learnerId === '') {
			$learnerId = $threshold['learnerId'] ?? '';
		}

		$metricValue = $threshold['checkedMetricValue'] ?? '';
		if ($metricValue === '') {
			$metricValue = $threshold['unexcusedLesuren'] ?? 0;
		}

		return [
			'thresholdId' => $thresholdId,
			'cohortId' => $threshold['cohortId'] ?? null,
			'learnerId' => $learnerId,
			'windowStart' => $threshold['checkedWindowStart'] ?? date('Y-m-d', strtotime('-4 weeks')),
			'windowEnd' => $threshold['checkedWindowEnd'] ?? date('Y-m-d'),
			'metricValue' => $metricValue,
			'breachingIds' => $threshold['checkedBreachingRecordIds'] ?? [],
			'tenantId' => $threshold['tenant_id'] ?? '',
		];

	}//end extractCrossingDetail()

	/**
	 * Build and save the AttendanceFlag, then ask integriq for the exchange job
	 * when the threshold's onCross.dataExchangeTarget is set.
	 *
	 * The flag is saved first so the job can name it as its owner; the job id is
	 * stamped on afterwards. Without integriq the flag stays without a job id.
	 *
	 * @param array<string,mixed> $detail Crossing detail from extractCrossingDetail().
	 * @param array<string,mixed> $onCross The threshold's onCross configuration.
	 *
	 * @return void
	 */
	private function saveFlag(array $detail, array $onCross): void {
		$mentorId = $this->resolveMentorId(learnerId: $detail['learnerId']);

		$flag = [
			'learnerId' => $detail['learnerId'],
			'attendanceThresholdId' => $detail['thresholdId'],
			'cohortId' => $detail['cohortId'],
			'windowStart' => $detail['windowStart'],
			'windowEnd' => $detail['windowEnd'],
			'metricValue' => (float)$detail['metricValue'],
			'breachingRecordIds' => $detail['breachingIds'],
			'dataExchangeJobId' => null,
			'mentorId' => $mentorId,
			'lifecycle' => 'open',
			'tenant_id' => $detail['tenantId'],
		];

		$saved = $this->objectService->saveObject(
			register: self::LEARNIQ_REGISTER,
			schema: self::ATTENDANCE_FLAG_SCHEMA,
			object: $flag
		);

		$this->logger->info(
			'[AttendanceFlagCreationHandler] Created AttendanceFlag for learner {l}, threshold {t}, metric {m}, window {ws}–{we}.',
			[
				'l' => $detail['learnerId'],
				't' => $detail['thresholdId'],
				'm' => $detail['metricValue'],
				'ws' => $detail['windowStart'],
				'we' => $detail['windowEnd'],
			]
		);

		$dataExchangeTarget = (string)($onCross['dataExchangeTarget'] ?? '');
		if ($dataExchangeTarget === '') {
			return;
		}

		$savedData = $saved->jsonSerialize();
		$flagId = (string)($savedData['id'] ?? ($savedData['uuid'] ?? ''));
		if ($flagId === '') {
			$this->logger->warning('[AttendanceFlagCreationHandler] The saved flag has no id, so no exchange job is requested.');
			return;
		}

		$jobId = $this->requestExchangeJob(target: $dataExchangeTarget, flagId: $flagId, tenantId: (string)$detail['tenantId']);
		if ($jobId === null) {
			return;
		}

		$this->objectService->saveObject(
			register: self::LEARNIQ_REGISTER,
			schema: self::ATTENDANCE_FLAG_SCHEMA,
			object: array_merge($flag, ['dataExchangeJobId' => $jobId]),
			uuid: $flagId
		);

	}//end saveFlag()

	/**
	 * Idempotency check: whether an AttendanceFlag already exists for the same
	 * learner + threshold + measurement window.
	 *
	 * @param mixed $learnerId NC user ID of the flagged learner.
	 * @param mixed $thresholdId UUID of the AttendanceThreshold that was crossed.
	 * @param mixed $windowStart Start date of the measurement window (Y-m-d).
	 *
	 * @return bool True when a flag for this crossing already exists.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-10
	 */
	private function flagAlreadyExists(mixed $learnerId, mixed $thresholdId, mixed $windowStart): bool {
		$existing = $this->objectService->findAll(
			[
				'filters' => [
					'register' => self::LEARNIQ_REGISTER,
					'schema' => self::ATTENDANCE_FLAG_SCHEMA,
					'learnerId' => $learnerId,
					'attendanceThresholdId' => $thresholdId,
					'windowStart' => $windowStart,
				],
				'limit' => 1,
			]
		);

		if (empty($existing) === true) {
			return false;
		}

		$this->logger->info(
			'[AttendanceFlagCreationHandler] Flag already exists for learner {l}, threshold {t}, window {w} — skipping duplicate.',
			['l' => $learnerId, 't' => $thresholdId, 'w' => $windowStart]
		);

		return true;
	}//end flagAlreadyExists()

	/**
	 * Ask integriq for the exchange job of a new flag.
	 *
	 * The job waits in integriq until a person takes the flag up: learniq's
	 * exchange gate refuses a leerplicht report while the flag is `open` (the
	 * human in the loop the old `pending-review` job state stood for).
	 *
	 * @param string $target   The exchange target (e.g. 'leerplicht').
	 * @param string $flagId   UUID of the saved AttendanceFlag.
	 * @param string $tenantId Tenant UUID.
	 *
	 * @return string|null The integriq job id, or null when integriq did not take it.
	 *
	 * @spec openspec/changes/data-exchange-to-integriq/specs/data-exchange/spec.md#requirement-learniq-asks-integriq-to-carry-an-exchange
	 */
	private function requestExchangeJob(string $target, string $flagId, string $tenantId): ?string {
		$mapping = null;
		if ($target === self::LEERPLICHT_TARGET) {
			$mapping = self::LEERPLICHT_MAPPING;
		}

		try {
			$jobId = $this->integriq->requestJob(
				target: $target,
				direction: 'export',
				ownerRef: self::ATTENDANCE_FLAG_SCHEMA . '/' . $flagId,
				scope: [
					'schema' => self::ATTENDANCE_FLAG_SCHEMA,
					'recordIds' => [$flagId],
					'tenantId' => $tenantId,
				],
				mappingSlug: $mapping,
				requestedBy: 'system',
				name: 'Verzuimmelding'
			);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[AttendanceFlagCreationHandler] No exchange job for flag {id} ({t}): {msg}',
				['id' => $flagId, 't' => $target, 'msg' => $exception->getMessage()]
			);
			return null;
		}

		$this->logger->info(
			'[AttendanceFlagCreationHandler] Integriq exchange job {job} requested for flag {id} ({t}).',
			['job' => $jobId, 'id' => $flagId, 't' => $target]
		);

		return $jobId;
	}//end requestExchangeJob()

	/**
	 * Resolve the learner's mentor from their LearnerProfile.managerId.
	 *
	 * Returns null when no profile is found or managerId is not set.
	 * A missing mentor does not block flag creation.
	 *
	 * @param string $learnerId NC user ID of the learner.
	 *
	 * @return string|null NC user ID of the mentor, or null.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-10
	 */
	private function resolveMentorId(string $learnerId): ?string {
		$profiles = $this->objectService->findAll(
			[
				'filters' => [
					'register' => self::LEARNIQ_REGISTER,
					'schema' => self::LEARNER_PROFILE_SCHEMA,
					'ncUserId' => $learnerId,
				],
				'limit' => 1,
			]
		);

		if (empty($profiles) === true) {
			return null;
		}

		$profile = $profiles[0];
		if (is_array($profiles[0]) === false) {
			$profile = $profiles[0]->jsonSerialize();
		}

		$managerId = $profile['managerId'] ?? null;

		if ($managerId === null || $managerId === '') {
			return null;
		}

		return $managerId;
	}//end resolveMentorId()
}//end class
