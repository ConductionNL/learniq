<?php

/**
 * CourseEvaluationDraftCleanupJob unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/course-evaluation/spec.md#requirement-eligibility-and-duplicate-submission-are-blocked-by-a-lifecycle-guard
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\BackgroundJob;

use DateTime;
use DateTimeImmutable;
use OCA\Learniq\BackgroundJob\CourseEvaluationDraftCleanupJob;
use OCA\Learniq\Tests\Support\CapturingLogger;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Unit tests for CourseEvaluationDraftCleanupJob.
 *
 * @covers \OCA\Learniq\BackgroundJob\CourseEvaluationDraftCleanupJob
 */
class CourseEvaluationDraftCleanupJobTest extends TestCase {

	private const NOW = '2026-10-05T12:00:00+00:00';

	/**
	 * The register stand-in.
	 *
	 * @var RegisterFaithfulStore
	 */
	private RegisterFaithfulStore $store;

	/**
	 * The logger the job writes to.
	 *
	 * @var CapturingLogger
	 */
	private CapturingLogger $logger;

	/**
	 * Fresh store and logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new RegisterFaithfulStore();
		// Rights modelled: a learner-shaped caller, so a delete or read with
		// RBAC on would be refused or narrowed. The job must not depend on a session.
		$this->store->callerGroups = [];
		$this->store->actingUser = '';
		$this->logger = new CapturingLogger();
	}//end setUp()

	/**
	 * A response row as the answer page writes it.
	 *
	 * @param string $id        The row id.
	 * @param string $lifecycle draft or submitted.
	 * @param string $created   When it was created.
	 *
	 * @return void
	 */
	private function storeResponse(string $id, string $lifecycle, string $created): void {
		$this->store->rows['course-evaluation-response'][] = [
			'id' => $id,
			'campaignId' => '0c000000-0000-4000-8000-000000000001',
			'courseId' => '0d000000-0000-4000-8000-00000000000a',
			'cohortId' => null,
			'academicYear' => '2026-2027',
			'period' => 'P1',
			'overallScore' => 4.0,
			'answers' => [['questionId' => 'q1', 'ratingValue' => 4]],
			'lifecycle' => $lifecycle,
			'tenant_id' => '0f000000-0000-4000-8000-000000000001',
		];
		$this->store->created['course-evaluation-response'][$id] = new DateTime($created);
	}//end storeResponse()

	/**
	 * Build the job over the store, with the configured age (null = unset).
	 *
	 * @param int|null $maxAge The app config value, or null for the default.
	 *
	 * @return CourseEvaluationDraftCleanupJob
	 */
	private function makeJob(?int $maxAge = null): CourseEvaluationDraftCleanupJob {
		$objects = $this->createMock(ObjectService::class);
		$store = $this->store;
		$objects->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $store->findAll($config, $_rbac, $_multitenancy)
		);
		$objects->method('deleteObject')->willReturnCallback(
			fn (string $uuid, $register = null, $schema = null, bool $_rbac = true, bool $_multitenancy = true, bool $_retentionSweep = false, $currentUser = null, bool $permanent = false): bool => $store->delete((string)$schema, $uuid, $_rbac, $_multitenancy, $permanent)
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('now')->willReturn(new DateTimeImmutable(self::NOW));
		$time->method('getTime')->willReturn((new DateTimeImmutable(self::NOW))->getTimestamp());

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnCallback(
			static fn (string $app, string $key, int $default = 0): int => ($app === 'learniq' && $key === CourseEvaluationDraftCleanupJob::MAX_AGE_KEY && $maxAge !== null) ? $maxAge : $default
		);

		return new CourseEvaluationDraftCleanupJob(
			time: $time,
			objectService: $objects,
			appConfig: $appConfig,
			logger: $this->logger,
		);
	}//end makeJob()

	/**
	 * Run the job's protected run().
	 *
	 * @param CourseEvaluationDraftCleanupJob $job The job.
	 *
	 * @return void
	 */
	private static function runJob(CourseEvaluationDraftCleanupJob $job): void {
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}//end runJob()

	/**
	 * The ids left in the response schema.
	 *
	 * @return array<int, string>
	 */
	private function remaining(): array {
		return array_map(static fn (array $row): string => (string)$row['id'], ($this->store->rows['course-evaluation-response'] ?? []));
	}//end remaining()

