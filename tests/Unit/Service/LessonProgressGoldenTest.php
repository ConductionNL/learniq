<?php

/**
 * Learniq LessonProgress characterization test.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
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
 * @spec openspec/specs/progress-tracking/spec.md#requirement-a-lesson-completion-belongs-to-one-enrolment
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\LessonProgress;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Pins every ObjectService call LessonProgress::record() makes, per scenario,
 * against tests/Unit/Service/golden/LessonProgress.json. With RECORD_GOLDEN=1
 * the file is written instead of compared.
 *
 * @covers \OCA\Learniq\Service\LessonProgress
 */
class LessonProgressGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/golden/LessonProgress.json';

	/**
	 * The lesson, enrolment and completion rows a scenario starts from.
	 *
	 * @return array<string, array<string, list<array<string, mixed>>>>
	 */
	private static function stores(): array {
		$lesson = ['id' => 'lesson-1', 'courseId' => 'course-1', 'tenant_id' => 't1'];
		return [
			'empty' => ['lesson' => [$lesson]],
			'active' => [
				'lesson' => [$lesson],
				'enrolment' => [['id' => 'enr-active', 'learnerId' => 'ann', 'courseId' => 'course-1', 'lifecycle' => 'active', 'tenant_id' => 't1']],
			],
			'pending' => [
				'lesson' => [$lesson],
				'enrolment' => [['uuid' => 'enr-pending', 'learnerId' => 'ann', 'courseId' => 'course-1', 'lifecycle' => 'pending', 'tenant_id' => 't1']],
			],
			'same-enrolment-row' => [
				'lesson' => [$lesson],
				'enrolment' => [['id' => 'enr-active', 'learnerId' => 'ann', 'courseId' => 'course-1', 'lifecycle' => 'active', 'tenant_id' => 't1']],
				'lesson-completion' => [
					['id' => 'lc-old', 'learnerId' => 'ann', 'lessonId' => 'lesson-1', 'enrolmentId' => 'enr-old', 'score' => 0.1],
					['id' => 'lc-1', 'learnerId' => 'ann', 'lessonId' => 'lesson-1', 'enrolmentId' => 'enr-active', 'score' => 0.2, 'note' => 'kept'],
				],
			],
			'other-enrolment-row' => [
				'lesson' => [$lesson],
				'enrolment' => [['id' => 'enr-active', 'learnerId' => 'ann', 'courseId' => 'course-1', 'lifecycle' => 'active', 'tenant_id' => 't1']],
				'lesson-completion' => [['id' => 'lc-old', 'learnerId' => 'ann', 'lessonId' => 'lesson-1', 'enrolmentId' => 'enr-old']],
			],
			'legacy-row' => [
				'lesson' => [$lesson],
				'lesson-completion' => [['id' => 'lc-legacy', 'learnerId' => 'ann', 'lessonId' => 'lesson-1', 'verb' => 'old']],
			],
			'entity-rows' => [
				'lesson' => [$lesson],
				'enrolment' => [['id' => 'enr-active', 'learnerId' => 'ann', 'courseId' => 'course-1', 'lifecycle' => 'active', 'tenant_id' => 't1']],
				'lesson-completion' => [['id' => 'lc-e', 'learnerId' => 'ann', 'lessonId' => 'lesson-1', 'enrolmentId' => 'enr-active']],
				'@entities' => [],
			],
			'lesson-without-course' => ['lesson' => [['id' => 'lesson-1', 'tenant_id' => 't1']]],
			'numeric-course' => ['lesson' => [['id' => 'lesson-1', 'courseId' => 7, 'tenant_id' => 't1']]],
			'lesson-by-uuid' => ['lesson' => [['uuid' => 'lesson-u', 'courseId' => 'course-1']]],
			'numeric-lesson-id' => ['lesson' => [['id' => 42, 'courseId' => 'course-1', 'tenant_id' => 't1']]],
		];
	}//end stores()

	/**
	 * Each scenario: a store name and the statement as saved.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>}>
	 */
	public static function scenarios(): array {
		$completed = 'http://adlnet.gov/expapi/verbs/completed';
		$passed    = 'http://adlnet.gov/expapi/verbs/passed';
		$base      = [
			'tenant_id' => 't1', 'lessonId' => 'lesson-1', 'verified_actor_id' => 'ann',
			'verb' => ['id' => $completed], 'result' => ['score' => ['scaled' => 0.75]],
		];
		$forged    = array_merge($base, ['actor' => ['account' => ['name' => 'mallory']], 'learnerId' => 'mallory', 'userId' => 'mallory']);
		return [
			'fresh, no enrolment' => ['empty', $base],
			'fresh, active enrolment' => ['active', $base],
			'fresh, pending enrolment' => ['pending', $base],
			'passed, no score' => ['active', array_merge($base, ['verb' => ['id' => $passed], 'result' => []])],
			'updates the row of the same enrolment' => ['same-enrolment-row', $base],
			'adds a row for another enrolment' => ['other-enrolment-row', $base],
			'legacy row matched by a completion without enrolment' => ['legacy-row', $base],
			'rows as entities' => ['entity-rows', $base],
			'no tenant' => ['empty', array_merge($base, ['tenant_id' => ''])],
			'unknown verb' => ['active', array_merge($base, ['verb' => ['id' => 'http://example.org/verbs/viewed']])],
			'no verified actor' => ['active', array_diff_key($base, ['verified_actor_id' => true])],
			'forged actor fields are ignored' => ['active', $forged],
			'lesson without course' => ['lesson-without-course', $base],
			'lesson by uuid' => ['lesson-by-uuid', array_merge($base, ['lessonId' => 'lesson-u', 'tenant_id' => ''])],
			'no lesson id' => ['active', array_diff_key($base, ['lessonId' => true])],
			'whole-number score' => ['active', array_merge($base, ['result' => ['score' => ['scaled' => 1]]])],
			'text score' => ['active', array_merge($base, ['result' => ['score' => ['scaled' => '0.5']]])],
			'numeric course id' => ['numeric-course', $base],
			'numeric lesson id' => ['numeric-lesson-id', array_merge($base, ['lessonId' => '42'])],
		];
	}//end scenarios()

	/**
	 * Every scenario's calls equal the recorded golden.
	 *
	 * @return void
	 */
	public function testEveryScenarioMakesTheRecordedCalls(): void {
		$actual = [];
		foreach (self::scenarios() as $name => [$store, $statement]) {
			$actual[$name] = $this->callsFor($store, $statement);
		}

		$json = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)) . "\n";
		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, $json);
		}

		$this->assertSame(file_get_contents(self::GOLDEN), $json);
	}//end testEveryScenarioMakesTheRecordedCalls()

	/**
	 * Run record() over one store and return the calls it made.
	 *
	 * @param string               $store     The store name from stores().
	 * @param array<string, mixed> $statement The statement as saved.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function callsFor(string $store, array $statement): array {
		$rows     = self::stores()[$store];
		$entities = isset($rows['@entities']);
		$calls    = [];
		$service  = $this->createMock(ObjectService::class);
		$service->method('findAll')->willReturnCallback(
			static function (array $config) use ($rows, $entities, &$calls): array {
				$calls[] = ['findAll' => $config];
				$found   = [];
				foreach (($rows[$config['filters']['schema']] ?? []) as $row) {
					if (self::rowMatches($row, $config) === true) {
						$found[] = $row;
					}
				}

				if ($entities === true) {
					return OrEntityFactory::makeMany($found, $config['filters']['schema']);
				}

				return $found;
			}
		);
		$params = (new ReflectionMethod(ObjectService::class, 'saveObject'))->getParameters();
		$service->method('saveObject')->willReturnCallback(
			static function (...$args) use ($params, &$calls) {
				$named = [];
				foreach ($args as $i => $value) {
					if ($params[$i]->isDefaultValueAvailable() === true && $params[$i]->getDefaultValue() === $value) {
						continue;
					}

					$named[$params[$i]->getName()] = $value;
				}

				// An entity row's @self block follows the loaded OpenRegister version, so it is not pinned.
				unset($named['object']['@self']);
				$calls[] = ['saveObject' => $named];
				return OrEntityFactory::make($named['object']);
			}
		);

		try {
			(new LessonProgress($service, $this->createMock(LoggerInterface::class)))->record($statement, '2026-10-01T09:00:00+00:00');
		} catch (\TypeError $error) {
			$calls[] = ['threw' => get_class($error)];
		}

		return $calls;
	}//end callsFor()

	/**
	 * Whether a stored row passes a findAll config's ids, filters and limit-free match.
	 *
	 * @param array<string, mixed> $row    The stored row.
	 * @param array<string, mixed> $config The findAll config.
	 *
	 * @return bool
	 */
	private static function rowMatches(array $row, array $config): bool {
		if (isset($config['ids']) === true && in_array((string)($row['id'] ?? ($row['uuid'] ?? '')), $config['ids'], true) === false) {
			return false;
		}

		foreach (array_diff_key($config['filters'], ['register' => true, 'schema' => true]) as $key => $value) {
			if (($row[$key] ?? null) !== $value) {
				return false;
			}
		}

		return true;
	}//end rowMatches()
}//end class
