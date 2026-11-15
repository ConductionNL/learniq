<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\CoursePackage;

use OCA\Learniq\Service\CoursePackage\CoursePackageImportReporter;
use OCA\Learniq\Service\CoursePackage\ImportOrigin;
use OCA\Learniq\Service\CoursePackage\ImportReportOutcome;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * CoursePackageImportReporter builds report rows, resolves the lifecycle and
 * persists the CoursePackageImportReport with its per-outcome counts.
 *
 * @covers \OCA\Learniq\Service\CoursePackage\CoursePackageImportReporter
 * @uses   \OCA\Learniq\Service\CoursePackage\ImportOrigin
 * @uses   \OCA\Learniq\Service\CoursePackage\ImportReportOutcome
 */
class CoursePackageImportReporterTest extends TestCase {

	/**
	 * The OpenRegister object service double.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objectService;

	/**
	 * The reporter under test.
	 *
	 * @var CoursePackageImportReporter
	 */
	private CoursePackageImportReporter $reporter;

	/**
	 * Build the reporter.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectService::class);
		$this->reporter = new CoursePackageImportReporter(objectService: $this->objectService);
	}//end setUp()

	/**
	 * One report entry with the given outcome.
	 *
	 * @param string $id      Resource identifier.
	 * @param string $outcome Outcome.
	 *
	 * @return array<string, mixed>
	 */
	private function entryWith(string $id, string $outcome): array {
		return $this->reporter->entry(
			resourceIdentifier: $id,
			resourceType: 'webcontent',
			title: 'T ' . $id,
			result: ['outcome' => $outcome, 'targetType' => null, 'targetId' => null, 'reason' => null]
		);
	}//end entryWith()

	/**
	 * The origin of every persisted report in these tests.
	 *
	 * @return ImportOrigin
	 */
	private function origin(): ImportOrigin {
		return new ImportOrigin(
			sourceFilename: 'course.imscc',
			importedBy: 'teacher1',
			importedAt: '2026-01-02T03:04:05+00:00',
			tenantId: 'tenant-1'
		);
	}//end origin()

	/**
	 * An entry carries the identity fields and every result field, in a fixed key order.
	 *
	 * @return void
	 */
	public function testEntryMapsEveryField(): void {
		$this->assertSame(
			[
				'resourceIdentifier' => 'RES1',
				'resourceType' => 'imswl_xmlv1p1',
				'title' => 'A link',
				'outcome' => 'degraded',
				'targetType' => 'material',
				'targetId' => 'mat-1',
				'reason' => 'because',
			],
			$this->reporter->entry(
				resourceIdentifier: 'RES1',
				resourceType: 'imswl_xmlv1p1',
				title: 'A link',
				result: ['reason' => 'because', 'targetId' => 'mat-1', 'targetType' => 'material', 'outcome' => 'degraded']
			)
		);
	}//end testEntryMapsEveryField()

	/**
	 * `succeeded` only when every entry is imported (vacuously so for none), else `partial`.
	 *
	 * @return void
	 */
	public function testResolveLifecycle(): void {
		$this->assertSame('succeeded', $this->reporter->resolveLifecycle(entries: []));
		$this->assertSame(
			'succeeded',
			$this->reporter->resolveLifecycle(entries: [$this->entryWith('a', 'imported'), $this->entryWith('b', 'imported')])
		);
		$this->assertSame(
			'partial',
			$this->reporter->resolveLifecycle(entries: [$this->entryWith('a', 'imported'), $this->entryWith('b', 'degraded')])
		);
		$this->assertSame('partial', $this->reporter->resolveLifecycle(entries: [$this->entryWith('a', 'dropped')]));
		$this->assertSame('partial', $this->reporter->resolveLifecycle(entries: [$this->entryWith('a', 'pending-qti')]));
	}//end testResolveLifecycle()

