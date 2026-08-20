<?php

/**
 * Learniq Data Exchange Run Handler
 *
 * IEventListener for DataExchangeJob lifecycle → `running`
 * (the OR ObjectTransitionedEvent with schema=data-exchange-job, to=running).
 *
 * Algorithm:
 * 1. Load the DataMappingProfile referenced by mappingProfileId (if set).
 * 2. Query the Learniq source objects per scope via ObjectService::findAll.
 * 3. Build the payload — applying the fieldMappings, stripping PII and running
 *    the per-target dossier composers — via DataExchangePayloadBuilder, which
 *    in turn applies the named field transforms via DataExchangeTransformer.
 * 4. Delegate to OpenConnector via REST API (POST /apps/openconnector/api/sources/run).
 *    If OpenConnector is not available, the job moves to `failed` with a clear
 *    errorMessage. Learniq implements NO Edukoppeling/StUF/OSO-XML/Digikoppeling
 *    wire protocols — all of that lives in OpenConnector.
 * 5. Record connectorRunId + result (counts, validation report, artefactRef).
 * 6. Transition the job to succeeded / partial / failed via lifecycle transitions.
 *
 * This is the ADR-031 "external-system bridge" exception: single responsibility
 * — orchestrate the OpenConnector call. No protocol code lives here.
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
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-14
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-20
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Learniq\Service\DataExchangePayloadBuilder;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Handles DataExchangeJob lifecycle → running.
 *
 * Loads the mapping profile, builds the payload, delegates to OpenConnector,
 * and records the result. Implements no wire protocols.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-14
 * @spec openspec/changes/verzuim-report-composer/tasks.md#task-3.1
 */
class DataExchangeRunHandler implements IEventListener {

	private const LEARNIQ_REGISTER = 'learniq';
	private const JOB_SCHEMA = 'data-exchange-job';
	private const MAPPING_PROFILE_SCHEMA = 'data-mapping-profile';

	/**
	 * Target whose succeeded run tracks the originating SupportRequest through
	 * to `routed-to-swv`. The dossier composition for this target itself lives
	 * in DataExchangePayloadBuilder.
	 *
	 * @spec openspec/changes/zorgvraag-swv-tlv-chain/tasks.md#task-4.5
	 */
	private const SWV_TARGET = 'swv';
	private const SUPPORT_REQUEST_SCHEMA = 'support-request';

	/**
	 * The OpenConnector REST endpoint for triggering a source run.
	 * Assumption documented in design: POST /apps/openconnector/api/sources/{name}/run
	 * returns { runId, status, recordsProcessed, recordsAccepted, recordsRejected,
	 *           validationReport, artefactRef }.
	 * If this endpoint path changes in OpenConnector, update the constant.
	 */
	private const OPENCONNECTOR_RUN_PATH = '/apps/openconnector/api/sources/%s/run';

