<?php

/**
 * Learniq CourseTemplateService::instantiate() golden tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\Course
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
 * @spec openspec/specs/course-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Course;

use OCA\Learniq\Tests\Support\DeleteCallShape;
use OCA\Learniq\Exception\CourseTemplateRefusedException;
use OCA\Learniq\Service\Course\CourseTemplateObjects;
use OCA\Learniq\Service\Course\CourseTemplateService;
use OCA\Learniq\Service\CoursePackage\CoursePackageObjectWriter;
use OCA\Learniq\Tests\Support\CapturingLogger;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * Pins every create and delete call, the result or refusal, and the log of
 * instantiate() per scenario against
 * tests/Unit/Service/Course/golden/CourseTemplateInstantiate.json. With
 * RECORD_GOLDEN=1 the file is written instead of compared.
 *
 * @covers \OCA\Learniq\Service\Course\CourseTemplateService
 * @covers \OCA\Learniq\Service\Course\CourseTemplateBuild
 * @covers \OCA\Learniq\Service\Course\ListOfArrays
 * @uses   \OCA\Learniq\Service\Course\CourseTemplateObjects
 * @uses   \OCA\Learniq\Exception\CourseTemplateRefusedException
 */
class CourseTemplateInstantiateGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/golden/CourseTemplateInstantiate.json';

	/**
	 * Every call, in order.
	 *
	 * @var list<array<string,mixed>>
	 */
	private array $calls = [];

	/**
	 * A full template.
	 *
	 * @return array<string,mixed>
	 */
	private static function fullTemplate(): array {
		return [
			'id' => 'tpl-1',
			'name' => 'Basis',
			'level' => 'mbo-4',
			'tenant_id' => 't-1',
			'curriculumPlanSkeleton' => [
				'kind' => 'weighted',
				'components' => [['code' => 'A'], 'not-a-row', ['code' => 'B']],
				'periods' => [['period' => 1], 'x'],
				'passRules' => 'nope',
			],
			'moduleStructure' => [
				[
					'key' => 'intro',
					'name' => 'Introductie',
					'order' => 3,
					'ectsCredits' => 2.5,
					'lessons' => [
						[
							'name' => 'Les een',
							'blocksSkeleton' => [['blockId' => 'b1', 'text' => 'Hallo'], 'x', ['type' => 'video', 'order' => 2]],
						],
						'skip-me',
						['name' => 'Les twee', 'order' => 7, 'contentType' => 'quiz', 'durationMinutes' => 45, 'blocksSkeleton' => 'none'],
						['order' => null],
					],
				],
				'not-a-module',
				['name' => 'Zonder sleutel', 'order' => null, 'lessons' => 'none'],
				[],
			],
		];
	}//end fullTemplate()

	/**
	 * Scenarios: template record (null: none), name, language, create call that fails (0: none), what it fails with.
	 *
	 * @return array<string,array{0:array<string,mixed>|null,1:string,2:string,3:int,4:string}>
	 */
	public static function scenarios(): array {
		$full = self::fullTemplate();
		$bare = ['id' => 'tpl-1', 'moduleStructure' => [['lessons' => [[]]]]];
		return [
			'full template' => [$full, '  Wiskunde voor beginners  ', ' en ', 0, ''],
			'default language, no plan' => [['id' => 'tpl-1', 'level' => 'vo', 'curriculumPlanSkeleton' => [], 'moduleStructure' => 'x'], 'Kort', '   ', 0, ''],
			'plan not an array, bare template' => [$bare + ['curriculumPlanSkeleton' => 'x'], 'B', 'de', 0, ''],
			'a lesson write returns null' => [$full, 'Naam', 'nl', 4, 'null'],
			'a module write is not allowed' => [$full, 'Naam', 'nl', 3, 'not-authorized'],
			'the plan write breaks' => [$full, 'Naam', 'nl', 1, 'runtime'],
			'the course write returns null, a rollback delete fails' => [$full, 'Naam', 'nl', 2, 'null'],
			'name too long' => [$full, str_repeat('x', 256), 'nl', 0, ''],
			'empty name' => [$full, '   ', 'nl', 0, ''],
			'template missing' => [null, 'Naam', 'nl', 0, ''],
			'template empty' => [[], 'Naam', 'nl', 0, ''],
		];
	}//end scenarios()

	/**
	 * instantiate() matches the recorded scenario.
	 *
	 * @param array<string,mixed>|null $template The template record.
	 * @param string                   $name     The course name.
	 * @param string                   $language The language.
	 * @param int                      $failAt   The create call that fails, 0 for none.
	 * @param string                   $failWith 'null', 'not-authorized' or 'runtime'.
	 *
	 * @return void
	 */
	#[DataProvider('scenarios')]
	public function testInstantiateMatchesGolden(?array $template, string $name, string $language, int $failAt, string $failWith): void {
		$logger = new CapturingLogger();
		$service = $this->service(template: $template, failAt: $failAt, failWith: $failWith, logger: $logger);
		try {
			$actual = ['result' => $service->instantiate('tpl-1', $name, $language)];
		} catch (CourseTemplateRefusedException $e) {
			$actual = ['refused' => [$e->getStatus(), $e->getReason(), $e->getMessage()]];
		}

		$actual['calls'] = $this->calls;
		$actual['log'] = $logger->records;

		$key = (string)$this->dataName();
		$golden = [];
		if (file_exists(self::GOLDEN) === true) {
			$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		}

		$actual = json_decode((string)json_encode($actual, JSON_PRESERVE_ZERO_FRACTION), true);
		if (getenv('RECORD_GOLDEN') === '1') {
			$golden[$key] = $actual;
			ksort($golden);
			file_put_contents(self::GOLDEN, json_encode($golden, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)) . "\n");
		}

		$this->assertArrayHasKey($key, $golden);
		$this->assertSame($golden[$key], $actual);
	}//end testInstantiateMatchesGolden()

	/**
	 * The service over recording doubles.
	 *
	 * @param array<string,mixed>|null $template The template record.
	 * @param int                      $failAt   The create call that fails, 0 for none.
	 * @param string                   $failWith How it fails.
	 * @param CapturingLogger          $logger   The logger.
	 *
	 * @return CourseTemplateService
	 */
	private function service(?array $template, int $failAt, string $failWith, CapturingLogger $logger): CourseTemplateService {
		$this->calls = [];
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			static function () use ($template) {
				if ($template === null) {
					throw new DoesNotExistException('missing');
				}

				return OrEntityFactory::make($template);
			}
		);
		$objects->method('deleteObject')->willReturnCallback(
			DeleteCallShape::legacy(function (string $uuid, mixed $register = null, mixed $schema = null, ...$rest): bool {
				$this->calls[] = ['delete' => [$uuid, $register, $schema, $rest]];
				if ($uuid === 'id-1') {
					throw new RuntimeException('cannot delete');
				}

				return true;
			})
		);

		$creates = 0;
		$writer = $this->createMock(CoursePackageObjectWriter::class);
		$writer->method('create')->willReturnCallback(
			function (string $schema, array $object) use (&$creates, $failAt, $failWith): ?string {
				$creates++;
				if (isset($object['code']) === true) {
					$object['code'] = preg_replace('/-1700000000-[0-9a-f]{4}/', '-<time>-<rand>', (string)$object['code']);
				}

				$this->calls[] = ['create' => [$schema, $object]];
				if ($creates === $failAt) {
					return self::failure(how: $failWith);
				}

				return 'id-' . $creates;
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => '[t]' . vsprintf($text, $params));
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1700000000);

		return new CourseTemplateService(objects: new CourseTemplateObjects(objectService: $objects, writer: $writer), l10n: $l10n, timeFactory: $time, logger: $logger);
	}//end service()

	/**
	 * The failure of a create call.
	 *
	 * @param string $how 'null', 'not-authorized' or 'runtime'.
	 *
	 * @return null
	 *
	 * @throws Throwable The configured failure.
	 */
	private static function failure(string $how): ?string {
		if ($how === 'not-authorized') {
			throw new NotAuthorizedException('no');
		}

		if ($how === 'runtime') {
			throw new RuntimeException('database down');
		}

		return null;
	}//end failure()
}//end class
