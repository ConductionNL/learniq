<?php

/**
 * Learniq item analysis golden tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Listener
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
 * @spec openspec/specs/assessment/spec.md#requirement-per-item-statistics-are-computed-from-graded-results-gated-by-a-minimum-sample-size
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\Tests\Support\SaveCallShape;
use DateTime;
use DateTimeZone;
use OCA\Learniq\Listener\ItemAnalysisRecomputeHandler;
use OCA\Learniq\Service\ItemAnalysisService;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Tests\Support\TransitionScope;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins, per seeded data set, what ItemAnalysisService computes per item and
 * per assessment, and every read and write ItemAnalysisRecomputeHandler makes
 * on a graded transition, against tests/Unit/Listener/golden/ItemAnalysis.json.
 * With RECORD_GOLDEN=1 the file is written instead of compared.
 *
 * @covers \OCA\Learniq\Listener\ItemAnalysisRecomputeHandler
 * @covers \OCA\Learniq\Listener\PicksFields
 * @covers \OCA\Learniq\Service\ItemAnalysisService
 * @covers \OCA\Learniq\Service\ItemDistractorAnalysis
 * @covers \OCA\Learniq\Service\ParsesQtiBody
 * @uses   \OCA\Learniq\Service\ListenerSchemaResolver
 */
class ItemAnalysisGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/golden/ItemAnalysis.json';

	/**
	 * The fake register, per schema.
	 *
	 * @var array<string,list<array<string,mixed>>>
	 */
	private array $db = [];

	/**
	 * Every call, in order.
	 *
	 * @var list<array<string,mixed>>
	 */
	private array $calls = [];

	/**
	 * The generator state.
	 *
	 * @var integer
	 */
	private int $seed = 1;

	/**
	 * Data sets: seed, respondents, items, assessment config, extra options.
	 *
	 * @return array<string,array{0:int,1:int,2:int,3:array<string,mixed>|null,4:array<string,bool>}>
	 */
	public static function sets(): array {
		return [
			'empty assessment' => [1, 0, 2, null, []],
			'below every minimum' => [2, 6, 3, null, []],
			'items over the minimum, reliability under' => [3, 24, 3, null, []],
			'everything over the minimum' => [4, 40, 4, null, []],
			'low minimums, one item' => [5, 5, 1, ['minSampleSize' => 2, 'reliabilityMinSampleSize' => 2], []],
			'low minimums, strict thresholds, existing rows and flags' => [6, 12, 4, ['minSampleSize' => 3, 'reliabilityMinSampleSize' => 3, 'tooDifficultyBelow' => 0.6, 'tooEasyAbove' => 0.4, 'lowDiscriminationBelow' => 0.9], ['existing' => true]],
			'all the same score' => [7, 30, 3, ['minSampleSize' => 2, 'reliabilityMinSampleSize' => 2], ['flat' => true]],
			'two respondents' => [8, 2, 2, ['minSampleSize' => 1, 'reliabilityMinSampleSize' => 1], []],
			'four respondents' => [12, 4, 1, ['minSampleSize' => 1, 'reliabilityMinSampleSize' => 1], []],
			'five respondents' => [13, 5, 1, ['minSampleSize' => 1, 'reliabilityMinSampleSize' => 1], []],
			'one respondent' => [9, 1, 2, ['minSampleSize' => 1, 'reliabilityMinSampleSize' => 1], []],
			'config not an array' => [10, 8, 2, null, ['badConfig' => true]],
			'no tenant' => [11, 10, 3, ['minSampleSize' => 4, 'reliabilityMinSampleSize' => 4], ['noTenant' => true]],
		];
	}//end sets()

	/**
	 * The handler's calls and the service's results equal the recorded golden.
	 *
	 * @param int                      $seed    Generator seed.
	 * @param int                      $people  Respondents.
	 * @param int                      $items   Items.
	 * @param array<string,mixed>|null $config  The assessment's itemAnalysisConfig.
	 * @param array<string,bool>       $options Extra options.
	 *
	 * @return void
	 */
	#[DataProvider('sets')]
	public function testItemAnalysisMatchesGolden(int $seed, int $people, int $items, ?array $config, array $options): void {
		$this->seed  = $seed;
		$this->db    = [];
		$this->calls = [];
		$tenant      = 'tenant-a';
		if (($options['noTenant'] ?? false) === true) {
			$tenant = '';
		}

		$this->seedData(people: $people, items: $items, config: $config, options: $options + ['tenant' => $tenant]);

		$service = new ItemAnalysisService($this->objectService());
		$direct  = [];
		for ($i = 1; $i <= $items; $i++) {
			$direct['item-' . $i] = $service->computeItemStatistics('item-' . $i, 'assessment-1');
		}

		$direct['missing-item'] = $service->computeItemStatistics('item-none', 'assessment-1');
		$direct['reliability']  = $service->computeReliability('assessment-1');
		$direct['other assessment'] = $service->computeReliability('assessment-none');
		$this->calls = [];

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-03-04T05:06:07', new DateTimeZone('UTC')));
		$handler = new ItemAnalysisRecomputeHandler($this->objectService(), $service, $time, TransitionScope::resolver());
		$handler->handle($this->gradedEvent(data: ['id' => 'r-1', 'assessmentId' => 'assessment-1', 'tenant_id' => $tenant]));

		$actual = self::normalise(value: ['direct' => $direct, 'handler' => $this->calls]);
		$this->assertGolden(actual: $actual);
	}//end testItemAnalysisMatchesGolden()

	/**
	 * A transition that is not to graded, or has no assessment, does nothing.
	 *
	 * @return void
	 */
	public function testAResultWithoutAnAssessmentDoesNothing(): void {
		$this->db    = [];
		$this->calls = [];
		$service = new ItemAnalysisService($this->objectService());
		$time    = $this->createMock(ITimeFactory::class);
		$handler = new ItemAnalysisRecomputeHandler($this->objectService(), $service, $time, TransitionScope::resolver());
		$handler->handle($this->gradedEvent(data: ['id' => 'r-1']));
		$this->assertSame([], $this->calls);
	}//end testAResultWithoutAnAssessmentDoesNothing()

	/**
	 * The next number from the seeded generator, from 0 to $max - 1.
	 *
	 * @param int $max Upper bound (exclusive).
	 *
	 * @return int
	 */
	private function next(int $max): int {
		$this->seed = ((($this->seed * 1103515245) + 12345) % 2147483648);
		return (intdiv($this->seed, 65536) % $max);
	}//end next()

	/**
	 * Fill the fake register.
	 *
	 * @param int                      $people  Respondents.
	 * @param int                      $items   Items.
	 * @param array<string,mixed>|null $config  The assessment's itemAnalysisConfig.
	 * @param array<string,mixed>      $options Extra options.
	 *
	 * @return void
	 */
	private function seedData(int $people, int $items, ?array $config, array $options): void {
		$tenant     = $options['tenant'];
		$assessment = ['id' => 'assessment-1', 'tenant_id' => $tenant];
		if ($config !== null) {
			$assessment['itemAnalysisConfig'] = $config;
		}

		if (($options['badConfig'] ?? false) === true) {
			$assessment['itemAnalysisConfig'] = 'nope';
		}

		$this->db['exam'] = [$assessment, ['id' => 'assessment-2', 'tenant_id' => $tenant]];
		$valid  = '<assessmentItem><choiceInteraction><simpleChoice identifier="A"/><simpleChoice identifier="01"/><simpleChoice identifier="C"/><simpleChoice identifier=""/></choiceInteraction></assessmentItem>';
		$shapes = [
			['interactionType' => 'choice', 'correctResponse' => 'A', 'qtiBody' => $valid],
			['interactionType' => 'choice', 'correctResponse' => 'A', 'qtiBody' => '<broken'],
			['interactionType' => 'choice', 'qtiBody' => $valid],
			['interactionType' => 'text', 'correctResponse' => 'A', 'qtiBody' => $valid],
		];
		for ($i = 1; $i <= $items; $i++) {
			$this->db['item'][] = ['id' => 'item-' . $i, 'tenant_id' => $tenant, 'maxScore' => (1 + $this->next(3))] + $shapes[($i - 1) % 4];
		}

		for ($p = 0; $p < $people; $p++) {
			$this->db['assessment-result'][] = $this->gradedResult(person: $p, items: $items, options: $options);
		}

		$this->db['assessment-result'][] = ['id' => 'r-draft', 'assessmentId' => 'assessment-1', 'tenant_id' => $tenant, 'lifecycle' => 'submitted', 'responses' => [['itemId' => 'item-1', 'autoScore' => 1]]];
		if (($options['existing'] ?? false) === true) {
			$this->db['item-statistics'][] = ['id' => 'stat-old', 'itemId' => 'item-1', 'assessmentId' => 'assessment-1', 'note' => 'kept'];
			$this->db['assessment-reliability'][] = ['id' => 'rel-old', 'assessmentId' => 'assessment-1', 'note' => 'kept'];
			$this->db['item-revision-flag'][] = ['id' => 'flag-old', 'itemId' => 'item-2', 'reason' => 'too-difficult', 'lifecycle' => 'acknowledged'];
			$this->db['item-revision-flag'][] = ['id' => 'flag-closed', 'itemId' => 'item-1', 'reason' => 'too-easy', 'lifecycle' => 'resolved'];
		}
	}//end seedData()

	/**
	 * One graded result.
	 *
	 * @param int                 $person  The respondent.
	 * @param int                 $items   Items.
	 * @param array<string,mixed> $options Extra options.
	 *
	 * @return array<string,mixed>
	 */
	private function gradedResult(int $person, int $items, array $options): array {
		$responses = [];
		$drawn     = [];
		for ($i = 1; $i <= $items; $i++) {
			$roll = $this->next(10);
			if ($roll === 0 && $person > 2) {
				continue;
			}

			$score = (float)$this->next(3);
			if (($options['flat'] ?? false) === true) {
				$score = 1.0;
			}

			$response = ['itemId' => 'item-' . $i, 'response' => ['value' => ['A', 'B', '1', 'D', 7][$this->next(5)]], 'autoScore' => $score, 'manualScore' => null];
			if ($roll === 1) {
				$response['manualScore'] = 0.5;
			}

			if ($roll === 2 && $person > 2) {
				$response['autoScore'] = null;
			}

			$responses[] = $response;
			if ($this->next(2) === 0) {
				$drawn[] = ['itemId' => 'item-' . $i, 'points' => (2 + $this->next(2))];
			}
		}//end for

		return ['id' => 'r-' . $person, 'assessmentId' => 'assessment-1', 'tenant_id' => $options['tenant'], 'lifecycle' => 'graded', 'responses' => $responses, 'drawnItemRefs' => $drawn];
	}//end gradedResult()

	/**
	 * An ObjectService over the fake register that records every call.
	 *
	 * @return ObjectService
	 */
	private function objectService(): ObjectService {
		$service = $this->createMock(ObjectService::class);
		$service->method('findAll')->willReturnCallback(
			function (array $config): array {
				$this->calls[] = ['findAll' => $config];
				$filters = array_diff_key($config['filters'], ['register' => true, 'schema' => true]);
				$rows    = array_values(
					array_filter(
						$this->db[$config['filters']['schema']] ?? [],
						static function (array $row) use ($config, $filters): bool {
							if (isset($config['ids']) === true && in_array($row['id'] ?? null, $config['ids'], true) === false) {
								return false;
							}

							foreach ($filters as $key => $value) {
								if (($row[$key] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
				if (isset($config['limit']) === true) {
					$rows = array_slice($rows, 0, (int)$config['limit']);
				}

				return array_map(static fn (array $row): ObjectEntity => OrEntityFactory::make($row, (string)$config['filters']['schema']), $rows);
			}
		);
		$service->method('saveObject')->willReturnCallback(
			SaveCallShape::legacy(function (
				array|ObjectEntity $object,
				?array $extend = [],
				$register = null,
				$schema = null,
				$uuid = null,
				bool $_rbac = true
			): ObjectEntity {
				$this->calls[] = ['saveObject' => [$register, $schema, $object, $_rbac]];
				$object['id'] = $object['id'] ?? ($schema . '-' . count($this->calls));
				$this->db[(string)$schema][] = $object;
				return OrEntityFactory::make($object, (string)$schema);
			})
		);

		return $service;
	}//end objectService()

	/**
	 * A graded transition of an AssessmentResult.
	 *
	 * @param array<string,mixed> $data The result.
	 *
	 * @return ObjectTransitionedEvent
	 */
	private function gradedEvent(array $data): ObjectTransitionedEvent {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($data);
		$event = $this->createMock(ObjectTransitionedEvent::class);
		$event->method('getObject')->willReturn($entity);
		$event->method('getRegister')->willReturn('learniq');
		$event->method('getSchema')->willReturn('assessment-result');
		$event->method('getTo')->willReturn('graded');
		return $event;
	}//end gradedEvent()

	/**
	 * The value with every computedAt replaced, every `@self` entity metadata block replaced, and floats rounded to 12 digits.
	 *
	 * The `@self` block is what the loaded ObjectEntity class serialises, so it
	 * follows OpenRegister's field list rather than anything learniq decides.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	private static function normalise(mixed $value): mixed {
		if (is_float($value) === true) {
			return round($value, 12);
		}

		if (is_array($value) === false) {
			return $value;
		}

		foreach ($value as $key => $inner) {
			$value[$key] = self::normalise(value: $inner);
			if ($key === 'computedAt' && is_string($inner) === true) {
				$value[$key] = '<now>';
			}

			if ($key === '@self' && is_array($inner) === true) {
				$value[$key] = '<entity metadata>';
			}
		}

		return $value;
	}//end normalise()

	/**
	 * Compare with, or record, this data set's golden entry.
	 *
	 * @param mixed $actual What the data set produced.
	 *
	 * @return void
	 */
	private function assertGolden(mixed $actual): void {
		$name   = (string)$this->dataName();
		$golden = [];
		if (file_exists(self::GOLDEN) === true) {
			$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		}

		$actual = json_decode((string)json_encode($actual, JSON_PRESERVE_ZERO_FRACTION), true);
		if (getenv('RECORD_GOLDEN') === '1') {
			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::GOLDEN, json_encode($golden, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)) . "\n");
		}

		$this->assertArrayHasKey($name, $golden);
		$this->assertSame($golden[$name], $actual);
	}//end assertGolden()
}//end class
