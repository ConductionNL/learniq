<?php

/**
 * Scholiq LTI AGS Score Poll Job
 *
 * Background job that pulls pending `nl.conduction.lti.ags.score.received`
 * CloudEvent messages from OpenConnector's `events-cloudevents` pull surface
 * for the scholiq-owned `event_subscription`, and creates a concept
 * `GradeEntry` per score — mirroring the exact shape
 * `GradeRollupHandler::handleAssessmentResultGraded()` already produces for
 * `sourceKind=assessment-result`. See design.md D2 (pull, not push) and D4
 * (idempotency via `(ltiToolPlacementId, ltiAgsResultId)`).
 *
 * Cursor + subscription bookkeeping (design.md D2/task 4.1 — documented
 * choice): both are `IAppConfig` values, not a tracking OpenRegister object.
 * A cron-cursor is single-writer (only this job ever advances it) and has no
 * audit/query need of its own, so a config scalar is the simplest correct
 * choice — the same reasoning `DataExchangeRunHandler::OPENCONNECTOR_TOKEN_KEY`
 * already applies to its own single-writer config value.
 *   - `lti_ags_subscription_id` — the scholiq-owned `event_subscription`
 *     UUID on OpenConnector (task 5.1 admin bootstrap). Empty = not yet
 *     bootstrapped; the job no-ops (not an error).
 *   - `lti_ags_pull_cursor` — the last-seen `event_message` UUID, advanced
 *     once per completed sweep.
 *
 * Auth note (deviation from design.md, documented — verified against
 * OpenConnector HEAD): design.md assumed this call could reuse the same
 * bearer-token-only shape as `DataExchangeRunHandler::callOpenConnector()`.
 * At HEAD, OpenConnector's `EventsController::pull()` requires an
 * authenticated Nextcloud user (`IUserSession::getUser()`) plus
 * `ActionAuthService::requireAction($user, 'event.pull')` group
 * authorization — there is no bearer/IAppConfig-token check on that route
 * (lib/Controller/EventsController.php:375-409 in the openconnector-dev
 * repo). A bare `Authorization: Bearer` header does not authenticate an NC
 * session. This job therefore sends HTTP Basic auth instead: a configured
 * NC username (`openconnector_api_user`, new config key — an app-password
 * grants a real session for that request) plus the SAME
 * `openconnector_api_token` value reused as the app-password, per task
 * 2.1/2.1's "do not add a second token" instruction (only a companion
 * username is added, not a second secret). The configured user must belong
 * to a group authorized for the `event.pull` action on OpenConnector.
 *
 * @category Cron
 * @package  OCA\Scholiq\Cron
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
 * @spec openspec/changes/lti-tool-placement/tasks.md#task-4.1
 * @spec openspec/changes/lti-tool-placement/specs/grading/spec.md#scenario-an-lti-ags-score-creates-a-traceable-concept-gradeentry
 * @spec openspec/changes/lti-tool-placement/specs/grading/spec.md#scenario-a-redelivered-ags-message-does-not-create-a-duplicate-gradeentry
 */

declare(strict_types=1);

namespace OCA\Scholiq\Cron;

use DateTimeImmutable;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\AppInfo\Application;
use OCA\Scholiq\Service\LtiAgsPullClient;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Polls OpenConnector for pending AGS score CloudEvents and bridges them into
 * concept GradeEntry rows.
 *
 * @psalm-api
 *
 * @spec openspec/changes/lti-tool-placement/tasks.md#task-4.1
 */
class LtiAgsScorePollJob extends TimedJob {

	/**
	 * Default sweep interval in seconds (5 minutes) — matches OpenConnector's
	 * own EventRetryJob cadence (design.md D2).
	 *
	 * @var integer
	 */
	private const DEFAULT_INTERVAL = 300;

	private const SCHOLIQ_REGISTER = 'scholiq';
	private const PLACEMENT_SCHEMA = 'lti-tool-placement';
	private const GRADE_ENTRY_SCHEMA = 'grade-entry';
	private const GRADE_SCALE_SCHEMA = 'grade-scale';

	/**
	 * App-config key for the scholiq-owned `event_subscription` UUID.
	 *
	 * @var string
	 */
	private const SUBSCRIPTION_ID_KEY = 'lti_ags_subscription_id';

