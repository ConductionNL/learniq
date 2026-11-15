<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\CoursePackage;

use OCA\Learniq\Service\CoursePackage\CoursePackageFileWriter;
use OCA\Learniq\Service\CoursePackage\CoursePackageImportReporter;
use OCA\Learniq\Service\CoursePackage\CoursePackageObjectWriter;
use OCA\Learniq\Service\CoursePackage\CoursePackageOutput;
use OCA\Learniq\Service\CoursePackage\MoodleActivityRouter;
use OCA\Learniq\Service\CoursePackage\MoodleCourseImporter;
use OCA\Learniq\Service\CoursePackage\PackageContext;
use OCA\Learniq\Service\MbzExtractor;
use OCA\Learniq\Service\MoodleBackupParser;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests MoodleCourseImporter: extraction + manifest parsing of a real `.mbz`
 * fixture, and the materialisation of a parsed manifest into one Course,
 * one entry per section and one routed call per activity.
 *
 * The extractor, parser, object writer and reporter are real (over a mocked
 * ObjectService); the activity router is mocked so every call it receives
 * can be asserted.
 *
 * @covers \OCA\Learniq\Service\CoursePackage\MoodleCourseImporter
 * @uses \OCA\Learniq\Service\CoursePackage\CoursePackageObjectWriter
 * @uses \OCA\Learniq\Service\CoursePackage\CoursePackageImportReporter
 * @uses \OCA\Learniq\Service\CoursePackage\CoursePackageOutput
 * @uses \OCA\Learniq\Service\CoursePackage\CourseMetadataFilter
 * @uses \OCA\Learniq\Service\CoursePackage\PackageContext
 * @uses \OCA\Learniq\Service\MbzExtractor
 * @uses \OCA\Learniq\Service\ExtractionTarget
 * @uses \OCA\Learniq\Service\MoodleBackupParser
 * @uses \OCA\Learniq\Service\XmlChildText
 */
class MoodleCourseImporterTest extends TestCase {

	private const FIXTURES = __DIR__ . '/../../../fixtures/course-packages';

	/**
	 * Every saveObject() call: schema and payload, in order.
	 *
	 * @var list<array{schema: string, object: array<string, mixed>}>
	 */
	private array $saved = [];

	/**
	 * Every routeActivity() call, as plain data.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $routed = [];

	/**
	 * Whether saved objects come back with a uuid.
	 *
	 * @var bool
	 */
	private bool $withUuid = true;

	/**
	 * Temp directory of the current test.
	 *
	 * @var string
	 */
	private string $dir = '';

	/**
	 * Fresh recorders and temp directory path per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->saved = [];
		$this->routed = [];
		$this->withUuid = true;
		$this->dir = sys_get_temp_dir() . '/learniq-mci-' . uniqid('', true);
	}//end setUp()

	/**
	 * Remove the temp directory tree.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$this->removeTree(path: $this->dir);
	}//end tearDown()

	/**
	 * The backup is extracted into the target directory and its
	 * moodle_backup.xml parsed into sections and activities.
	 *
	 * @return void
	 */
	public function testExtractAndParseReadsTheBackup(): void {
		$manifest = $this->importer()->extractAndParse(packagePath: self::FIXTURES . '/minimal-moodle.mbz', targetDir: $this->dir);

		$this->assertFileExists($this->dir . '/moodle_backup.xml');
		$this->assertSame([['identifier' => '2', 'title' => 'Week 1', 'order' => 0]], $manifest['sectionNodes']);
		$this->assertSame(
			[
				['5', 'resource', 'Syllabus', 'activities/resource_5'],
				['6', 'quiz', 'Quiz 1', 'activities/quiz_6'],
				['7', 'forum', 'General discussion', 'activities/forum_7'],
				['8', 'url', 'Course website', 'activities/url_8'],
				['9', 'assign', 'Essay assignment', 'activities/assign_9'],
			],
			array_map(
				static fn (array $a): array => [$a['identifier'], $a['moduleType'], $a['title'], $a['directory']],
				$manifest['activities']
			)
		);
		$this->assertSame(['2', '2', '2', '2', '2'], array_column($manifest['activities'], 'sectionIdentifier'));
	}//end testExtractAndParseReadsTheBackup()

	/**
	 * A file that is not a gzipped tar fails in extraction.
	 *
	 * @return void
	 */
	public function testExtractAndParseRejectsACorruptArchive(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('not a valid gzipped tar');

		$this->importer()->extractAndParse(packagePath: self::FIXTURES . '/corrupt.bin', targetDir: $this->dir);
	}//end testExtractAndParseRejectsACorruptArchive()

