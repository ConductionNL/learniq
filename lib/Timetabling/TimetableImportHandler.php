<?php

/**
 * Scholiq Timetable Import Handler
 *
 * IEventListener for DataExchangeJob lifecycle -> `running` (the same OR
 * ObjectTransitionedEvent DataExchangeRunHandler consumes), filtered to
 * `target: timetable-import`. DataExchangeRunHandler bails out for this
 * target (see its own `handle()`) so exactly one handler owns the job.
 *
 * Algorithm (design.md "Data Model" / timetabling spec):
 * 1. Load the DataMappingProfile referenced by mappingProfileId. Unlike the
 *    export-direction handlers, this profile's fieldMappings are read in
 *    REVERSE: `scholiqField` still names the Scholiq-side (Session) field
 *    and `targetField` still names the external-side field (per the
 *    schema's own direction-agnostic property descriptions), but for
 *    `direction: import` this handler resolves each inbound record's
 *    `targetField` value into the matching `scholiqField`.
 * 2. Delegate the pull to OpenConnector via REST API — no Zermelo/Untis/
 *    Xedule wire protocol is implemented here.
 * 3. Validate each inbound record BEFORE any Session write (the
 *    validate-before-dequeue posture `data-exchange` already requires for
 *    every other target): cohortId, title, startsAt, endsAt, and
 *    externalRef must all be present and non-empty, or the record is
 *    rejected into `result.validationReport`.
 * 4. Idempotent upsert keyed by (externalRef, tenant_id): a matching Session
 *    is updated in place; no match creates a new `scheduled` Session. A
 *    Session with no externalRef (created manually) is never matched or
 *    overwritten — the lookup filter always requires a non-empty
 *    externalRef.
 * 5. Once the job reaches `succeeded`/`partial`, triggers
 *    {@see \OCA\Scholiq\Timetabling\TimetableConflictDetector}'s batch scan
 *    over every upserted Session.
 *
 * ADR-031 legitimate exception: external-system bridge — the same shape as
 * `data-exchange`'s existing job-execution handler (DataExchangeRunHandler).
 *
 * @category Service
 * @package  OCA\Scholiq\Timetabling
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
 * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#requirement-timetable-import-delegates-the-wire-protocol-to-openconnector-via-dataexchangejob
 * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#requirement-timetable-import-upserts-session-objects-idempotently-by-externalref
 */

declare(strict_types=1);

namespace OCA\Scholiq\Timetabling;

use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Executes a `timetable-import` DataExchangeJob: pulls a generated timetable
 * from OpenConnector and idempotently upserts Session objects by externalRef.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#requirement-timetable-import-delegates-the-wire-protocol-to-openconnector-via-dataexchangejob
 */
class TimetableImportHandler implements IEventListener
{

    private const SCHOLIQ_REGISTER = 'scholiq';
    private const JOB_SCHEMA       = 'data-exchange-job';
    private const MAPPING_PROFILE_SCHEMA = 'data-mapping-profile';
    private const SESSION_SCHEMA         = 'session';
    private const TARGET = 'timetable-import';

    /**
     * The OpenConnector REST endpoint for triggering a source run — same
     * path/contract shape as DataExchangeRunHandler's own
     * OPENCONNECTOR_RUN_PATH (documented assumption, not verified against a
     * live OpenConnector instance): for `direction: import` the response is
     * expected to additionally carry a `records` array of raw external
     * records, one per Zermelo/Untis/Xedule occurrence.
     */
    private const OPENCONNECTOR_RUN_PATH = '/apps/openconnector/api/sources/%s/run';

    private const OPENCONNECTOR_TOKEN_KEY = 'openconnector_api_token';

