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
use OCA\Learniq\Service\CoursePackage\PackageContext;
use OCA\Learniq\Service\CoursePackage\PackageXmlValueReader;
use OCA\Learniq\Service\MoodleQuizQuestionMapper;
use OCA\Learniq\Tests\Support\CapturingLogger;
use OCA\Learniq\Tests\Support\GoldenFile;
use PHPUnit\Framework\TestCase;
use RuntimeException;

// The unit bootstrap does not load the golden-file helper; the full one does.
require_once __DIR__ . '/../../../Support/GoldenFile.php';

/**
 * Pins MoodleActivityRouter::routeActivity() against
 * golden/MoodleActivityRouter.json: per activity, the report entries it
 * appends, every call on its collaborators in order, and what was logged.
 *
 * @covers \OCA\Learniq\Service\CoursePackage\MoodleActivityRouter
 * @uses \OCA\Learniq\Service\CoursePackage\CoursePackageOutput
 * @uses \OCA\Learniq\Service\CoursePackage\PackageContext
 */
class MoodleActivityRouterGoldenTest extends TestCase {
	use GoldenFile;

	private const GOLDEN = __DIR__ . '/golden/MoodleActivityRouter.json';

	/**
	 * Calls on the collaborators, in order.
	 *
	 * @var list<mixed>
	 */
	private array $calls = [];

	/**
	 * Every activity kind routes, writes and reports as recorded.
	 *
	 * @return void
	 */
	public function testRoutingMatchesGolden(): void {
		$dir = sys_get_temp_dir() . '/learniq-mar-golden-' . getmypid();
		@mkdir($dir . '/activities/quiz_6', 0777, true);
		@mkdir($dir . '/activities/quiz_7', 0777, true);
		file_put_contents($dir . '/activities/quiz_6/questions.xml', '<questions/>');

		$logger = new CapturingLogger();
		$router = new MoodleActivityRouter(
			$this->quizMapper(),
			new CoursePackageOutput($this->objectWriter(), $this->fileWriter(), $this->reporter()),
			$this->xmlReader(),
			$logger
		);
		$package = new PackageContext($dir, 'anna', 'tenant-1');

		$actual = [];
		foreach ($this->activities() as $name => $activity) {
			$this->calls = [];
			$entries = [['existing' => true]];
			$router->routeActivity(activity: $activity, courseId: 'course-1', package: $package, entries: $entries);
			$actual[$name] = [
				'entries' => $entries,
				'calls' => str_replace($dir, '<dir>', (string)json_encode($this->calls, JSON_UNESCAPED_SLASHES)),
			];
		}

		$actual['log'] = $logger->dump();

		@unlink($dir . '/activities/quiz_6/questions.xml');
		@rmdir($dir . '/activities/quiz_6');
		@rmdir($dir . '/activities/quiz_7');
		@rmdir($dir . '/activities');
		@rmdir($dir);

		$this->assertMatchesGolden(self::GOLDEN, $actual);
	}//end testRoutingMatchesGolden()

	/**
	 * One activity row per route.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function activities(): array {
		return [
			'scorm' => $this->activity('1', 'scorm', 'activities/scorm_1'),
			'cmi5' => $this->activity('2', 'cmi5', null),
			'forum' => $this->activity('3', 'forum', 'activities/forum_3'),
			'wiki' => $this->activity('4', 'wiki', null),
			'unknown' => $this->activity('5', 'chat', null),
			'resource with directory' => $this->activity('10', 'resource', 'activities/resource_10'),
			'page without directory' => $this->activity('11', 'page', null),
			'url resolved' => $this->activity('12', 'url', 'activities/url_12'),
			'url unresolved' => $this->activity('13', 'url', 'activities/url_missing'),
			'url without directory' => $this->activity('14', 'url', null),
			'assign' => $this->activity('15', 'assign', 'activities/assign_15'),
			'quiz without directory' => $this->activity('16', 'quiz', null),
			'quiz without bank' => $this->activity('17', 'quiz', 'activities/quiz_7'),
			'quiz with bank' => $this->activity('18', 'quiz', 'activities/quiz_6'),
			'failing material' => array_merge($this->activity('19', 'resource', null), ['title' => 'boom']),
		];
	}//end activities()

	/**
	 * One `MoodleBackupParser` activity row.
	 *
	 * @param string      $id        The module identifier, doubling as order.
	 * @param string      $type      The module type and classification.
	 * @param string|null $directory The activity's backup directory.
	 *
	 * @return array<string,mixed>
	 */
	private function activity(string $id, string $type, ?string $directory): array {
		return [
			'identifier' => $id,
			'classification' => $type,
			'moduleType' => $type,
			'title' => 'Activity ' . $id,
			'order' => (int)$id,
			'directory' => $directory,
		];
	}//end activity()

