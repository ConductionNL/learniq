<?php

/**
 * Learniq LessonOnboardingImporter::import() golden tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\LessonOnboarding
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
 * @spec openspec/specs/course-management/spec.md#scenario-a-lesson-plan-with-two-headings-and-an-image
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\LessonOnboarding;

use OCA\Learniq\Tests\Support\PassedArguments;
use OCA\Learniq\Service\CoursePackage\CoursePackageFileWriter;
use OCA\Learniq\Service\CoursePackage\CoursePackageObjectWriter;
use OCA\Learniq\Service\LessonOnboarding\LessonDraftBuilder;
use OCA\Learniq\Service\LessonOnboarding\LessonOnboardingImporter;
use OCA\Learniq\Service\LessonOnboarding\OfficeLessonExtractor;
use OCA\Learniq\Service\LessonOnboarding\OnboardingFolderSetting;
use OCA\Learniq\Service\LessonOnboarding\OnboardingImportException;
use OCA\Learniq\Tests\Support\CapturingLogger;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins every write, transition and log line of import(), and its result or
 * refusal, per scenario against
 * tests/Unit/Service/LessonOnboarding/golden/LessonOnboardingImport.json.
 * Random block ids are replaced by stable placeholders in order of
 * appearance, so a note still points at the same block. With RECORD_GOLDEN=1
 * the file is written instead of compared.
 *
 * @covers \OCA\Learniq\Service\LessonOnboarding\LessonOnboardingImporter
 * @covers \OCA\Learniq\Service\LessonOnboarding\LessonMaterialWriter
 * @uses   \OCA\Learniq\Service\LessonOnboarding\LessonDraftBuilder
 * @uses   \OCA\Learniq\Service\LessonOnboarding\TeacherNoteSplitter
 * @uses   \OCA\Learniq\Service\LessonOnboarding\OnboardingImportException
 */
class LessonOnboardingImportGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/golden/LessonOnboardingImport.json';

	/**
	 * Every call, in order.
	 *
	 * @var list<array<string,mixed>>
	 */
	private array $calls = [];

	/**
	 * A lesson with two sections, images and slide notes.
	 *
	 * @return array<string,mixed>
	 */
	private static function richLesson(): array {
		return [
			'title' => '  Breuken vergelijken  ',
			'sections' => [
				['heading' => 'Start', 'paragraphs' => ['Wat weten we al?'], 'images' => [], 'notes' => 'Vraag eerst naar pizza.'],
				[
					'heading' => 'Instructie',
					'paragraphs' => ['Uitleg.', 'Nog meer.'],
					'images' => [['name' => 'image 1.png', 'bytes' => 'PNG'], ['name' => 'fail.png', 'bytes' => 'X'], ['name' => 'nomaterial.png', 'bytes' => 'Y']],
					'notes' => '',
				],
				['heading' => 'Afsluiting', 'paragraphs' => [], 'images' => [['name' => 'slot.jpg', 'bytes' => 'JPG']], 'notes' => 'Exit ticket.'],
			],
			'notes' => ['A table was flattened'],
		];
	}//end richLesson()

	/**
	 * Scenarios: row overrides, course, file name and path, extractor answer, failure switches.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:array<string,mixed>|null,2:string,3:array<string,mixed>,4:array<string,bool>}>
	 */
	public static function scenarios(): array {
		$tenantCourse = ['name' => 'Rekenen', 'tenant_id' => 't-course'];
		$ok = ['status' => 'ok', 'lesson' => self::richLesson()];
		$plain = ['status' => 'ok', 'lesson' => ['title' => '', 'sections' => [['heading' => 'Een', 'paragraphs' => ['Tekst'], 'images' => []]], 'notes' => []]];
		return [
			'docx with images and notes' => [[], $tenantCourse, '-Breuken & co!.docx', $ok, []],
			'pptx outside the user folder, course without tenant' => [['format' => 'pptx'], ['name' => 'X'], '/elsewhere/Les 3.pptx', $ok, []],
			'notes without images, one note lost' => [[], $tenantCourse, '(Les) 4!.docx', ['status' => 'ok', 'lesson' => ['title' => 'T', 'sections' => [['heading' => 'Een', 'paragraphs' => ['Tekst'], 'images' => [], 'notes' => 'Alleen voor mij.']], 'notes' => []]], ['noteNull' => true]],
			'a partial read with a lesson' => [[], $tenantCourse, 'p.docx', ['status' => 'partial'] + $plain, []],
			'no title, no images' => [[], $tenantCourse, 'Kale les.docx', $plain, []],
			'long title' => [[], $tenantCourse, 'a.docx', ['status' => 'ok', 'lesson' => ['title' => str_repeat('é', 300)] + $plain['lesson']], []],
			'notes cannot be saved' => [[], $tenantCourse, 'n.docx', $ok, ['noteNull' => true]],
			'a note write throws' => [[], $tenantCourse, 'n.docx', $ok, ['noteThrows' => true]],
			'the transition fails' => [[], $tenantCourse, 'n.docx', $ok, ['transitionThrows' => true]],
			'the original material is not created' => [[], $tenantCourse, 'o.docx', $plain, ['originalNull' => true]],
			'the lesson is not created' => [[], $tenantCourse, 'n.docx', $ok, ['lessonNull' => true]],
			'reader unavailable' => [['format' => 'pptx'], $tenantCourse, 'p.pptx', ['status' => 'unavailable', 'lesson' => null], []],
			'unreadable' => [[], $tenantCourse, 'u.docx', ['status' => 'empty', 'lesson' => null], []],
			'ok without a lesson' => [[], $tenantCourse, 'u.docx', ['status' => 'ok', 'lesson' => null], []],
			'someone else\'s row' => [['teacherId' => 'other'], $tenantCourse, 'n.docx', $ok, []],
			'already imported' => [['lifecycle' => 'imported'], $tenantCourse, 'n.docx', $ok, []],
			'no course' => [[], null, 'n.docx', $ok, []],
			'no file id' => [['fileId' => 0], $tenantCourse, 'n.docx', $ok, []],
		];
	}//end scenarios()

	/**
	 * import() matches the recorded scenario.
	 *
	 * @param array<string,mixed>      $row      Row overrides.
	 * @param array<string,mixed>|null $course   The course, or null for none.
	 * @param string                   $file     File name, or a path starting with '/'.
	 * @param array<string,mixed>      $read     The extractor's answer.
	 * @param array<string,bool>       $switches Failure switches.
	 *
	 * @return void
	 */
	#[DataProvider('scenarios')]
	public function testImportMatchesGolden(array $row, ?array $course, string $file, array $read, array $switches): void {
		$this->calls = [];
		$logger = new CapturingLogger();
		$importer = $this->importer(row: $row, course: $course, file: $file, read: $read, switches: $switches, logger: $logger);
		try {
			$actual = ['result' => $importer->import(userId: 'jdevries', rowId: 'row-1', courseId: 'course-1')];
		} catch (OnboardingImportException $e) {
			$actual = ['refused' => [$e->getMessage(), $e->getStatus(), $e->getReason()]];
		}

		$actual['calls'] = $this->calls;
		$actual['log'] = $logger->records;
		$this->assertGolden(actual: self::stableIds(value: $actual));
	}//end testImportMatchesGolden()

	/**
	 * The importer over recording doubles.
	 *
	 * @param array<string,mixed>      $row      Row overrides.
	 * @param array<string,mixed>|null $course   The course.
	 * @param string                   $file     File name, or a path starting with '/'.
	 * @param array<string,mixed>      $read     The extractor's answer.
	 * @param array<string,bool>       $switches Failure switches.
	 * @param CapturingLogger          $logger   The logger.
	 *
	 * @return LessonOnboardingImporter
	 */
	private function importer(array $row, ?array $course, string $file, array $read, array $switches, CapturingLogger $logger): LessonOnboardingImporter {
		$rowData = array_merge(['teacherId' => 'jdevries', 'fileId' => 99, 'format' => 'docx', 'lifecycle' => 'detected'], $row);
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			static function (int|string $id) use ($rowData, $course) {
				if ($id === 'row-1') {
					return OrEntityFactory::make($rowData, 'lesson-onboarding-file');
				}

				if ($id === 'course-1' && $course !== null) {
					return OrEntityFactory::make($course, 'course');
				}

				throw new RuntimeException('not found');
			}
		);
		$objects->method('findAll')->willReturnCallback(
			function (...$args): array {
				$this->calls[] = ['findAll' => $args];
				return [OrEntityFactory::make(['order' => 3], 'lesson'), ['order' => 7], 'junk', ['name' => 'no order']];
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (...$args) {
				$this->calls[] = ['saveObject' => PassedArguments::of(method: [ObjectService::class, 'saveObject'], args: $args)];
				return OrEntityFactory::make([], 'lesson');
			}
		);

		$writer = $this->createMock(CoursePackageObjectWriter::class);
		$writer->method('create')->willReturnCallback(
			function (string $schema, array $object) use ($switches): ?string {
				$this->calls[] = ['create' => [$schema, $object]];
				return self::created(schema: $schema, object: $object, switches: $switches, count: count($this->calls));
			}
		);

		$files = $this->createMock(CoursePackageFileWriter::class);
		$files->method('writeBytesToFiles')->willReturnCallback(
			function (string $content, string $filename, string $importedBy, string $tenantId): ?string {
				$this->calls[] = ['writeBytesToFiles' => [$content, $filename, $importedBy, $tenantId]];
				if (str_contains($filename, 'fail') === true) {
					return null;
				}

				return '/imports/' . $filename;
			}
		);

		$extractor = $this->createMock(OfficeLessonExtractor::class);
		$extractor->method('extract')->willReturnCallback(
			function (File $node, string $format) use ($read): array {
				$this->calls[] = ['extract' => [$node->getName(), $format]];
				return $read;
			}
		);

		$engine = $this->createMock(TransitionEngine::class);
		$engine->method('transition')->willReturnCallback(
			function (...$args) use ($switches) {
				$this->calls[] = ['transition' => $args];
				if (($switches['transitionThrows'] ?? false) === true) {
					throw new RuntimeException('guard refused');
				}

				return OrEntityFactory::make([], 'lesson-onboarding-file');
			}
		);

		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn(99);
		$node->method('getName')->willReturn(basename($file));
		$path = '/jdevries/files/Lessen inbox/' . $file;
		if (str_starts_with($file, '/') === true) {
			$path = $file;
		}

		$node->method('getPath')->willReturn($path);
		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->willReturn(['not a file', $node]);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($folder);
		$setting = $this->createMock(OnboardingFolderSetting::class);
		$setting->method('tenantOf')->willReturnCallback(
			function (string $userId): string {
				$this->calls[] = ['tenantOf' => $userId];
				return 't-folder';
			}
		);

		return new LessonOnboardingImporter(
			objectService: $objects,
			objectWriter: $writer,
			fileWriter: $files,
			extractor: $extractor,
			draftBuilder: new LessonDraftBuilder(),
			transitionEngine: $engine,
			rootFolder: $root,
			folderSetting: $setting,
			logger: $logger,
		);
	}//end importer()

	/**
	 * What the object writer answers for one create call.
	 *
	 * @param string              $schema   The schema.
	 * @param array<string,mixed> $object   The object.
	 * @param array<string,bool>  $switches Failure switches.
	 * @param int                 $count    The call number.
	 *
	 * @return string|null
	 */
	private static function created(string $schema, array $object, array $switches, int $count): ?string {
		$fails = [
			'lesson' => ($switches['lessonNull'] ?? false),
			'lesson-teacher-note' => ($switches['noteNull'] ?? false),
			'material' => (str_contains((string)($object['title'] ?? ''), 'nomaterial') || (($switches['originalNull'] ?? false) && str_starts_with((string)($object['fileRef'] ?? ''), '/imports/') === false)),
		];
		if ($schema === 'lesson-teacher-note' && ($switches['noteThrows'] ?? false) === true) {
			throw new RuntimeException('note store down');
		}

		if (($fails[$schema] ?? false) === true) {
			return null;
		}

		return $schema . '-' . $count;
	}//end created()

	/**
	 * The value with every UUID replaced by a placeholder numbered in order of appearance.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	private static function stableIds(mixed $value): mixed {
		$map  = [];
		$json = (string)json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$json = (string)preg_replace_callback(
			'/[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}/',
			static function (array $match) use (&$map): string {
				$map[$match[0]] = $map[$match[0]] ?? ('<uuid-' . (count($map) + 1) . '>');
				return $map[$match[0]];
			},
			$json
		);

		return json_decode($json, true);
	}//end stableIds()

	/**
	 * Compare with, or record, this scenario's golden entry.
	 *
	 * @param mixed $actual What the scenario did.
	 *
	 * @return void
	 */
	private function assertGolden(mixed $actual): void {
		$name   = (string)$this->dataName();
		$golden = [];
		if (file_exists(self::GOLDEN) === true) {
			$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		}

		if (getenv('RECORD_GOLDEN') === '1') {
			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::GOLDEN, json_encode($golden, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n");
		}

		$this->assertArrayHasKey($name, $golden);
		$this->assertSame($golden[$name], $actual);
	}//end assertGolden()
}//end class