    /**
     * Constructor.
     *
     * @param ObjectService             $objectService    OR object access service.
     * @param TransitionEngine          $transitionEngine OR lifecycle engine for job state transitions.
     * @param TimetableConflictDetector $conflictDetector The batch conflict scan engine.
     * @param TimetableRecordMapper     $recordMapper     Inbound-record shaping and required-field validation.
     * @param IClientService            $clientService    NC HTTP client factory.
     * @param IURLGenerator             $urlGenerator     NC URL generator for internal requests.
     * @param IAppConfig                $appConfig        NC app config for token lookup.
     * @param LoggerInterface           $logger           PSR logger.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly TransitionEngine $transitionEngine,
        private readonly TimetableConflictDetector $conflictDetector,
        private readonly TimetableRecordMapper $recordMapper,
        private readonly IClientService $clientService,
        private readonly IURLGenerator $urlGenerator,
        private readonly IAppConfig $appConfig,
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
     * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#scenario-a-timetable-import-job-delegates-to-openconnector-and-reports-its-result
     */
    public function handle(Event $event): void
    {
        if (($event instanceof ObjectTransitionedEvent) === false) {
            return;
        }

        if ($event->getRegister() !== self::SCHOLIQ_REGISTER || $event->getSchema() !== self::JOB_SCHEMA) {
            return;
        }

        if ($event->getTo() !== 'running') {
            return;
        }

        $job = $event->getObject()->jsonSerialize();
        if (($job['target'] ?? '') !== self::TARGET) {
            return;
        }

        $this->runImport(job: $job);

    }//end handle()

    /**
     * Execute the timetable-import job.
     *
     * @param array<string,mixed> $job The DataExchangeJob data.
     *
     * @return void
     *
     * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#scenario-re-importing-the-same-timetable-does-not-duplicate-sessions
     * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#scenario-a-manually-created-session-is-never-touched-by-an-import
     */
    private function runImport(array $job): void
    {
        $jobId    = (string) ($job['id'] ?? ($job['uuid'] ?? ''));
        $tenantId = (string) ($job['tenant_id'] ?? '');

        if ($jobId === '') {
            $this->logger->error('[TimetableImportHandler] DataExchangeJob has no id — cannot execute.');
            return;
        }

        $this->saveJobFields(jobId: $jobId, fields: ['startedAt' => date('c')]);

        $mappingProfileId = $job['mappingProfileId'] ?? null;
        $profile          = null;
        if (is_string($mappingProfileId) === true && $mappingProfileId !== '') {
            $profile = $this->loadMappingProfile(profileId: $mappingProfileId);
        }

        $connectorResult = $this->callOpenConnector(payload: ['scope' => ($job['scope'] ?? [])]);

        if ($connectorResult === null) {
            $this->failJob(
                jobId: $jobId,
                message: "OpenConnector connection 'timetable-import' not found or returned an error. "
                    ."Ensure OpenConnector is installed and a timetable-import source is configured."
            );
            return;
        }

        $records = $connectorResult['records'] ?? [];
        if (is_array($records) === false) {
            $records = [];
        }

        $validated = $this->validateRecords(records: $records, profile: $profile, tenantId: $tenantId);
        $upserted  = $this->upsertAll(accepted: $validated['accepted'], tenantId: $tenantId);

        $processed     = count($records);
        $acceptedCount = count($upserted);
        $rejectedCount = ($processed - $acceptedCount);

        $resultData = [
            'recordsProcessed' => $processed,
            'recordsAccepted'  => $acceptedCount,
            'recordsRejected'  => $rejectedCount,
            'validationReport' => $validated['validationReport'],
            'artefactRef'      => null,
        ];

        $nextState = $this->resolveNextState(
            processed: $processed,
            accepted: $acceptedCount,
            rejected: $rejectedCount
        );

        $this->saveJobFields(
            jobId: $jobId,
            fields: [
                'finishedAt'     => date('c'),
                'result'         => $resultData,
                'connectorRunId' => $connectorResult['runId'] ?? null,
            ],
        );
        $this->transitionEngine->transition($jobId, $nextState);

        if ($nextState !== 'fail' && count($upserted) > 0) {
            $this->conflictDetector->scan(sessions: $upserted);
        }

        $this->logger->info(
            '[TimetableImportHandler] Job {id} -> {state}. processed={p}, accepted={a}, rejected={r}.',
            ['id' => $jobId, 'state' => $nextState, 'p' => $processed, 'a' => $acceptedCount, 'r' => $rejectedCount]
        );

    }//end runImport()

