<?php

/**
 * Unit tests for LtiAgsScorePollJob.
 *
 * Covers the AGS-to-GradeEntry bridge: a pulled AGS score message for a
 * configured placement creates exactly one concept GradeEntry with the
 * correct componentId/curriculumPlanId/ltiAgsResultId (task 4.7); a
 * redelivered message (same ltiToolPlacementId + ltiAgsResultId already on
 * an existing GradeEntry) does not create a duplicate (task 4.8); and a
 * message whose deploymentUuid matches no LtiToolPlacement is skipped
 * without throwing (task 4.9).
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\BackgroundJob
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
 * @spec openspec/changes/archive/2026-07-13-lti-tool-placement/tasks.md#task-4.7
 * @spec openspec/changes/archive/2026-07-13-lti-tool-placement/tasks.md#task-4.8
 * @spec openspec/changes/archive/2026-07-13-lti-tool-placement/tasks.md#task-4.9
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Learniq\BackgroundJob\LtiAgsScorePollJob;
use OCA\Learniq\Service\LtiAgsPullClient;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for LtiAgsScorePollJob::run().
 */
class LtiAgsScorePollJobTest extends TestCase {

	/**
	 * ObjectService mock.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objectService;

	/**
	 * HTTP client-service mock.
	 *
	 * @var IClientService&MockObject
	 */
	private IClientService&MockObject $clientService;

	/**
	 * URL generator mock.
	 *
	 * @var IURLGenerator&MockObject
	 */
	private IURLGenerator&MockObject $urlGenerator;

	/**
	 * App-config mock.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * @var array<string,string>
	 */
	private array $configValues = [];

