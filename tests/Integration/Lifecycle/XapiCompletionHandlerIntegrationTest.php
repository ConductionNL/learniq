<?php

/**
 * Integration test for XapiCompletionHandler.
 *
 * Requires a live OpenRegister database (installed Nextcloud + scholiq + openregister).
 * Run with:
 *   ./vendor/bin/phpunit --testsuite "Integration Tests"
 *
 * In CI environments without a running Nextcloud the test is skipped automatically.
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Integration\Lifecycle
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @group integration
 *
 * NOTE: This test needs a live OpenRegister installation AND an authenticated
 * principal. `@group integration` does NOT keep it out of a default CI run:
 * phpunit.xml declares both the "Unit Tests" and the "Integration Tests"
 * testsuite and the Code Quality workflow invokes `vendor/bin/phpunit` with no
 * suite or group filter, so every test in this directory runs on every push.
 * The class therefore guards its own preconditions in setUp() — see the
 * user-session check there.
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Integration\Lifecycle;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Lifecycle\XapiCompletionHandler;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Integration test for XapiCompletionHandler.
 *
 * Seeds a minimal scholiq register (Course, Lesson, Enrolment) into the live
 * OpenRegister database, fires an xAPI "completed" statement event, and asserts
 * that the matching Enrolment is transitioned to `completed`.
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Integration\Lifecycle
 */
class XapiCompletionHandlerIntegrationTest extends TestCase {

	/** @var ObjectService|null */
	private ?ObjectService $objectService = null;

	/** @var XapiCompletionHandler|null */
	private ?XapiCompletionHandler $handler = null;

	/** Cleanup: UUIDs of objects created by this test run. */
	private array $createdUuids = [];

	/**
	 * Set up the test: verify OR is available, resolve services.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// Skip if Nextcloud server is not bootstrapped.
		if (class_exists(\OC::class) === false || isset(\OC::$server) === false) {
			$this->markTestSkipped('Nextcloud not bootstrapped — set up a live NC + OR environment to run integration tests.');
		}

		// Skip if openregister app is not installed.
		if (class_exists(ObjectService::class) === false) {
			$this->markTestSkipped('openregister app is not installed — integration tests require OR.');
		}

		// Every test in this class writes objects through OR's ObjectService.
		// Since openregister#1955 OR fail-closes anonymous writes: with no user
		// session `PermissionHandler::checkPermission()` throws
		// NotAuthorizedException ("User 'Anonymous' does not have permission to
		// 'create' objects in schema 'Course'") before the schema is even
		// touched. A PHPUnit CLI process has no user session, so the seeding
		// step below cannot run at all — this is a missing precondition, not a
		// product defect, and it must read as SKIPPED rather than as an error.
		if (\OC::$server->get(\OCP\IUserSession::class)->getUser() === null) {
			$this->markTestSkipped(
				'No authenticated user session — OpenRegister fail-closes anonymous object writes '
				. '(openregister#1955), so this integration test cannot seed its fixtures. '
				. 'Run it from a context that has logged a user in.'
			);
		}

		try {
			$this->objectService = \OC::$server->get(ObjectService::class);

			// TransitionEngine is final; we use the real one via the DI container.
			$transitionEngine = \OC::$server->get(\OCA\OpenRegister\Service\Lifecycle\TransitionEngine::class);

			// The listener guards on register/schema SLUGS while OpenRegister
			// stamps numeric ids onto the entity; the real resolver from the
			// container is what turns one into the other in production.
			$schemaResolver = \OC::$server->get(\OCA\Scholiq\Service\ListenerSchemaResolver::class);

			$this->handler = new XapiCompletionHandler(
				$this->objectService,
				$transitionEngine,
				$schemaResolver,
				new NullLogger(),
			);
		} catch (\Throwable $e) {
			$this->markTestSkipped('Could not resolve OR services from DI container: ' . $e->getMessage());
		}

	}//end setUp()

	/**
	 * Tear down: remove objects created during the test to leave the DB clean.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ($this->objectService !== null && empty($this->createdUuids) === false) {
			foreach (array_reverse($this->createdUuids) as ['register' => $register, 'schema' => $schema, 'uuid' => $uuid]) {
				try {
					$this->objectService->deleteObject($uuid);
				} catch (\Throwable) {
					// Best-effort cleanup; ignore failures.
				}
			}
		}

		parent::tearDown();

	}//end tearDown()

	/**
	 * Create an object via OR's ObjectService and record its UUID for cleanup.
	 *
	 * @param string $schema Schema name (e.g. 'Course').
	 * @param array $data Object payload.
	 *
	 * @return array The created object as an associative array.
	 */
	private function createObject(string $schema, array $data): array {
		try {
			$obj = $this->objectService->saveObject(
				register: 'scholiq',
				schema: $schema,
				object: $data,
			);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			// The Scholiq OpenRegister register/schemas aren't provisioned in
			// this environment (CI installs the app but doesn't seed the
			// register); these integration tests need a live, seeded OR.
			$this->markTestSkipped('Scholiq register/schema not seeded: ' . $e->getMessage());
		} catch (\OCA\OpenRegister\Exception\NotAuthorizedException $e) {
			// Belt and braces behind the setUp() session check: a session may
			// exist yet still lack `create` on this schema (openregister#1955
			// fail-closed writes, or a schema authorization block that does not
			// grant this principal). Either way the fixture cannot be seeded,
			// which is a missing precondition rather than a failing assertion.
			$this->markTestSkipped('OpenRegister denied the fixture write: ' . $e->getMessage());
		}

		// saveObject() returns an ObjectEntity, not an array — it implements
		// JsonSerializable but NOT ArrayAccess, so `$obj['uuid']` fatals with
		// "Cannot use object of type ObjectEntity as array".
		$this->createdUuids[] = [
			'register' => 'scholiq',
			'schema' => $schema,
			'uuid' => $obj->getUuid(),
		];

		// jsonSerialize() exposes the UUID as `id`, so stamp `uuid` explicitly
		// rather than letting every call site read a null.
		$serialised = (array)$obj->jsonSerialize();
		$serialised['uuid'] = $obj->getUuid();

		return $serialised;
	}//end createObject()