    /**
     * Map and validate every inbound record, splitting them into the accepted
     * (Session-shaped) records and the rejections that make up the job's
     * `result.validationReport` — validate-before-dequeue, so no Session is
     * written for a record that is missing a required field.
     *
     * @param array<int,mixed>         $records  The raw external records.
     * @param array<string,mixed>|null $profile  The DataMappingProfile, or null.
     * @param string                   $tenantId Tenant scope.
     *
     * @return array{accepted: array<int,array<string,mixed>>, validationReport: array<int,array<string,mixed>>}
     *
     * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#scenario-a-timetable-import-job-delegates-to-openconnector-and-reports-its-result
     */
    private function validateRecords(array $records, ?array $profile, string $tenantId): array
    {
        $accepted         = [];
        $validationReport = [];

        foreach ($records as $record) {
            if (is_array($record) === false) {
                continue;
            }

            $mapped  = $this->recordMapper->map(record: $record, profile: $profile, tenantId: $tenantId);
            $missing = $this->recordMapper->missingRequiredFields(record: $mapped);

            if (count($missing) > 0) {
                $validationReport[] = [
                    'recordId'     => $record['_externalRecordId'] ?? ($mapped['externalRef'] ?? null),
                    'errorCode'    => 'missing-fields',
                    'errorMessage' => 'Missing required field(s): '.implode(', ', $missing),
                ];
                continue;
            }

            $accepted[] = $mapped;
        }//end foreach

        return [
            'accepted'         => $accepted,
            'validationReport' => $validationReport,
        ];

    }//end validateRecords()

    /**
     * Upsert every accepted record, returning only the Sessions that saved.
     *
     * @param array<int,array<string,mixed>> $accepted The mapped, validated records.
     * @param string                         $tenantId Tenant scope.
     *
     * @return array<int,array<string,mixed>> The saved Session data arrays.
     *
     * @spec openspec/changes/timetabling-and-substitution/specs/timetabling/spec.md#scenario-re-importing-the-same-timetable-does-not-duplicate-sessions
     */
    private function upsertAll(array $accepted, string $tenantId): array
    {
        $upserted = [];
        foreach ($accepted as $mapped) {
            $session = $this->upsertSession(mapped: $mapped, tenantId: $tenantId);
            if ($session !== null) {
                $upserted[] = $session;
            }
        }

        return $upserted;

    }//end upsertAll()

    /**
     * Decide the job's terminal lifecycle transition from its record tallies:
     * `partial` when some records were accepted and some rejected, `fail` when
     * records were processed but none survived validation, else `succeed`.
     *
     * @param int $processed Records returned by OpenConnector.
     * @param int $accepted  Records upserted successfully.
     * @param int $rejected  Records not upserted.
     *
     * @return string The TransitionEngine transition name.
     */
    private function resolveNextState(int $processed, int $accepted, int $rejected): string
    {
        $nextState = 'succeed';

        if ($rejected > 0 && $accepted > 0) {
            $nextState = 'partial';
        }

        if ($rejected > 0 && $accepted === 0 && $processed > 0) {
            $nextState = 'fail';
        }

        return $nextState;

    }//end resolveNextState()