	/**
	 * A draft older than the age is removed; a fresh draft and a submitted
	 * row (however old) stay. It reads and deletes as the system, so it works
	 * from cron with no session.
	 *
	 * @return void
	 */
	public function testAStaleDraftIsRemovedAndNothingElse(): void {
		$this->storeResponse('stale-draft', 'draft', '2026-10-05T10:00:00+00:00');
		$this->storeResponse('fresh-draft', 'draft', '2026-10-05T11:55:00+00:00');
		$this->storeResponse('old-submitted', 'submitted', '2026-09-01T10:00:00+00:00');

		self::runJob($this->makeJob());

		$this->assertSame(['fresh-draft', 'old-submitted'], $this->remaining());
		$this->assertCount(1, $this->store->deletes);
		$this->assertFalse($this->store->deletes[0]['rbac']);
		$this->assertFalse($this->store->deletes[0]['multitenancy']);
		$this->assertTrue($this->store->deletes[0]['permanent']);
		foreach ($this->store->reads as $read) {
			$this->assertFalse($read['rbac']);
			$this->assertFalse($read['multitenancy']);
		}

		$removed = array_values(array_filter($this->logger->records, static fn (array $record): bool => array_key_exists('removed', $record['context'])));
		$this->assertCount(1, $removed, 'The job logs how many drafts it removed.');
		$this->assertSame(1, $removed[0]['context']['removed']);
	}//end testAStaleDraftIsRemovedAndNothingElse()

	/**
	 * The age is an app config value: with a day configured, a two-hour-old
	 * draft stays.
	 *
	 * @return void
	 */
	public function testTheAgeIsAnAppConfigValue(): void {
		$this->storeResponse('two-hours-old', 'draft', '2026-10-05T10:00:00+00:00');

		self::runJob($this->makeJob(maxAge: 86400));

		$this->assertSame(['two-hours-old'], $this->remaining());
		$this->assertSame([], $this->store->deletes);
	}//end testTheAgeIsAnAppConfigValue()

	/**
	 * A configured age below the floor is raised to it, so a draft that a
	 * submit still in flight needs is never removed under it.
	 *
	 * @return void
	 */
	public function testAnAgeBelowTheFloorIsRaised(): void {
		$this->storeResponse('five-minutes-old', 'draft', '2026-10-05T11:55:00+00:00');

		self::runJob($this->makeJob(maxAge: 1));

		$this->assertSame(['five-minutes-old'], $this->remaining());
	}//end testAnAgeBelowTheFloorIsRaised()

	/**
	 * A draft with no creation time is left alone: its age is unknown.
	 *
	 * @return void
	 */
	public function testADraftWithoutACreationTimeStays(): void {
		$this->storeResponse('no-created', 'draft', '2026-10-05T10:00:00+00:00');
		unset($this->store->created['course-evaluation-response']['no-created']);

		self::runJob($this->makeJob());

		$this->assertSame(['no-created'], $this->remaining());
	}//end testADraftWithoutACreationTimeStays()

	/**
	 * A delete that fails is logged and not counted; a row that is not an
	 * entity is skipped; the run does not stop.
	 *
	 * @return void
	 */
	public function testAFailedDeleteIsLoggedAndNotCounted(): void {
		$stale = \OCA\Learniq\Tests\Support\OrEntityFactory::make(['id' => 'stale-draft', 'lifecycle' => 'draft'], 'course-evaluation-response');
		$stale->setCreated(new DateTime('2026-10-05T10:00:00+00:00'));
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturn(['not an entity', $stale]);
		$objects->expects($this->once())->method('deleteObject')->willThrowException(new \RuntimeException('lock timeout'));

		$time = $this->createMock(ITimeFactory::class);
		$time->method('now')->willReturn(new DateTimeImmutable(self::NOW));
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnArgument(2);

		self::runJob(new CourseEvaluationDraftCleanupJob(time: $time, objectService: $objects, appConfig: $appConfig, logger: $this->logger));

		$contexts = array_column($this->logger->records, 'context');
		$this->assertContains(['uuid' => 'stale-draft', 'error' => 'lock timeout'], $contexts);
		$removed = array_values(array_filter($contexts, static fn (array $context): bool => array_key_exists('removed', $context)));
		$this->assertSame(0, $removed[0]['removed']);
	}//end testAFailedDeleteIsLoggedAndNotCounted()

	/**
	 * The job is registered in appinfo/info.xml, or Nextcloud never schedules
	 * it (a job with a full test suite and no registration does nothing).
	 *
	 * @return void
	 */
	public function testTheJobIsRegisteredInInfoXml(): void {
		// file_get_contents() + simplexml_load_string(), not load_file(): see
		// ConnectionReportJobTest (the Nextcloud bootstrap's entity loader).
		$infoXml = simplexml_load_string(
			(string)file_get_contents(dirname(__DIR__, 3) . '/appinfo/info.xml')
		);
		$this->assertNotFalse(condition: $infoXml);

		$jobs = array_map('strval', $infoXml->xpath('/info/background-jobs/job'));
		$this->assertContains(needle: CourseEvaluationDraftCleanupJob::class, haystack: $jobs);
	}//end testTheJobIsRegisteredInInfoXml()
}//end class