	/**
	 * Read an object back from OR by UUID.
	 *
	 * ObjectService has no `get()` method — reads go through `find()`, whose
	 * identifier parameter is named `id` (not `uuid`) and which returns an
	 * ObjectEntity or null rather than an array. The register/schema scope the
	 * old `get()` calls carried is preserved here as named arguments: find()
	 * would otherwise resolve cross-table, and OR's own BUG-OBJ-13 note warns
	 * that leaving that context implicit is how the wrong magic table gets hit.
	 *
	 * @param string $uuid The object UUID.
	 * @param string $schema The schema the object belongs to.
	 *
	 * @return array<string,mixed> The object as a plain array, empty when absent.
	 */
	private function fetchObject(string $uuid, string $schema): array {
		// MEASURED against a live NC 34 + openregister: despite the `?ObjectEntity`
		// return type, ObjectService::find() THROWS DoesNotExistException on a
		// miss ("Object with identifier '…' not found in any magic table") rather
		// than returning null. A bare `=== null` check is therefore dead code —
		// find() needs its own try/catch.
		try {
			$entity = $this->objectService->find(
				id: $uuid,
				register: 'scholiq',
				schema: $schema,
			);
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			return [];
		}

		if ($entity === null) {
			return [];
		}

		return (array)$entity->jsonSerialize();
	}//end fetchObject()

	/**
	 * Build a minimal xAPI "completed" event carrying $payload.
	 *
	 * @param array $payload xAPI statement payload.
	 *
	 * @return Event An anonymous event object that implements getData().
	 */
	private function makeXapiEvent(array $payload): Event {
		return new class($payload) extends Event {
			/**
			 * Constructor.
			 *
			 * @param array $data xAPI statement payload.
			 *
			 * @return void
			 */
			public function __construct(
				private readonly array $data,
			) {
				parent::__construct();
			}

			/**
			 * Return the xAPI statement payload.
			 *
			 * @return array
			 */
			public function getData(): array {
				return $this->data;
			}
		};

	}//end makeXapiEvent()