	/**
	 * App-config key for the last-seen `event_message` cursor.
	 *
	 * @var string
	 */
	private const PULL_CURSOR_KEY = 'lti_ags_pull_cursor';

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory for job scheduling.
	 * @param ObjectService $objectService OR object access service.
	 * @param LtiAgsPullClient $pullClient OpenConnector AGS pull transport.
	 * @param IAppConfig $appConfig NC app config for subscription/cursor lookup.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ObjectService $objectService,
		private readonly LtiAgsPullClient $pullClient,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);

		$this->setInterval(seconds: self::DEFAULT_INTERVAL);
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(allow: false);

	}//end __construct()

	/**
	 * Execute one pull sweep.
	 *
	 * A single poisoned message must never wedge the cron pipeline (task
	 * 4.6) — any exception while processing one message is caught and
	 * logged, never rethrown.
	 *
	 * @param mixed $argument Task arguments (not used).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/changes/lti-tool-placement/tasks.md#task-4.1
	 */
	public function run(mixed $argument): void {
		$subscriptionId = $this->appConfig->getValueString(app: Application::APP_ID, key: self::SUBSCRIPTION_ID_KEY, default: '');
		if ($subscriptionId === '') {
			// Not yet bootstrapped (task 5.1) — no-op, not an error.
			$this->logger->debug('[LtiAgsScorePollJob] No lti_ags_subscription_id configured; skipping sweep.');
			return;
		}

		$cursor = $this->appConfig->getValueString(app: Application::APP_ID, key: self::PULL_CURSOR_KEY, default: '');
		$pulled = $this->pullClient->pull(subscriptionId: $subscriptionId, cursor: $cursor);
		if ($pulled === null) {
			// Logged inside the pull client; one failed sweep does not wedge the cron schedule.
			return;
		}

		$messages = $pulled['messages'];
		$created = 0;
		$skipped = 0;
		foreach ($messages as $message) {
			try {
				$accepted = $this->processMessage(message: $this->toArray(row: $message));
				if ($accepted === true) {
					$created++;
				}

				if ($accepted === false) {
					$skipped++;
				}
			} catch (Throwable $exception) {
				// Task 4.6: one malformed message must not wedge the sweep.
				$this->logger->error(
					'[LtiAgsScorePollJob] Failed to process a pulled AGS message: {msg}',
					['msg' => $exception->getMessage()]
				);
			}
		}//end foreach

		$newCursor = $pulled['cursor'] ?? null;
		if (is_string($newCursor) === true && $newCursor !== '') {
			$this->appConfig->setValueString(app: Application::APP_ID, key: self::PULL_CURSOR_KEY, value: $newCursor);
		}

		$this->logger->info(
			'[LtiAgsScorePollJob] Sweep complete. pulled={pulled}, created={created}, skipped={skipped}.',
			['pulled' => count($messages), 'created' => $created, 'skipped' => $skipped]
		);

	}//end run()

	/**
	 * Process one pulled `event_message` row.
	 *
	 * Resolves the `LtiToolPlacement` by `openconnectorDeploymentId`, skips
	 * (logs, does not throw) an orphaned message whose deployment matches no
	 * placement (task 4.2), checks the `(ltiToolPlacementId, ltiAgsResultId)`
	 * idempotency pair (task 4.3), and creates the concept `GradeEntry`
	 * (task 4.4).
	 *
	 * @param array<string,mixed> $message The pulled event_message row.
	 *
	 * @return bool True when a GradeEntry was created; false when the message was skipped.
	 *
	 * @spec openspec/changes/lti-tool-placement/tasks.md#task-4.2
	 * @spec openspec/changes/lti-tool-placement/tasks.md#task-4.3
	 * @spec openspec/changes/lti-tool-placement/tasks.md#task-4.4
	 */
	private function processMessage(array $message): bool {
		$resultId = (string)($message['id'] ?? ($message['uuid'] ?? ''));
		if ($resultId === '') {
			$this->logger->warning('[LtiAgsScorePollJob] Pulled message has no id/uuid — cannot dedupe, skipping.');
			return false;
		}

		$data = $message['payload'] ?? [];
		if (is_array($data) === false) {
			$this->logger->warning('[LtiAgsScorePollJob] Message {id} has no usable payload — skipping.', ['id' => $resultId]);
			return false;
		}

		$resolved = $this->resolvePlacementForMessage(data: $data, resultId: $resultId);
		if ($resolved === null) {
			return false;
		}

		$placement = $resolved['placement'];
		$placementId = $resolved['placementId'];

		if ($this->gradeEntryAlreadyExists(placementId: $placementId, resultId: $resultId) === true) {
			// Task 4.3 / design.md D4: redelivery — do not create a duplicate.
			return false;
		}

		// A GradeEntry needs a curriculum plan AND a component within it; a
		// placement carrying only one of the two has nothing to write to.
		$componentId = ($placement['gradeEntryComponentId'] ?? null);
		$planId = ($placement['curriculumPlanId'] ?? null);
		if (in_array($componentId, [null, ''], true) === true || in_array($planId, [null, ''], true) === true) {
			$this->logger->info(
				'[LtiAgsScorePollJob] Placement {pid} is not configured for grade passback — skipping message {id}.',
				['pid' => $placementId, 'id' => $resultId]
			);
			return false;
		}

		$score = $this->extractScore(data: $data, resultId: $resultId);
		if ($score === null) {
			return false;
		}

		$scaleId = (string)($placement['gradeScaleId'] ?? '');

		$gradeEntry = [
			'learnerId' => $score['learnerId'],
			'curriculumPlanId' => $planId,
			'componentId' => $componentId,
			'sourceKind' => 'lti-ags',
			'ltiToolPlacementId' => $placementId,
			'ltiAgsResultId' => $resultId,
			'value' => $this->normaliseScore(
				scoreGiven: $score['scoreGiven'],
				scoreMaximum: $score['scoreMaximum'],
				gradeScaleId: $scaleId
			),
			'gradeScaleId' => $scaleId,
			'grader' => 'lti-ags',
			'gradedAt' => (new DateTimeImmutable())->format(\DATE_ATOM),
			'tenant_id' => (string)($placement['tenant_id'] ?? ''),
			'lifecycle' => 'concept',
		];

		$this->objectService->saveObject(
			register: self::SCHOLIQ_REGISTER,
			schema: self::GRADE_ENTRY_SCHEMA,
			object: $gradeEntry
		);

		return true;
	}//end processMessage()

	/**
	 * Resolve the LtiToolPlacement an AGS message belongs to.
	 *
	 * Task 4.2: an orphaned subscription message (e.g. a placement deleted
	 * while the score was already in flight) is logged and skipped, not
	 * treated as an error.
	 *
	 * @param array<string,mixed> $data The message payload.
	 * @param string $resultId The AGS result id, for the log lines.
	 *
	 * @return array{placement: array<string,mixed>, placementId: string}|null The placement, or null when unresolvable.
	 *
	 * @spec openspec/changes/lti-tool-placement/tasks.md#task-4.2
	 */
	private function resolvePlacementForMessage(array $data, string $resultId): ?array {
		$deploymentUuid = (string)($data['deploymentUuid'] ?? '');
		if ($deploymentUuid === '') {
			$this->logger->warning('[LtiAgsScorePollJob] Message {id} has no deploymentUuid — skipping.', ['id' => $resultId]);
			return null;
		}

		$placement = $this->resolvePlacementByDeployment(deploymentUuid: $deploymentUuid);
		if ($placement === null) {
			$this->logger->info(
				'[LtiAgsScorePollJob] No LtiToolPlacement for deployment {dep} (message {id}) — skipping (orphan).',
				['dep' => $deploymentUuid, 'id' => $resultId]
			);
			return null;
		}

		$placementId = (string)($placement['id'] ?? ($placement['uuid'] ?? ''));
		if ($placementId === '') {
			$this->logger->warning('[LtiAgsScorePollJob] Resolved placement has no id — skipping message {id}.', ['id' => $resultId]);
			return null;
		}

		return [
			'placement' => $placement,
			'placementId' => $placementId,
		];

	}//end resolvePlacementForMessage()

	/**
	 * Extract the learner and score values an AGS message must carry.
	 *
	 * A message without both a userId and a scoreGiven cannot produce a
	 * GradeEntry, so it is skipped rather than written with a guessed value.
	 *
	 * @param array<string,mixed> $data The message payload.
	 * @param string $resultId The AGS result id, for the log line.
	 *
	 * @return array{learnerId: string, scoreGiven: float, scoreMaximum: float|null}|null The score, or null when insufficient.
	 *
	 * @spec openspec/changes/lti-tool-placement/tasks.md#task-4.2
	 */
	private function extractScore(array $data, string $resultId): ?array {
		$score = [];
		if (is_array($data['score'] ?? null) === true) {
			$score = $data['score'];
		}

		$learnerId = (string)($score['userId'] ?? '');
		$scoreGiven = ($score['scoreGiven'] ?? null);

		if ($learnerId === '' || $scoreGiven === null) {
			$this->logger->info(
				'[LtiAgsScorePollJob] AGS score for message {id} has no userId/scoreGiven — insufficient data, skipping.',
				['id' => $resultId]
			);
			return null;
		}

		$scoreMaximum = null;
		if (isset($score['scoreMaximum']) === true) {
			$scoreMaximum = (float)$score['scoreMaximum'];
		}

		return [
			'learnerId' => $learnerId,
			'scoreGiven' => (float)$scoreGiven,
			'scoreMaximum' => $scoreMaximum,
		];

	}//end extractScore()

	/**
	 * Resolve an `LtiToolPlacement` by `openconnectorDeploymentId`.
	 *
	 * @param string $deploymentUuid The OpenConnector lti_deployment UUID.
	 *
	 * @return array<string,mixed>|null The placement data, or null if not found.
	 */
	private function resolvePlacementByDeployment(string $deploymentUuid): ?array {
		$results = $this->objectService->findAll(
			[
				'register' => self::SCHOLIQ_REGISTER,
				'schema' => self::PLACEMENT_SCHEMA,
				'filters' => ['openconnectorDeploymentId' => $deploymentUuid],
				'limit' => 1,
			]
		);

		if (empty($results) === true) {
			return null;
		}

		return $this->toArray(row: $results[0]);
	}//end resolvePlacementByDeployment()

	/**
	 * Check whether a GradeEntry already exists for the (placement, resultId) pair.
	 *
	 * @param string $placementId The LtiToolPlacement UUID.
	 * @param string $resultId The AGS resultId / CloudEvent message id.
	 *
	 * @return bool True when a matching GradeEntry already exists.
	 *
	 * @spec openspec/changes/lti-tool-placement/tasks.md#task-4.3
	 */
	private function gradeEntryAlreadyExists(string $placementId, string $resultId): bool {
		$results = $this->objectService->findAll(
			[
				'register' => self::SCHOLIQ_REGISTER,
				'schema' => self::GRADE_ENTRY_SCHEMA,
				'filters' => [
					'ltiToolPlacementId' => $placementId,
					'ltiAgsResultId' => $resultId,
				],
				'limit' => 1,
			]
		);

		return (empty($results) === false);
	}//end gradeEntryAlreadyExists()

	/**
	 * Normalise an AGS score onto the target GradeScale's [min, max] range.
	 *
	 * Falls back to the raw `scoreGiven` when the scale cannot be resolved,
	 * is not a continuous numeric/percentage scale, or `scoreMaximum` is
	 * absent/zero — the concept lifecycle's teacher-review gate (grading
	 * spec's soft-publish model) is the fail-safe for an unnormalised value,
	 * never a blocked import.
	 *
	 * @param float $scoreGiven The raw AGS scoreGiven value.
	 * @param float|null $scoreMaximum The raw AGS scoreMaximum value, if present.
	 * @param string $gradeScaleId UUID of the target GradeScale.
	 *
	 * @return float The normalised value to store on the GradeEntry.
	 */
	private function normaliseScore(float $scoreGiven, ?float $scoreMaximum, string $gradeScaleId): float {
		if ($scoreMaximum === null || $scoreMaximum <= 0.0 || $gradeScaleId === '') {
			return $scoreGiven;
		}

		$scale = $this->objectService->find(id: $gradeScaleId, register: self::SCHOLIQ_REGISTER, schema: self::GRADE_SCALE_SCHEMA);
		if ($scale === null) {
			return $scoreGiven;
		}

		$scaleData = $this->toArray(row: $scale);
		$kind = ($scaleData['kind'] ?? null);
		if (in_array($kind, ['numeric', 'percentage'], true) === false) {
			return $scoreGiven;
		}

		$min = $scaleData['min'] ?? null;
		$max = $scaleData['max'] ?? null;
		if (is_numeric($min) === false || is_numeric($max) === false) {
			return $scoreGiven;
		}

		$min = (float)$min;
		$max = (float)$max;

		$ratio = $scoreGiven / $scoreMaximum;

		return $min + ($ratio * ($max - $min));
	}//end normaliseScore()

	/**
	 * Normalise an ObjectService row (entity or array) to a plain array.
	 *
	 * @param mixed $row The row returned by ObjectService.
	 *
	 * @return array<string,mixed> The serialized object data.
	 */
	private function toArray(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			return (array)$row->jsonSerialize();
		}

		return [];
	}//end toArray()
}//end class