	/**
	 * Object writer that records every create and answers a predictable id.
	 *
	 * @return CoursePackageObjectWriter
	 */
	private function objectWriter(): CoursePackageObjectWriter {
		$writer = $this->createMock(CoursePackageObjectWriter::class);
		$writer->method('createMaterial')->willReturnCallback(function (array $material, string $tenantId): ?string {
			$this->calls[] = ['createMaterial', $material, $tenantId];
			if ($material['title'] === 'boom') {
				throw new RuntimeException('material store down');
			}

			return 'material-' . count($this->calls);
		});
		$writer->method('createLesson')->willReturnCallback(function (array $lesson, string $tenantId): ?string {
			$this->calls[] = ['createLesson', $lesson, $tenantId];
			return 'lesson-' . count($this->calls);
		});
		$writer->method('createItemBank')->willReturnCallback(function (string $name, string $tenantId): ?string {
			$this->calls[] = ['createItemBank', $name, $tenantId];
			return 'bank-' . count($this->calls);
		});
		$writer->method('create')->willReturnCallback(function (string $schema, array $object): ?string {
			$this->calls[] = ['create', $schema, $object];
			return $schema . '-' . count($this->calls);
		});

		return $writer;
	}//end objectWriter()

	/**
	 * File writer that records every lookup.
	 *
	 * @return CoursePackageFileWriter
	 */
	private function fileWriter(): CoursePackageFileWriter {
		$writer = $this->createMock(CoursePackageFileWriter::class);
		$writer->method('resolveFileRef')->willReturnCallback(function (string $dir, ?string $href, string $importedBy, string $tenantId): ?string {
			$this->calls[] = ['resolveFileRef', $dir, $href, $importedBy, $tenantId];
			if ($href === null) {
				return null;
			}

			return 'file:' . $href;
		});

		return $writer;
	}//end fileWriter()

	/**
	 * Reporter whose entry is its own arguments.
	 *
	 * @return CoursePackageImportReporter
	 */
	private function reporter(): CoursePackageImportReporter {
		$reporter = $this->createMock(CoursePackageImportReporter::class);
		$reporter->method('entry')->willReturnCallback(
			static fn (string $resourceIdentifier, string $resourceType, string $title, array $result): array => [
				'resourceIdentifier' => $resourceIdentifier,
				'resourceType' => $resourceType,
				'title' => $title,
				'result' => $result,
			]
		);

		return $reporter;
	}//end reporter()

	/**
	 * Xml reader that only knows the url module of activity 12.
	 *
	 * @return PackageXmlValueReader
	 */
	private function xmlReader(): PackageXmlValueReader {
		$reader = $this->createMock(PackageXmlValueReader::class);
		$reader->method('readTextContent')->willReturnCallback(function (string $path, string $tagName): ?string {
			$this->calls[] = ['readTextContent', $path, $tagName];
			if (str_contains($path, 'url_12') === true) {
				return 'https://example.org/target';
			}

			return null;
		});

		return $reader;
	}//end xmlReader()

	/**
	 * Quiz mapper answering one mapped and one dropped question.
	 *
	 * @return MoodleQuizQuestionMapper
	 */
	private function quizMapper(): MoodleQuizQuestionMapper {
		$mapper = $this->createMock(MoodleQuizQuestionMapper::class);
		$mapper->method('mapQuestions')->willReturnCallback(function (string $questionsXmlPath, string $itemBankId, string $tenantId): array {
			$this->calls[] = ['mapQuestions', $questionsXmlPath, $itemBankId, $tenantId];
			return [
				['outcome' => 'mapped', 'moodleQuestionType' => 'multichoice', 'title' => 'Q one', 'itemData' => ['stem' => 'one', 'itemBankId' => $itemBankId]],
				['outcome' => 'dropped', 'moodleQuestionType' => 'ddwtos', 'title' => 'Q two', 'reason' => 'Unsupported question type.'],
			];
		});

		return $mapper;
	}//end quizMapper()
}//end class