    /**
     * Idempotently upsert a Session by (externalRef, tenant_id). A manually
     * created Session (externalRef unset) is never matched — the lookup
     * filter always requires a non-empty externalRef, and a Session is only
     * ever created or updated here WITH externalRef set.
     *
     * @param array<string,mixed> $mapped   The mapped, validated record.
     * @param string              $tenantId Tenant scope.
     *
     * @return array<string,mixed>|null The saved Session data, or null on failure.
     */
    private function upsertSession(array $mapped, string $tenantId): ?array
    {
        $externalRef = (string) ($mapped['externalRef'] ?? '');
        if ($externalRef === '') {
            return null;
        }

        $filters = ['externalRef' => $externalRef];
        if ($tenantId !== '') {
            $filters['tenant_id'] = $tenantId;
        }

        $existing = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::SESSION_SCHEMA,
                'filters'  => $filters,
                'limit'    => 1,
            ]
        );

        $data = $mapped;

        // No match: this externalRef has never been imported before, so the
        // record becomes a brand-new `scheduled` Session.
        if (empty($existing) === true) {
            $data['lifecycle'] = 'scheduled';
        }

        if (empty($existing) === false) {
            $current = $existing[0];
            if (is_array($current) === false) {
                $current = $current->jsonSerialize();
            }

            $data = array_merge($current, $mapped);
        }

        $saved = $this->objectService->saveObject(
            register: self::SCHOLIQ_REGISTER,
            schema: self::SESSION_SCHEMA,
            object: $data
        );

        return $saved->jsonSerialize();

    }//end upsertSession()

    /**
     * Load a DataMappingProfile by UUID.
     *
     * @param string $profileId UUID of the DataMappingProfile.
     *
     * @return array<string,mixed>|null The profile data, or null if not found.
     */
    private function loadMappingProfile(string $profileId): ?array
    {
        $results = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::MAPPING_PROFILE_SCHEMA,
                'filters'  => ['id' => $profileId],
                'limit'    => 1,
            ]
        );

        if (empty($results) === true) {
            return null;
        }

        $profile = $results[0];
        if (is_array($profile) === false) {
            $profile = $profile->jsonSerialize();
        }

        return $profile;

    }//end loadMappingProfile()

    /**
     * Call the OpenConnector REST API for the `timetable-import` connection.
     *
     * @param array<string,mixed> $payload Request payload (job scope).
     *
     * @return array<string,mixed>|null Response data, or null on failure.
     */
    private function callOpenConnector(array $payload): ?array
    {
        $path = sprintf(self::OPENCONNECTOR_RUN_PATH, self::TARGET);
        $url  = $this->urlGenerator->getAbsoluteURL('/index.php'.$path);

        $apiToken = $this->appConfig->getValueString(
            app: 'scholiq',
            key: self::OPENCONNECTOR_TOKEN_KEY,
            default: ''
        );

        $requestOptions = [
            'json'    => $payload,
            'timeout' => 120,
        ];

        if ($apiToken === '') {
            $this->logger->warning(
                '[TimetableImportHandler] No OpenConnector API token configured '
                .'(scholiq.openconnector_api_token); the call may fail with 401/403.'
            );
        }

        if ($apiToken !== '') {
            $requestOptions['headers'] = ['Authorization' => 'Bearer '.$apiToken];
        }

        try {
            $client   = $this->clientService->newClient();
            $response = $client->post($url, $requestOptions);

            $body = json_decode($response->getBody(), true);
            if (is_array($body) === false) {
                $this->logger->error('[TimetableImportHandler] OpenConnector returned non-JSON.');
                return null;
            }

            return $body;
        } catch (\Exception $e) {
            $this->logger->error(
                '[TimetableImportHandler] OpenConnector call failed: {msg}',
                ['msg' => $e->getMessage()]
            );
            return null;
        }//end try

    }//end callOpenConnector()

    /**
     * Persist a failure result and drive the job to `failed`.
     *
     * @param string $jobId   UUID of the DataExchangeJob.
     * @param string $message Human-readable error message.
     *
     * @return void
     */
    private function failJob(string $jobId, string $message): void
    {
        $this->saveJobFields(
            jobId: $jobId,
            fields: [
                'finishedAt'   => date('c'),
                'errorMessage' => $message,
            ],
        );
        $this->transitionEngine->transition($jobId, 'fail');

    }//end failJob()

    /**
     * Persist updated fields on the DataExchangeJob without triggering a lifecycle event loop.
     *
     * @param string              $jobId  UUID of the DataExchangeJob.
     * @param array<string,mixed> $fields Fields to update.
     *
     * @return void
     */
    private function saveJobFields(string $jobId, array $fields): void
    {
        $existing = $this->objectService->findAll(
            [
                'register' => self::SCHOLIQ_REGISTER,
                'schema'   => self::JOB_SCHEMA,
                'filters'  => ['id' => $jobId],
                'limit'    => 1,
            ]
        );

        if (empty($existing) === true) {
            $this->logger->warning('[TimetableImportHandler] Job {id} not found for field update.', ['id' => $jobId]);
            return;
        }

        $current = $existing[0];
        if (is_array($current) === false) {
            $current = $current->jsonSerialize();
        }

        $updated = array_merge($current, $fields);

        $this->objectService->saveObject(
            register: self::SCHOLIQ_REGISTER,
            schema: self::JOB_SCHEMA,
            object: $updated
        );

    }//end saveJobFields()
}//end class