	/**
	 * One Course is created; every section reports into it and every
	 * activity is routed into it, keeping the entries the caller holds.
	 *
	 * @return void
	 */
	public function testImportManifestCreatesCourseReportsSectionsAndRoutesActivities(): void {
		$package = new PackageContext('/tmp/pkg', 'anna', 't-1');
		$activities = [
			['identifier' => '5', 'moduleType' => 'resource', 'title' => 'Syllabus'],
			['identifier' => '6', 'moduleType' => 'quiz', 'title' => 'Quiz 1'],
		];
		$entries = [['existing' => true]];

		$courseId = $this->importer()->importManifest(
			manifest: [
				'sectionNodes' => [
					['identifier' => '2', 'title' => 'Week 1', 'order' => 0],
					['identifier' => '3', 'title' => 'Week 2', 'order' => 1],
				],
				'activities' => $activities,
			],
			package: $package,
			entries: $entries
		);

		$this->assertSame('course-1', $courseId);
		$this->assertCount(1, $this->saved);
		$this->assertSame('course', $this->saved[0]['schema']);
		$this->assertSame('Imported Moodle course', $this->saved[0]['object']['name']);
		$this->assertNull($this->saved[0]['object']['parentCourseId']);
		$this->assertSame('t-1', $this->saved[0]['object']['tenant_id']);

		$this->assertSame(
			[
				['activity' => $activities[0], 'courseId' => 'course-1', 'package' => $package],
				['activity' => $activities[1], 'courseId' => 'course-1', 'package' => $package],
			],
			$this->routed
		);
		$this->assertSame(
			[
				['existing' => true],
				$this->sectionEntry('2', 'Week 1', 'course-1'),
				$this->sectionEntry('3', 'Week 2', 'course-1'),
				['routed' => '5'],
				['routed' => '6'],
			],
			$entries
		);
	}//end testImportManifestCreatesCourseReportsSectionsAndRoutesActivities()

	/**
	 * When the Course comes back without a uuid, the sections are still
	 * reported, with no target, and null is returned.
	 *
	 * @return void
	 */
	public function testImportManifestWithoutACourseUuidReportsSectionsWithoutTarget(): void {
		$this->withUuid = false;
		$entries = [];

		$courseId = $this->importer()->importManifest(
			manifest: ['sectionNodes' => [['identifier' => '2', 'title' => 'Week 1', 'order' => 0]], 'activities' => []],
			package: new PackageContext('/tmp/pkg', 'anna', 't-1'),
			entries: $entries
		);

		$this->assertNull($courseId);
		$this->assertSame([$this->sectionEntry('2', 'Week 1', null)], $entries);
		$this->assertSame([], $this->routed);
	}//end testImportManifestWithoutACourseUuidReportsSectionsWithoutTarget()

	/**
	 * An empty manifest still creates the Course and nothing else.
	 *
	 * @return void
	 */
	public function testImportManifestWithNoSectionsOrActivities(): void {
		$entries = [];

		$courseId = $this->importer()->importManifest(
			manifest: ['sectionNodes' => [], 'activities' => []],
			package: new PackageContext('/tmp/pkg', 'anna', 't-1'),
			entries: $entries
		);

		$this->assertSame('course-1', $courseId);
		$this->assertSame(['course'], array_column($this->saved, 'schema'));
		$this->assertSame([], $entries);
		$this->assertSame([], $this->routed);
	}//end testImportManifestWithNoSectionsOrActivities()

	/**
	 * The importer under test.
	 *
	 * @return MoodleCourseImporter
	 */
	private function importer(): MoodleCourseImporter {
		$counters = [];
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], $register = null, $schema = null) use (&$counters) {
				$schema = (string)$schema;
				$this->assertSame('learniq', $register);
				$this->saved[] = ['schema' => $schema, 'object' => $object];
				$counters[$schema] = ($counters[$schema] ?? 0) + 1;
				$uuid = null;
				if ($this->withUuid === true) {
					$uuid = $schema . '-' . $counters[$schema];
				}

				return OrEntityFactory::make($object, $schema, 'learniq', $uuid);
			}
		);

		$router = $this->createMock(MoodleActivityRouter::class);
		$router->method('routeActivity')->willReturnCallback(
			function (array $activity, string $courseId, PackageContext $package, array &$entries): void {
				$this->routed[] = ['activity' => $activity, 'courseId' => $courseId, 'package' => $package];
				$entries[] = ['routed' => $activity['identifier']];
			}
		);

		return new MoodleCourseImporter(
			new MbzExtractor(),
			new MoodleBackupParser(),
			$router,
			new CoursePackageOutput(
				new CoursePackageObjectWriter($objectService),
				$this->createStub(CoursePackageFileWriter::class),
				new CoursePackageImportReporter($objectService)
			),
		);
	}//end importer()

	/**
	 * The entry a section reports.
	 *
	 * @param string      $identifier Section identifier.
	 * @param string      $title      Section title.
	 * @param string|null $courseId   The Course.
	 *
	 * @return array<string, mixed>
	 */
	private function sectionEntry(string $identifier, string $title, ?string $courseId): array {
		return [
			'resourceIdentifier' => $identifier,
			'resourceType' => 'section',
			'title' => $title,
			'outcome' => 'imported',
			'targetType' => 'course',
			'targetId' => $courseId,
			'reason' => null,
		];
	}//end sectionEntry()

	/**
	 * Remove a file or directory tree.
	 *
	 * @param string $path The path.
	 *
	 * @return void
	 */
	private function removeTree(string $path): void {
		if (is_dir($path) === false) {
			if (is_file($path) === true) {
				unlink($path);
			}

			return;
		}

		foreach (array_diff((array)scandir($path), ['.', '..']) as $item) {
			$this->removeTree(path: $path . '/' . $item);
		}

		rmdir($path);
	}//end removeTree()
}//end class