	/**
	 * App-config key for the OpenConnector internal API token.
	 * Admins must set `learniq.openconnector_api_token` to a valid app-password
	 * or API token for the internal source-run call to succeed. Fixes #189.
	 */
	private const OPENCONNECTOR_TOKEN_KEY = 'openconnector_api_token';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR object access service.
	 * @param TransitionEngine $transitionEngine OR lifecycle engine for job state transitions.
	 * @param IClientService $clientService NC HTTP client factory.
	 * @param IURLGenerator $urlGenerator NC URL generator for internal requests.
	 * @param IAppConfig $appConfig NC app config for token lookup.
	 * @param LoggerInterface $logger PSR logger.
	 * @param DataExchangePayloadBuilder $payloadBuilder Field mapping + dossier composition.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly TransitionEngine $transitionEngine,
		private readonly IClientService $clientService,
		private readonly IURLGenerator $urlGenerator,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly DataExchangePayloadBuilder $payloadBuilder,
	) {
	}//end __construct()

	/**
	 * Handle an ObjectTransitionedEvent.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-14
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectTransitionedEvent) === false) {
			return;
		}

		if ($event->getRegister() !== self::LEARNIQ_REGISTER) {
			return;
		}

		if ($event->getSchema() !== self::JOB_SCHEMA) {
			return;
		}

		if ($event->getTo() !== 'running') {
			return;
		}

		// Timetabling-and-substitution: target: timetable-import is a PULL
		// (external -> Learniq Session upserts), a fundamentally different
		// shape than every other target this handler's runJob() implements
		// (Learniq objects -> external PUSH). OCA\Learniq\Timetabling\
		// TimetableImportHandler owns that target exclusively, registered
		// against this SAME event in lib/AppInfo/Application.php — bail here
		// so the two handlers never race to transition the same job.
		$job = $event->getObject()->jsonSerialize();
		if (($job['target'] ?? '') === 'timetable-import') {
			return;
		}

		$this->runJob(event: $event);

	}//end handle()

	/**
	 * Maximum records per data-exchange run page.
	 *
	 * A value of 10 000 silently truncates exports larger than this. A configurable
	 * limit with pagination is the proper fix; for now we raise the guard to 100 000
	 * and log a warning when we hit the ceiling. Fixes #188.
	 */
	private const QUERY_LIMIT = 100000;

	/**
	 * Execute the data exchange job.
	 *
	 * @param ObjectTransitionedEvent $event The running-state transition event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-14
	 * @spec openspec/changes/zorgvraag-swv-tlv-chain/tasks.md#task-4.5
	 */
	private function runJob(ObjectTransitionedEvent $event): void {
		$job = $event->getObject()->jsonSerialize();
		$jobId = $job['id'] ?? ($job['uuid'] ?? '');

		if ($jobId === '') {
			$this->logger->error('[DataExchangeRunHandler] DataExchangeJob has no id — cannot execute.');
			return;
		}

		$target = $job['target'] ?? '';
		$mappingProfileId = $job['mappingProfileId'] ?? null;
		$scope = $job['scope'] ?? [];
		$jobTenantId = $job['tenant_id'] ?? '';

		// Record startedAt.
		$this->saveJobFields(jobId: $jobId, fields: ['startedAt' => date('c')]);

		// 1. Load the DataMappingProfile.
		$profile = null;
		if ($mappingProfileId !== null && $mappingProfileId !== '') {
			$profile = $this->loadMappingProfile(profileId: $mappingProfileId);
		}

		// 2. Query Learniq source objects per scope (tenant-scoped — fixes #186).
		// M5: querySourceObjects throws RuntimeException when count >= QUERY_LIMIT.
		// 3. Build payload by applying fieldMappings.
		// #206: bsn-to-pseudonym throws \RuntimeException when eckId is absent — catch
		// here and fail the job fail-closed rather than shipping null pseudonym values.
		// C3: buildPayload throws when mandatory-profile target has no profile.
		try {
			$sourceObjects = $this->querySourceObjects(scope: $scope, tenantId: $jobTenantId);
			$payload = $this->payloadBuilder->buildPayload(
				objects: $sourceObjects,
				profile: $profile,
				target: $target
			);
		} catch (RuntimeException $e) {
			$this->logger->error(
				'[DataExchangeRunHandler] Job {id} aborted during query/payload build: {msg}',
				['id' => $jobId, 'msg' => $e->getMessage()]
			);
			$this->failJob(jobId: $jobId, errorMessage: $e->getMessage());
			return;
		}

		// 4. Delegate to OpenConnector.
		$connectorResult = $this->callOpenConnector(target: $target, payload: $payload);

		if ($connectorResult === null) {
			// OpenConnector not available or returned an error.
			$this->failJob(
				jobId: $jobId,
				errorMessage: sprintf(
					"OpenConnector connection '%s' not found or returned an error."
					. " Ensure OpenConnector is installed and the '%s' source is configured.",
					$target,
					$target
				),
			);
			return;
		}

		// 5/6. Record the result and drive the outcome transition.
		$this->recordJobOutcome(
			jobId: $jobId,
			connectorResult: $connectorResult,
			target: $target,
			scope: $scope,
			tenantId: $jobTenantId,
		);

	}//end runJob()

	/**
	 * Fail a job: persist the error fields, then drive the `fail` transition.
	 *
	 * C4: the result fields are persisted first and the lifecycle is moved via the
	 * transition engine afterwards, so OR's audit-trail entry and the declared
	 * transition guards both see the final field values.
	 *
	 * @param string $jobId DataExchangeJob id.
	 * @param string $errorMessage Message stored on the job and shown to an operator.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-14
	 */
	private function failJob(string $jobId, string $errorMessage): void {
		$this->saveJobFields(
			jobId: $jobId,
			fields: [
				'finishedAt' => date('c'),
				'errorMessage' => $errorMessage,
			],
		);
		$this->transitionEngine->transition($jobId, 'fail');

	}//end failJob()

	/**
	 * Persist an OpenConnector run's counts, transition the job to its outcome
	 * state, and track the originating SupportRequest through.
	 *
	 * @param string $jobId DataExchangeJob id.
	 * @param array<string,mixed> $connectorResult OpenConnector's run response.
	 * @param string $target Data-exchange target slug.
	 * @param array<string,mixed> $scope The job's scope, used to resolve the SupportRequest.
	 * @param string $tenantId Tenant scope of the job.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-14
	 * @spec openspec/changes/zorgvraag-swv-tlv-chain/tasks.md#task-4.5
	 */
	private function recordJobOutcome(
		string $jobId,
		array $connectorResult,
		string $target,
		array $scope,
		string $tenantId,
	): void {
		$resultData = [
			'recordsProcessed' => $connectorResult['recordsProcessed'] ?? 0,
			'recordsAccepted' => $connectorResult['recordsAccepted'] ?? 0,
			'recordsRejected' => $connectorResult['recordsRejected'] ?? 0,
			'validationReport' => $connectorResult['validationReport'] ?? [],
			'artefactRef' => $connectorResult['artefactRef'] ?? null,
		];

		$processed = (int)$resultData['recordsProcessed'];
		$accepted = (int)$resultData['recordsAccepted'];
		$rejected = (int)$resultData['recordsRejected'];

		$nextState = $this->resolveOutcomeState(processed: $processed, accepted: $accepted, rejected: $rejected);

		// C4 fix: persist result fields first (no lifecycle), then drive lifecycle via
		// the transition engine so OR's audit-trail and declared transition guards fire.
		$this->saveJobFields(
			jobId: $jobId,
			fields: [
				'finishedAt' => date('c'),
				'result' => $resultData,
				'connectorRunId' => ($connectorResult['runId'] ?? null),
			],
		);
		$this->transitionEngine->transition($jobId, $nextState);

		// Once a swv-target job succeeds, the originating SupportRequest tracks
		// through to routed-to-swv (learning-plan spec "SupportRequest tracks the
		// routed job through to decision"). Best-effort: a missing/unresolvable
		// SupportRequest does not fail the job — it is logged and skipped.
		// The target/nextState applicability check lives inside the callee.
		$this->routeSupportRequestToSwv(target: $target, nextState: $nextState, scope: $scope, tenantId: $tenantId);

		$this->logger->info(
			'[DataExchangeRunHandler] Job {id} → {state}. target={t}, processed={p}, accepted={a}, rejected={r}.',
			[
				'id' => $jobId,
				'state' => $nextState,
				't' => $target,
				'p' => $processed,
				'a' => $accepted,
				'r' => $rejected,
			]
		);

	}//end recordJobOutcome()

	/**
	 * Decide which lifecycle transition an OpenConnector run's counts imply.
	 *
	 * A run that rejected some records but accepted others is `partial`; one that
	 * processed records and accepted none of them is a `fail`. Everything else,
	 * including an empty run, succeeds.
	 *
	 * @param int $processed Records OpenConnector processed.
	 * @param int $accepted Records it accepted.
	 * @param int $rejected Records it rejected.
	 *
	 * @return string Transition name: 'succeed', 'partial' or 'fail'.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-14
	 */
	private function resolveOutcomeState(int $processed, int $accepted, int $rejected): string {
		if ($rejected > 0 && $accepted > 0) {
			return 'partial';
		}

		if ($rejected > 0 && $accepted === 0 && $processed > 0) {
			return 'fail';
		}

		return 'succeed';
	}//end resolveOutcomeState()

	/**
	 * Transition the SupportRequest that originated a succeeded swv-target
	 * DataExchangeJob to `routed-to-swv`.
	 *
	 * No-ops for any target other than `swv` or any state other than
	 * `succeed` — callers may invoke this unconditionally after every job
	 * outcome; the applicability check lives here, not in the caller, to
	 * keep runJob()'s branching flat.
	 *
	 * Resolves the SupportRequest via `scope.filters.supportRequestId`
	 * (stamped by SupportRequestSubmitHandler when it queues the job).
	 *
	 * @param string $target The job's target slug.
	 * @param string $nextState The lifecycle state the job just transitioned to.
	 * @param array<string,mixed> $scope The job's scope (schema, filters, ...).
	 * @param string $tenantId Tenant ID to enforce as a mandatory filter.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/zorgvraag-swv-tlv-chain/tasks.md#task-4.5
	 * @spec openspec/changes/zorgvraag-swv-tlv-chain/specs/learning-plan/spec.md#requirement-swv-routing-reuses-dataexchangejob-and-the-existing-pending-parent-review-gate
	 */
	private function routeSupportRequestToSwv(string $target, string $nextState, array $scope, string $tenantId): void {
		if ($target !== self::SWV_TARGET || $nextState !== 'succeed') {
			return;
		}

		$filters = $scope['filters'] ?? [];
		$supportRequestId = $filters['supportRequestId'] ?? null;

		if (is_string($supportRequestId) === false || $supportRequestId === '') {
			$this->logger->warning(
				'[DataExchangeRunHandler] Succeeded swv job has no scope.filters.supportRequestId — cannot '
				. 'route the originating SupportRequest to routed-to-swv.'
			);
			return;
		}

		$idFilters = ['id' => $supportRequestId];
		if ($tenantId !== '') {
			$idFilters['tenant_id'] = $tenantId;
		}

		$results = $this->objectService->findAll(
			[
				'register' => self::LEARNIQ_REGISTER,
				'schema' => self::SUPPORT_REQUEST_SCHEMA,
				'filters' => $idFilters,
				'limit' => 1,
			]
		);

		if (empty($results) === true) {
			$this->logger->warning(
				'[DataExchangeRunHandler] SupportRequest {id} not found — cannot route to routed-to-swv.',
				['id' => $supportRequestId]
			);
			return;
		}

		try {
			$this->transitionEngine->transition($supportRequestId, 'routeToSwv');
		} catch (\Throwable $e) {
			$this->logger->warning(
				'[DataExchangeRunHandler] Could not transition SupportRequest {id} to routed-to-swv: {msg}',
				['id' => $supportRequestId, 'msg' => $e->getMessage()]
			);
		}

	}//end routeSupportRequestToSwv()

	/**
	 * Load a DataMappingProfile by UUID.
	 *
	 * @param string $profileId UUID of the DataMappingProfile.
	 *
	 * @return array<string,mixed>|null The profile data, or null if not found.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-14
	 */
	private function loadMappingProfile(string $profileId): ?array {
		$results = $this->objectService->findAll(
			[
				'register' => self::LEARNIQ_REGISTER,
				'schema' => self::MAPPING_PROFILE_SCHEMA,
				'filters' => ['id' => $profileId],
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
	}//end loadMappingProfile()

	/**
	 * Query Learniq source objects per the job scope.
	 *
	 * @param array<string,mixed> $scope The job scope (schema, filters, cohortId, period).
	 * @param string $tenantId Tenant ID to enforce as a mandatory filter. Fixes #186.
	 *
	 * @return array<int,array<string,mixed>> Source objects.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-14
	 */
	private function querySourceObjects(array $scope, string $tenantId): array {
		$schema = $scope['schema'] ?? '';
		$filters = $scope['filters'] ?? [];
		$cohortId = $scope['cohortId'] ?? null;

		if ($schema === '') {
			return [];
		}

		if ($cohortId !== null) {
			$filters['cohortId'] = $cohortId;
		}

		// #186: always force tenant_id so a malicious scope.filters targeting a
		// different tenant's register/schema returns no data.
		if ($tenantId !== '') {
			$filters['tenant_id'] = $tenantId;
		}

		$results = $this->objectService->findAll(
			[
				'register' => self::LEARNIQ_REGISTER,
				'schema' => $schema,
				'filters' => $filters,
				// #188: raised from 10 000 to 100 000; full pagination is a follow-up.
				'limit' => self::QUERY_LIMIT,
			]
		);

		// M5: fail hard when we hit the limit ceiling — silent truncation must not ship partial PII.
		if (count($results) >= self::QUERY_LIMIT) {
			throw new RuntimeException(
				'querySourceObjects hit QUERY_LIMIT (' . self::QUERY_LIMIT . ") for schema '{$schema}'; "
				. 'pagination required. Aborting to prevent incomplete data export.'
			);
		}

		return array_map(
			static function ($item) {
				if (is_array($item) === true) {
					return $item;
				}

				return $item->jsonSerialize();
			},
			$results
		);

	}//end querySourceObjects()

	/**
	 * Call the OpenConnector REST API to execute the named connection.
	 *
	 * Assumption (documented in design): OpenConnector exposes
	 *   POST /index.php/apps/openconnector/api/sources/{name}/run
	 * with body { payload: array } and returns:
	 *   { runId, status, recordsProcessed, recordsAccepted,
	 *     recordsRejected, validationReport, artefactRef }
	 *
	 * If the endpoint is unreachable or returns an error, returns null.
	 * Learniq implements NO wire protocols — all Edukoppeling/StUF/OSO-XML/
	 * Digikoppeling/SAML logic lives in OpenConnector.
	 *
	 * @param string $target Named OpenConnector connection (e.g. 'bron-rod').
	 * @param array<int,array<string,mixed>> $payload The mapped payload to send.
	 *
	 * @return array<string,mixed>|null Response data, or null on failure.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-20
	 */
	private function callOpenConnector(string $target, array $payload): ?array {
		$path = sprintf(self::OPENCONNECTOR_RUN_PATH, rawurlencode($target));
		$url = $this->urlGenerator->getAbsoluteURL('/index.php' . $path);

		// #189: attach the configured API token so the OpenConnector endpoint
		// does not need to be @PublicPage (and is therefore not unauthenticated).
		$apiToken = $this->appConfig->getValueString(
			app: 'learniq',
			key: self::OPENCONNECTOR_TOKEN_KEY,
			default: ''
		);

		$requestOptions = [
			'json' => ['payload' => $payload],
			'timeout' => 120,
		];

		if ($apiToken === '') {
			$this->logger->warning(
				'[DataExchangeRunHandler] No OpenConnector API token configured ('
				. 'learniq.openconnector_api_token); the call may fail with 401/403. '
				. 'Set the token via the Learniq admin settings.'
			);
		}

		if ($apiToken !== '') {
			$requestOptions['headers'] = [
				'Authorization' => 'Bearer ' . $apiToken,
			];
		}

		try {
			$client = $this->clientService->newClient();
			$response = $client->post(
				$url,
				$requestOptions
			);

			$body = json_decode($response->getBody(), true);
			if (is_array($body) === false) {
				$this->logger->error(
					'[DataExchangeRunHandler] OpenConnector returned non-JSON for target {t}.',
					['t' => $target]
				);
				return null;
			}

			return $body;
		} catch (\Exception $e) {
			$this->logger->error(
				"[DataExchangeRunHandler] OpenConnector call failed for target '{t}': {msg}",
				['t' => $target, 'msg' => $e->getMessage()]
			);
			return null;
		}//end try

	}//end callOpenConnector()

	/**
	 * Persist updated fields on the DataExchangeJob without triggering a lifecycle event loop.
	 *
	 * @param string $jobId UUID of the DataExchangeJob.
	 * @param array<string,mixed> $fields Fields to update.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-14
	 */
	private function saveJobFields(string $jobId, array $fields): void {
		$existing = $this->objectService->findAll(
			[
				'register' => self::LEARNIQ_REGISTER,
				'schema' => self::JOB_SCHEMA,
				'filters' => ['id' => $jobId],
				'limit' => 1,
			]
		);

		if (empty($existing) === true) {
			$this->logger->warning('[DataExchangeRunHandler] Job {id} not found for field update.', ['id' => $jobId]);
			return;
		}

		$current = $existing[0];
		if (is_array($existing[0]) === false) {
			$current = $existing[0]->jsonSerialize();
		}

		$updated = array_merge($current, $fields);

		$this->objectService->saveObject(
			register: self::LEARNIQ_REGISTER,
			schema: self::JOB_SCHEMA,
			object: $updated
		);

	}//end saveJobFields()
}//end class