	/**
	 * The report is saved once with counts per outcome, a `partial` lifecycle,
	 * and the saved object's serialisation is returned.
	 *
	 * @return void
	 */
	public function testPersistReportCountsOutcomesAndSaves(): void {
		$entries = [
			$this->entryWith('a', 'imported'),
			$this->entryWith('b', 'imported'),
			$this->entryWith('c', 'degraded'),
			$this->entryWith('d', 'dropped'),
			$this->entryWith('e', 'dropped'),
			$this->entryWith('f', 'dropped'),
			$this->entryWith('g', 'pending-qti'),
		];
		$saved = new ObjectEntity();
		$saved->setUuid('report-1');
		$saved->setObject(['lifecycle' => 'partial']);

		$this->objectService->expects($this->once())
			->method('saveObject')
			->with(
				$this->identicalTo(
					[
						'sourceFormat' => 'imscc',
						'sourceFilename' => 'course.imscc',
						'courseId' => 'course-1',
						'importedBy' => 'teacher1',
						'importedAt' => '2026-01-02T03:04:05+00:00',
						'lifecycle' => 'partial',
						'resourcesTotal' => 7,
						'resourcesImported' => 2,
						'resourcesDegraded' => 1,
						'resourcesDropped' => 3,
						'errorMessage' => null,
						'entries' => $entries,
						'tenant_id' => 'tenant-1',
					]
				),
				[],
				'learniq',
				'course-package-import-report'
			)
			->willReturn($saved);

		$report = $this->reporter->persistReport(
			origin: $this->origin(),
			outcome: new ImportReportOutcome(sourceFormat: 'imscc', courseId: 'course-1', entries: $entries, errorMessage: null)
		);

		$this->assertSame($saved->jsonSerialize(), $report);
	}//end testPersistReportCountsOutcomesAndSaves()

	/**
	 * A fully imported package is saved `succeeded`.
	 *
	 * @return void
	 */
	public function testPersistReportSucceededWhenEverythingImported(): void {
		$entries = [$this->entryWith('a', 'imported')];
		$this->objectService->expects($this->once())
			->method('saveObject')
			->with(
				$this->callback(
					static fn (array $object): bool => $object['lifecycle'] === 'succeeded'
						&& $object['resourcesTotal'] === 1
						&& $object['resourcesImported'] === 1
						&& $object['resourcesDegraded'] === 0
						&& $object['resourcesDropped'] === 0
				)
			)
			->willReturn(new ObjectEntity());

		$this->reporter->persistReport(
			origin: $this->origin(),
			outcome: new ImportReportOutcome(sourceFormat: 'moodle', courseId: null, entries: $entries, errorMessage: null)
		);
	}//end testPersistReportSucceededWhenEverythingImported()

	/**
	 * An error message makes the report `failed`, even when every entry was imported.
	 *
	 * @return void
	 */
	public function testPersistReportFailedWhenErrorMessage(): void {
		$entries = [$this->entryWith('a', 'imported')];
		$this->objectService->expects($this->once())
			->method('saveObject')
			->with(
				$this->callback(
					static fn (array $object): bool => $object['lifecycle'] === 'failed'
						&& $object['errorMessage'] === 'Corrupt archive'
						&& $object['courseId'] === null
						&& $object['sourceFormat'] === 'learniq-json'
				)
			)
			->willReturn(new ObjectEntity());

		$this->reporter->persistReport(
			origin: $this->origin(),
			outcome: new ImportReportOutcome(sourceFormat: 'learniq-json', courseId: null, entries: $entries, errorMessage: 'Corrupt archive')
		);
	}//end testPersistReportFailedWhenErrorMessage()

	/**
	 * The timestamp is ISO-8601 in UTC, whatever the process default timezone.
	 *
	 * @return void
	 */
	public function testNowIsAtomInUtcRegardlessOfDefaultTimezone(): void {
		$previous = date_default_timezone_get();
		date_default_timezone_set('Pacific/Kiritimati');
		try {
			$now = $this->reporter->now();
		} finally {
			date_default_timezone_set($previous);
		}

		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $now);
	}//end testNowIsAtomInUtcRegardlessOfDefaultTimezone()
}//end class