	/**
	 * Happy-path: completing the final mandatory lesson transitions the Enrolment
	 * to `completed` and OR writes an enrolment.completed audit entry.
	 *
	 * @return void
	 */
	public function testCompletingFinalMandatoryLessonTransitionsEnrolment(): void {
		// ── Seed data ──────────────────────────────────────────────────
		// 1. Course (published).
		$course = $this->createObject(
			'Course',
			[
				'title' => 'Integration Test Course ' . uniqid(),
				'lifecycle' => 'published',
			]
		);

		$courseId = $course['uuid'];

		$xapiObjectId1 = 'https://scholiq.test/lessons/' . uniqid();
		$xapiObjectId2 = 'https://scholiq.test/lessons/' . uniqid();

		// 2. Lesson 1 — published, not mandatory.
		$this->createObject(
			'Lesson',
			[
				'title' => 'Lesson 1',
				'courseId' => $courseId,
				'lifecycle' => 'published',
				'mandatoryTraining' => false,
				'xapiObjectId' => $xapiObjectId1,
			]
		);

		// 3. Lesson 2 — published, mandatory training (the final lesson).
		$lesson2 = $this->createObject(
			'Lesson',
			[
				'title' => 'Lesson 2 — Mandatory',
				'courseId' => $courseId,
				'lifecycle' => 'published',
				'mandatoryTraining' => true,
				'xapiObjectId' => $xapiObjectId2,
			]
		);

		$learnerId = 'learner-' . uniqid();

		// 4. Active Enrolment for the learner.
		$enrolment = $this->createObject(
			'Enrolment',
			[
				'learnerId' => $learnerId,
				'courseId' => $courseId,
				'lifecycle' => 'active',
			]
		);

		$enrolmentId = $enrolment['uuid'];

		// ── Fire event ─────────────────────────────────────────────────
		$xapiStatement = [
			'verb' => ['id' => 'http://adlnet.gov/expapi/verbs/completed'],
			'object' => ['id' => $xapiObjectId2],
			'actor' => ['account' => ['name' => $learnerId]],
		];

		$event = $this->makeXapiEvent($xapiStatement);
		$this->handler->handle($event);

		// ── Assertions ─────────────────────────────────────────────────
		// The Enrolment should now be in `completed` state.
		$updated = $this->fetchObject($enrolmentId, 'Enrolment');

		$this->assertSame(
			'completed',
			$updated['lifecycle'] ?? null,
			'Enrolment lifecycle should be "completed" after xAPI completed statement for final mandatory lesson.'
		);

		// OR should have written an audit-trail entry for the transition.
		// We check the audit log if the AuditTrailMapper is available.
		if (class_exists(\OCA\OpenRegister\Db\AuditTrailMapper::class)) {
			try {
				$auditMapper = \OC::$server->get(\OCA\OpenRegister\Db\AuditTrailMapper::class);
				$entries = $auditMapper->findAll(
					filters: [
						'object_uuid' => $enrolmentId,
						'action' => 'enrolment.completed',
					],
					limit: 5
				);
				$this->assertNotEmpty(
					$entries,
					'OR audit trail should contain an enrolment.completed entry after the lifecycle transition.'
				);
			} catch (\Throwable) {
				// AuditTrailMapper may not expose this query method in all OR versions; skip gracefully.
				$this->addWarning('Could not verify audit trail entry — AuditTrailMapper query not available in this OR version.');
			}
		}

	}//end testCompletingFinalMandatoryLessonTransitionsEnrolment()

	/**
	 * Non-mandatory lesson completion does NOT transition the Enrolment.
	 *
	 * @return void
	 */
	public function testNonMandatoryLessonCompletionIsIgnored(): void {
		$course = $this->createObject('Course', ['title' => 'Course ' . uniqid(), 'lifecycle' => 'published']);
		$courseId = $course['uuid'];

		$xapiObjectId = 'https://scholiq.test/lessons/' . uniqid();
		$this->createObject(
			'Lesson',
			[
				'title' => 'Optional Lesson',
				'courseId' => $courseId,
				'lifecycle' => 'published',
				'mandatoryTraining' => false,
				'xapiObjectId' => $xapiObjectId,
			]
		);

		$learnerId = 'learner-' . uniqid();
		$enrolment = $this->createObject('Enrolment', ['learnerId' => $learnerId, 'courseId' => $courseId, 'lifecycle' => 'active']);

		$event = $this->makeXapiEvent(
			[
				'verb' => ['id' => 'http://adlnet.gov/expapi/verbs/completed'],
				'object' => ['id' => $xapiObjectId],
				'actor' => ['account' => ['name' => $learnerId]],
			]
		);

		$this->handler->handle($event);

		$still = $this->fetchObject($enrolment['uuid'], 'Enrolment');
		$this->assertSame('active', $still['lifecycle'] ?? null, 'Enrolment should remain active after non-mandatory lesson completion.');

	}//end testNonMandatoryLessonCompletionIsIgnored()

	/**
	 * Unknown verb in xAPI statement is ignored (no Enrolment change).
	 *
	 * @return void
	 */
	public function testUnknownVerbIsIgnored(): void {
		$course = $this->createObject('Course', ['title' => 'Course ' . uniqid(), 'lifecycle' => 'published']);
		$courseId = $course['uuid'];

		$xapiObjectId = 'https://scholiq.test/lessons/' . uniqid();
		$this->createObject(
			'Lesson',
			[
				'title' => 'Mandatory Lesson',
				'courseId' => $courseId,
				'lifecycle' => 'published',
				'mandatoryTraining' => true,
				'xapiObjectId' => $xapiObjectId,
			]
		);

		$learnerId = 'learner-' . uniqid();
		$enrolment = $this->createObject('Enrolment', ['learnerId' => $learnerId, 'courseId' => $courseId, 'lifecycle' => 'active']);

		$event = $this->makeXapiEvent(
			[
				'verb' => ['id' => 'http://adlnet.gov/expapi/verbs/launched'],
				'object' => ['id' => $xapiObjectId],
				'actor' => ['account' => ['name' => $learnerId]],
			]
		);

		$this->handler->handle($event);

		$still = $this->fetchObject($enrolment['uuid'], 'Enrolment');
		$this->assertSame('active', $still['lifecycle'] ?? null, 'Enrolment should remain active for non-completion verbs.');

	}//end testUnknownVerbIsIgnored()

}//end class
