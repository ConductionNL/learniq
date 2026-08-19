<?php

/**
 * Integration test for AssessmentPublishGuard's random-draw item-source check.
 *
 * Requires a live OpenRegister database (installed Nextcloud + learniq + openregister).
 * Run with:
 *   ./vendor/bin/phpunit --testsuite "Integration Tests"
 *
 * In CI environments without a running Nextcloud the test is skipped automatically.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Integration\Lifecycle
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @group integration
 *
 * NOTE: This test needs a live OpenRegister installation AND an authenticated
 * principal. `@group integration` does NOT keep it out of a default CI run:
 * phpunit.xml declares both testsuites and the Code Quality workflow invokes
 * `vendor/bin/phpunit` with no suite or group filter. The class therefore
 * guards its own preconditions in setUp(). Mirrors
 * XapiCompletionHandlerIntegrationTest.php's shape.
 *
 * @spec openspec/changes/assessment-item-pools-and-analysis/specs/assessment/spec.md#requirement-publishing-an-assessment-requires-a-resolvable-item-source
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Integration\Lifecycle;

use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * Integration test for AssessmentPublishGuard's extended item-source rule.
 *
 * Seeds an ItemBank + Items into the live OpenRegister database and asserts
 * that a random-draw Assessment's `publish` transition is blocked when the
 * bank has fewer distinct variant groups than drawCount, and succeeds once
 * enough matching published Items exist.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Integration\Lifecycle
 */
class AssessmentPublishGuardRandomDrawIntegrationTest extends TestCase {

	/** @var ObjectService|null */
	private ?ObjectService $objectService = null;

	/** @var TransitionEngine|null */
	private ?TransitionEngine $transitionEngine = null;

	/** Cleanup: UUIDs of objects created by this test run. */
	private array $createdUuids = [];

	/**
	 * Set up the test: verify OR is available, resolve services.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		if (class_exists(\OC::class) === false || isset(\OC::$server) === false) {
			$this->markTestSkipped('Nextcloud not bootstrapped — set up a live NC + OR environment to run integration tests.');
		}

		if (class_exists(ObjectService::class) === false) {
			$this->markTestSkipped('openregister app is not installed — integration tests require OR.');
		}

		// Seeding writes objects through OR's ObjectService. Since
		// openregister#1955 OR fail-closes anonymous writes, so with no user
		// session PermissionHandler::checkPermission() throws
		// NotAuthorizedException before the fixture is created. A PHPUnit CLI
		// process has no user session — a missing precondition, not a failure.
		if (\OC::$server->get(\OCP\IUserSession::class)->getUser() === null) {
			$this->markTestSkipped(
				'No authenticated user session — OpenRegister fail-closes anonymous object writes '
				. '(openregister#1955), so this integration test cannot seed its fixtures. '
				. 'Run it from a context that has logged a user in.'
			);
		}

		try {
			$this->objectService = \OC::$server->get(ObjectService::class);
			$this->transitionEngine = \OC::$server->get(TransitionEngine::class);
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
			foreach (array_reverse($this->createdUuids) as ['schema' => $schema, 'uuid' => $uuid]) {
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
	 * @param string $schema Schema name (e.g. 'Assessment').
	 * @param array $data Object payload.
	 *
	 * @return array The created object as an associative array.
	 */
	private function createObject(string $schema, array $data): array {
		try {
			$obj = $this->objectService->saveObject(register: 'learniq', schema: $schema, object: $data);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			$this->markTestSkipped('Learniq register/schema not seeded: ' . $e->getMessage());
		} catch (\OCA\OpenRegister\Exception\NotAuthorizedException $e) {
			// Belt and braces behind the setUp() session check — see the sibling
			// XapiCompletionHandlerIntegrationTest for the full rationale.
			$this->markTestSkipped('OpenRegister denied the fixture write: ' . $e->getMessage());
		}

		// saveObject() returns an ObjectEntity, which is JsonSerializable but
		// NOT ArrayAccess, and its serialisation exposes the UUID as `id`.
		$this->createdUuids[] = ['schema' => $schema, 'uuid' => $obj->getUuid()];

		$serialised = (array)$obj->jsonSerialize();
		$serialised['uuid'] = $obj->getUuid();

		return $serialised;
	}//end createObject()

	/**
	 * A random-draw Assessment whose ItemBank has fewer distinct variant
	 * groups than drawCount cannot publish.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/assessment-item-pools-and-analysis/specs/assessment/spec.md#scenario-a-random-draw-assessment-with-an-insufficient-pool-cannot-publish
	 */
	public function testInsufficientPoolBlocksPublish(): void {
		$bank = $this->createObject('ItemBank', ['name' => 'Integration Test Bank ' . uniqid()]);

		// Only 6 published items — fewer than drawCount 10.
		for ($i = 0; $i < 6; $i++) {
			$this->createObject(
				'Item',
				[
					'itemBankId' => $bank['uuid'],
					'title' => 'Item ' . $i,
					'interactionType' => 'textEntry',
					'qtiBody' => '<assessmentItem/>',
					'maxScore' => 1,
					'lifecycle' => 'published',
				]
			);
		}

		$assessment = $this->createObject(
			'Assessment',
			[
				'title' => 'Integration Test Assessment ' . uniqid(),
				'itemSelectionMode' => 'random-draw',
				'itemPoolConfig' => ['itemBankId' => $bank['uuid'], 'drawCount' => 10],
			]
		);

		$result = $this->transitionEngine->transition($assessment['uuid'], 'publish');

		self::assertFalse(
			($result === true || (is_array($result) === true && ($result['success'] ?? false) === true)),
			'publish MUST be blocked when the pool has fewer than drawCount distinct variant groups'
		);

	}//end testInsufficientPoolBlocksPublish()

	/**
	 * A random-draw Assessment whose ItemBank has at least drawCount
	 * distinct published Items can publish.
	 *
	 * @return void
	 */
	public function testSufficientPoolAllowsPublish(): void {
		$bank = $this->createObject('ItemBank', ['name' => 'Integration Test Bank ' . uniqid()]);

		for ($i = 0; $i < 10; $i++) {
			$this->createObject(
				'Item',
				[
					'itemBankId' => $bank['uuid'],
					'title' => 'Item ' . $i,
					'interactionType' => 'textEntry',
					'qtiBody' => '<assessmentItem/>',
					'maxScore' => 1,
					'lifecycle' => 'published',
				]
			);
		}

		$assessment = $this->createObject(
			'Assessment',
			[
				'title' => 'Integration Test Assessment ' . uniqid(),
				'itemSelectionMode' => 'random-draw',
				'itemPoolConfig' => ['itemBankId' => $bank['uuid'], 'drawCount' => 5],
			]
		);

		$result = $this->transitionEngine->transition($assessment['uuid'], 'publish');

		self::assertTrue(
			($result === true || (is_array($result) === true && ($result['success'] ?? false) === true)),
			'publish MUST succeed when the pool has at least drawCount distinct published Items'
		);

	}//end testSufficientPoolAllowsPublish()
}//end class