	/**
	 * The placement fixture returned for the 'lti-tool-placement' schema.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $placementFixture = null;

	/**
	 * The GradeEntry fixtures ObjectService::findAll('grade-entry') should
	 * report as already existing (idempotency fixtures).
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $existingGradeEntries = [];

	/**
	 * Objects saved via ObjectService::saveObject during the test.
	 *
	 * @var array<int,array{register:string,schema:string,object:array<string,mixed>}>
	 */
	private array $savedObjects = [];

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectService::class);
		$this->clientService = $this->createMock(IClientService::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->appConfig = $this->createMock(IAppConfig::class);

		$this->configValues = [
			'lti_ags_subscription_id' => 'sub-1',
			'lti_ags_pull_cursor' => '',
			'openconnector_api_user' => 'lti-service-user',
			'openconnector_api_token' => 'token-abc',
		];

		$this->appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = ''): string {
				return $this->configValues[$key] ?? $default;
			}
		);
		$this->appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->configValues[$key] = $value;
				return true;
			}
		);

		$this->urlGenerator->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $path): string => 'https://learniq.example' . $path
		);

		// The job runs without a user, as cron does. OpenRegister refuses such a
		// caller: measured live on 2026-09-30, the job's GradeScale read failed
		// with "User 'Anonymous' does not have permission to 'read' objects in
		// schema 'GradeScale'". This store answers only a system-context call.
		$this->objectService->method('findAll')->willReturnCallback(
			function (array $config, bool $_rbac = true, bool $_multitenancy = true): array {
				$schema = $config['filters']['schema'] ?? '';
				if ($this->refusesCaller(action: 'read', schema: (string)$schema, rbac: $_rbac, multitenancy: $_multitenancy) === true) {
					return [];
				}

				$filters = array_diff_key(($config['filters'] ?? []), ['register' => true, 'schema' => true]);

				if ($schema === 'lti-tool-placement') {
					if ($this->placementFixture === null) {
						return [];
					}

					if (($filters['openconnectorDeploymentId'] ?? null) !== $this->placementFixture['openconnectorDeploymentId']) {
						return [];
					}

					return OrEntityFactory::makeMany([$this->placementFixture], 'lti-tool-placement');
				}

				if ($schema === 'grade-entry') {
					$placementId = $filters['ltiToolPlacementId'] ?? null;
					$resultId = $filters['ltiAgsResultId'] ?? null;
					return OrEntityFactory::makeMany(
						array_values(
							array_filter(
								$this->existingGradeEntries,
								static fn (array $e): bool => ($e['ltiToolPlacementId'] ?? null) === $placementId
									&& ($e['ltiAgsResultId'] ?? null) === $resultId
							)
						),
						'grade-entry'
					);
				}

				return [];
			}
		);

		// OpenRegister's saveObject() is saveObject($object, $extend, $register, $schema, ...)
		// — the PAYLOAD IS FIRST — and returns a non-nullable ObjectEntity.
		// willReturnCallback() hands the closure the mock's arguments
		// POSITIONALLY, so the closure must mirror that order.
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): ObjectEntity {
				$this->refusesCaller(action: 'create', schema: (string)$schema, rbac: $_rbac, multitenancy: $_multitenancy);
				$this->savedObjects[] = ['register' => $register, 'schema' => $schema, 'object' => $object];
				$object['id'] = 'grade-entry-new';
				return OrEntityFactory::make($object, (string)$schema, (string)$register);
			}
		);
	}//end setUp()

	/**
	 * Refuse a call made in the caller's (user-less) context, the way the live
	 * instance did: RBAC on throws OpenRegister's permission error; tenant
	 * scope on hides every row (the caller has no organisation).
	 *
	 * @param string $action       'read' or 'create'.
	 * @param string $schema       The schema slug.
	 * @param bool   $rbac         Whether RBAC applies.
	 * @param bool   $multitenancy Whether the tenant scope applies.
	 *
	 * @return bool True when the rows must be hidden.
	 *
	 * @throws \RuntimeException When RBAC applies.
	 */
	private function refusesCaller(string $action, string $schema, bool $rbac, bool $multitenancy): bool {
		if ($rbac === true) {
			throw new \RuntimeException("User 'Anonymous' does not have permission to '" . $action . "' objects in schema '" . $schema . "'");
		}

		return $multitenancy === true;
	}//end refusesCaller()

	/**
	 * A pulled message in the shape integriq's pull endpoint really returns.
	 *
	 * Built the way integriq builds it, with the real (mirrored) ObjectEntity
	 * serialisation: `LtiAgsService::receiveScore()` emits a CloudEvent whose
	 * `data` carries the score (`EventService::emitCloudEvent()` saves it as an
	 * `event` object), `EventService::createEventMessage()` stores that event's
	 * `jsonSerialize()` as the message `payload`, and `EventsController::pull()`
	 * answers with the message objects. So the score fields sit under
	 * `payload.data`, next to the CloudEvent envelope, never at `payload` level.
	 * The `x-generated-by` loop marker is left out: OpenRegister drops it on
	 * save because the `event` schema does not declare it.
	 *
	 * @param string              $messageUuid    The event_message uuid (the AGS result id learniq dedupes on).
	 * @param string              $deploymentUuid The lti_deployment uuid.
	 * @param string              $lineItemId     The line item (integriq sets it to the placement id).
	 * @param array<string,mixed> $score          The AGS score body the tool posted.
	 *
	 * @return array<string,mixed> The message as the pull response carries it.
	 */
	private function integriqMessage(string $messageUuid, string $deploymentUuid, string $lineItemId, array $score): array {
		$event = OrEntityFactory::make(
			[
				'source' => 'lti_deployment/' . $deploymentUuid,
				'type' => 'nl.conduction.lti.ags.score.received',
				'time' => '2026-09-29T21:00:00+00:00',
				'subject' => $lineItemId,
				'data' => [
					'deploymentUuid' => $deploymentUuid,
					'deploymentId' => 'deploy-claim-1',
					'lineItemId' => $lineItemId,
					'gradeSink' => null,
					// IRequest::getParams(): the JSON score body plus the route parameters.
					'score' => array_merge($score, ['deployment' => $deploymentUuid, 'lineItemId' => $lineItemId]),
				],
				'userId' => null,
			],
			'event',
			'integriq',
			'event-' . $messageUuid
		);

		$message = OrEntityFactory::make(
			[
				'event' => 'event-' . $messageUuid,
				'consumerId' => null,
				'subscription' => 'sub-1',
				'status' => 'pending',
				'payload' => $event->jsonSerialize(),
				'created' => '2026-09-29T21:00:00+00:00',
				'updated' => '2026-09-29T21:00:00+00:00',
			],
			'event_message',
			'integriq',
			$messageUuid
		);

		return $message->jsonSerialize();
	}//end integriqMessage()

	/**
	 * Build the job under test, wired to return the given pulled messages.
	 *
	 * @param array<int,array<string,mixed>> $messages The messages the pull() HTTP call should return.
	 * @param string|null $cursor The cursor value the pull() call should return.
	 *
	 * @return LtiAgsScorePollJob
	 */
	private function job(array $messages, ?string $cursor = 'cursor-1'): LtiAgsScorePollJob {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn(json_encode(['messages' => $messages, 'cursor' => $cursor]));

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($response);

		$this->clientService->method('newClient')->willReturn($client);

		// The pull transport is a real collaborator wired to the mocked HTTP
		// client, so the sweep is still driven end-to-end through the same
		// JSON body the OpenConnector endpoint would return.
		$pullClient = new LtiAgsPullClient(
			clientService: $this->clientService,
			urlGenerator: $this->urlGenerator,
			appConfig: $this->appConfig,
			appManager: $this->createMock(IAppManager::class),
			logger: new NullLogger()
		);

		return new LtiAgsScorePollJob(
			time: $this->createMock(ITimeFactory::class),
			objectService: $this->objectService,
			pullClient: $pullClient,
			appConfig: $this->appConfig,
			logger: new NullLogger()
		);
	}//end job()

	/**
	 * A pulled AGS message for a configured placement creates exactly one
	 * concept GradeEntry with the correct componentId/curriculumPlanId/ltiAgsResultId.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-07-13-lti-tool-placement/tasks.md#task-4.7
	 */
	public function testCreatesConceptGradeEntryForConfiguredPlacement(): void {
		$this->placementFixture = [
			'id' => 'placement-1',
			'openconnectorDeploymentId' => 'deployment-1',
			'curriculumPlanId' => 'plan-1',
			'gradeEntryComponentId' => 'component-1',
			'gradeScaleId' => '',
			'tenant_id' => 'tenant-1',
		];

		$message = $this->integriqMessage(
			messageUuid: 'msg-1',
			deploymentUuid: 'deployment-1',
			lineItemId: 'placement-1',
			score: ['userId' => 'learner-1', 'scoreGiven' => 8.5, 'scoreMaximum' => 10]
		);

		$job = $this->job(messages: [$message]);
		$job->run(null);

		self::assertCount(1, $this->savedObjects);
		$saved = $this->savedObjects[0];
		self::assertSame('learniq', $saved['register']);
		self::assertSame('grade-entry', $saved['schema']);
		self::assertSame('lti-ags', $saved['object']['sourceKind']);
		self::assertSame('component-1', $saved['object']['componentId']);
		self::assertSame('plan-1', $saved['object']['curriculumPlanId']);
		self::assertSame('placement-1', $saved['object']['ltiToolPlacementId']);
		self::assertSame('msg-1', $saved['object']['ltiAgsResultId']);
		self::assertSame('learner-1', $saved['object']['learnerId']);
		self::assertSame('concept', $saved['object']['lifecycle']);
		self::assertSame(8.5, $saved['object']['value']);

		// Cursor advanced.
		self::assertSame('cursor-1', $this->configValues['lti_ags_pull_cursor']);
	}//end testCreatesConceptGradeEntryForConfiguredPlacement()

	/**
	 * The score is read from the CloudEvent `data` of a message shaped exactly
	 * as integriq's pull returns it, including the envelope and `@self` blocks;
	 * the envelope's `id` and `subject` are not mistaken for learniq's fields.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-a-returned-grade-lands-on-the-placement-that-launched-it
	 */
	public function testReadsTheScoreFromTheCloudEventDataOfARealMessage(): void {
		$this->placementFixture = [
			'id' => 'placement-1',
			'openconnectorDeploymentId' => 'deployment-1',
			'curriculumPlanId' => 'plan-1',
			'gradeEntryComponentId' => 'component-1',
			'gradeScaleId' => '',
			'tenant_id' => 'tenant-1',
		];

		$message = $this->integriqMessage(
			messageUuid: 'msg-real',
			deploymentUuid: 'deployment-1',
			lineItemId: 'placement-1',
			score: [
				'userId' => 'learner-7',
				'scoreGiven' => 8,
				'scoreMaximum' => 10,
				'activityProgress' => 'Completed',
				'gradingProgress' => 'FullyGraded',
				'timestamp' => '2026-09-29T21:00:00Z',
			]
		);
		self::assertArrayNotHasKey('deploymentUuid', $message['payload'], 'the fixture must carry the score under payload.data, as integriq does');

		$this->job(messages: [$message])->run(null);

		self::assertCount(1, $this->savedObjects);
		self::assertSame('learner-7', $this->savedObjects[0]['object']['learnerId']);
		self::assertSame('placement-1', $this->savedObjects[0]['object']['ltiToolPlacementId']);
		self::assertSame('msg-real', $this->savedObjects[0]['object']['ltiAgsResultId']);
		self::assertSame(8.0, $this->savedObjects[0]['object']['value']);
	}//end testReadsTheScoreFromTheCloudEventDataOfARealMessage()

	/**
	 * A user-less run (cron) normalises the score on the placement's grade scale
	 * and writes the grade for the right learner, placement, plan and component.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-a-returned-grade-lands-on-the-placement-that-launched-it
	 */
	public function testAUserlessRunWritesTheGradeOnTheScale(): void {
		$this->placementFixture = [
			'id' => 'placement-1',
			'openconnectorDeploymentId' => 'deployment-1',
			'curriculumPlanId' => 'plan-1',
			'gradeEntryComponentId' => 'component-1',
			'gradeScaleId' => 'scale-1',
			'tenant_id' => 'tenant-1',
		];
		$this->objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null, bool $_rbac = true, bool $_multitenancy = true): ?ObjectEntity {
				if ($this->refusesCaller(action: 'read', schema: (string)$schema, rbac: $_rbac, multitenancy: $_multitenancy) === true) {
					return null;
				}

				if ((string)$schema === 'grade-scale' && (string)$id === 'scale-1') {
					return OrEntityFactory::make(['id' => 'scale-1', 'kind' => 'numeric', 'min' => 1, 'max' => 10], 'grade-scale');
				}

				return null;
			}
		);

		$this->job(
			messages: [
				$this->integriqMessage(
					messageUuid: 'msg-cron',
					deploymentUuid: 'deployment-1',
					lineItemId: 'placement-1',
					score: ['userId' => 'learner-9', 'scoreGiven' => 8, 'scoreMaximum' => 10]
				),
			]
		)->run(null);

		self::assertCount(1, $this->savedObjects);
		$grade = $this->savedObjects[0]['object'];
		self::assertSame('learner-9', $grade['learnerId']);
		self::assertSame('placement-1', $grade['ltiToolPlacementId']);
		self::assertSame('plan-1', $grade['curriculumPlanId']);
		self::assertSame('component-1', $grade['componentId']);
		self::assertSame('msg-cron', $grade['ltiAgsResultId']);
		self::assertEqualsWithDelta(8.2, $grade['value'], 0.0001, '8 of 10 on a 1-10 scale is 1 + 0.8 * 9');
		self::assertSame('concept', $grade['lifecycle']);
	}//end testAUserlessRunWritesTheGradeOnTheScale()

	/**
	 * Pulling the same message twice (simulating a redelivery) creates
	 * exactly one GradeEntry, not two.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-07-13-lti-tool-placement/tasks.md#task-4.8
	 */
	public function testRedeliveredMessageDoesNotCreateDuplicate(): void {
		$this->placementFixture = [
			'id' => 'placement-1',
			'openconnectorDeploymentId' => 'deployment-1',
			'curriculumPlanId' => 'plan-1',
			'gradeEntryComponentId' => 'component-1',
			'gradeScaleId' => '',
			'tenant_id' => 'tenant-1',
		];

		// Simulate a GradeEntry already created for this exact pair.
		$this->existingGradeEntries = [
			['ltiToolPlacementId' => 'placement-1', 'ltiAgsResultId' => 'msg-1'],
		];

		$message = $this->integriqMessage(
			messageUuid: 'msg-1',
			deploymentUuid: 'deployment-1',
			lineItemId: 'placement-1',
			score: ['userId' => 'learner-1', 'scoreGiven' => 8.5, 'scoreMaximum' => 10]
		);

		$job = $this->job(messages: [$message]);
		$job->run(null);

		self::assertCount(0, $this->savedObjects);
	}//end testRedeliveredMessageDoesNotCreateDuplicate()

	/**
	 * A message whose deploymentUuid matches no LtiToolPlacement is logged
	 * and skipped without throwing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-07-13-lti-tool-placement/tasks.md#task-4.9
	 */
	public function testOrphanMessageIsSkippedWithoutThrowing(): void {
		$this->placementFixture = null;

		$message = $this->integriqMessage(
			messageUuid: 'msg-orphan',
			deploymentUuid: 'deployment-unknown',
			lineItemId: 'placement-gone',
			score: ['userId' => 'learner-1', 'scoreGiven' => 5, 'scoreMaximum' => 10]
		);

		$job = $this->job(messages: [$message]);

		// Must not throw.
		$job->run(null);

		self::assertCount(0, $this->savedObjects);
	}//end testOrphanMessageIsSkippedWithoutThrowing()

	/**
	 * A job with no configured subscription id no-ops without calling the
	 * HTTP client at all.
	 *
	 * @return void
	 */
	public function testNoOpsWhenSubscriptionNotConfigured(): void {
		$this->configValues['lti_ags_subscription_id'] = '';

		$this->clientService->expects($this->never())->method('newClient');

		$job = new LtiAgsScorePollJob(
			time: $this->createMock(ITimeFactory::class),
			objectService: $this->objectService,
			pullClient: new LtiAgsPullClient(
				clientService: $this->clientService,
				urlGenerator: $this->urlGenerator,
				appConfig: $this->appConfig,
				appManager: $this->createMock(IAppManager::class),
				logger: new NullLogger()
			),
			appConfig: $this->appConfig,
			logger: new NullLogger()
		);

		$job->run(null);

		self::assertCount(0, $this->savedObjects);
	}//end testNoOpsWhenSubscriptionNotConfigured()

	/**
	 * Two placements on one deployment: the score's line item picks the
	 * placement; a line item naming a placement on another deployment is
	 * ignored and the deployment lookup decides.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-a-returned-grade-lands-on-the-placement-that-launched-it
	 */
	public function testLineItemPicksThePlacement(): void {
		// The deployment lookup would answer placement-1 (the fixture).
		$this->placementFixture = [
			'id' => 'placement-1',
			'openconnectorDeploymentId' => 'deployment-1',
			'curriculumPlanId' => 'plan-1',
			'gradeEntryComponentId' => 'component-1',
			'gradeScaleId' => '',
			'tenant_id' => 'tenant-1',
		];
		$byId = [
			'placement-2' => array_merge($this->placementFixture, ['id' => 'placement-2', 'gradeEntryComponentId' => 'component-2']),
			'placement-x' => array_merge($this->placementFixture, ['id' => 'placement-x', 'openconnectorDeploymentId' => 'deployment-9']),
		];
		$this->objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null, bool $_rbac = true, bool $_multitenancy = true) use ($byId): ?ObjectEntity {
				if ($this->refusesCaller(action: 'read', schema: (string)$schema, rbac: $_rbac, multitenancy: $_multitenancy) === true) {
					return null;
				}

				if (isset($byId[(string)$id]) === false) {
					return null;
				}

				return OrEntityFactory::make($byId[(string)$id], 'lti-tool-placement');
			}
		);

		$score = ['userId' => 'learner-1', 'scoreGiven' => 7, 'scoreMaximum' => 10];
		$this->job(
			messages: [
				$this->integriqMessage(messageUuid: 'msg-a', deploymentUuid: 'deployment-1', lineItemId: 'placement-2', score: $score),
				$this->integriqMessage(messageUuid: 'msg-b', deploymentUuid: 'deployment-1', lineItemId: 'placement-x', score: $score),
			]
		)->run(null);

		self::assertCount(2, $this->savedObjects);
		self::assertSame('placement-2', $this->savedObjects[0]['object']['ltiToolPlacementId']);
		self::assertSame('component-2', $this->savedObjects[0]['object']['componentId']);
		self::assertSame('placement-1', $this->savedObjects[1]['object']['ltiToolPlacementId'], 'a line item on another deployment falls back to the deployment');
	}//end testLineItemPicksThePlacement()

	/**
	 * The job is declared in appinfo/info.xml. Nextcloud adds every declared job
	 * on a fresh install (Installer::installApp) and on every app upgrade
	 * (AppManager::upgradeApp); a row lost in between comes back with the next
	 * version bump.
	 *
	 * @return void
	 */
	public function testTheJobIsDeclaredInInfoXml(): void {
		// String parse, not simplexml_load_file(): see ConnectionReportJobTest.
		$infoXml = simplexml_load_string((string)file_get_contents(dirname(__DIR__, 3) . '/appinfo/info.xml'));
		self::assertNotFalse($infoXml);

		$jobs = array_map('strval', $infoXml->xpath('/info/background-jobs/job'));
		self::assertContains(LtiAgsScorePollJob::class, $jobs);
	}//end testTheJobIsDeclaredInInfoXml()
}//end class
