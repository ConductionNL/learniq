<?php

/**
 * Learniq CourseQualityScoreRollupHandler golden tests.
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
 * @spec openspec/specs/course-evaluation/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\Tests\Support\PassedArguments;
use OCA\Learniq\Listener\CourseQualityScoreRollupHandler;
use OCA\Learniq\Service\CourseQualityScoreEvaluator;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Tests\Support\TransitionScope;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins every evaluate, read and write of handle() per scenario against
 * tests/Unit/Listener/golden/CourseQualityScoreRollup.json. With
 * RECORD_GOLDEN=1 the file is written instead of compared.
 *
 * @covers \OCA\Learniq\Listener\CourseQualityScoreRollupHandler
 * @covers \OCA\Learniq\Listener\PicksFields
 * @uses   \OCA\Learniq\Service\ListenerSchemaResolver
 */
class CourseQualityScoreRollupGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/golden/CourseQualityScoreRollup.json';

	/**
	 * Every call, in order.
	 *
	 * @var list<array<string,mixed>>
	 */
	private array $calls = [];

	/**
	 * Scenarios: the response, the transition target, the stored score row (null: none, 'entity': as an entity).
	 *
	 * @return array<string,array{0:array<string,mixed>,1:string,2:array<string,mixed>|null,3:bool}>
	 */
	public static function scenarios(): array {
		$response = ['id' => 'resp-1', 'courseId' => 'c-1', 'academicYear' => '2026-2027', 'period' => 'P1', 'teacherId' => 't-9', 'tenant_id' => 'ten-1'];
		$stored = ['id' => 'score-1', 'courseId' => 'c-1', 'note' => 'kept', 'responseCount' => 1];
		return [
			'first response, teacher scoped' => [$response, 'submitted', null, false],
			'existing row as an array' => [$response, 'submitted', $stored, false],
			'existing row as an entity' => [$response, 'submitted', $stored, true],
			'course level, empty teacher' => [['teacherId' => ''] + $response, 'submitted', null, false],
			'course level, no teacher, no tenant' => [array_diff_key($response, ['teacherId' => 1, 'tenant_id' => 1]), 'submitted', null, false],
			'no course' => [['courseId' => ''] + $response, 'submitted', null, false],
			'no year' => [array_diff_key($response, ['academicYear' => 1]), 'submitted', null, false],
			'no period' => [['period' => ''] + $response, 'submitted', null, false],
			'not submitted' => [$response, 'draft', null, false],
		];
	}//end scenarios()

	/**
	 * handle() matches the recorded scenario.
	 *
	 * @param array<string,mixed>      $response The response.
	 * @param string                   $to       The transition target.
	 * @param array<string,mixed>|null $stored   The stored score row.
	 * @param bool                     $asEntity Whether findAll returns it as an entity.
	 *
	 * @return void
	 */
	#[DataProvider('scenarios')]
	public function testHandleMatchesGolden(array $response, string $to, ?array $stored, bool $asEntity): void {
		$this->calls = [];
		$handler = new CourseQualityScoreRollupHandler($this->objectService(stored: $stored, asEntity: $asEntity), $this->evaluator(), TransitionScope::resolver());
		$handler->handle($this->transitioned(data: $response, to: $to));
		$handler->handle(new Event());

		$this->assertGolden(actual: json_decode((string)json_encode($this->calls, JSON_PRESERVE_ZERO_FRACTION), true));
	}//end testHandleMatchesGolden()

	/**
	 * An evaluator double that records its calls.
	 *
	 * @return CourseQualityScoreEvaluator
	 */
	private function evaluator(): CourseQualityScoreEvaluator {
		$evaluator = $this->createMock(CourseQualityScoreEvaluator::class);
		$evaluator->method('evaluate')->willReturnCallback(
			function (string $courseId, ?string $teacherId, string $academicYear, string $period): array {
				$this->calls[] = ['evaluate' => [$courseId, $teacherId, $academicYear, $period]];
				return ['responseCount' => 3, 'invitationCount' => 4, 'averageOverallScore' => 7.5, 'responseRate' => 0.75, 'lastRecomputedAt' => '2026-01-02T03:04:05+00:00', 'extra' => 'ignored'];
			}
		);
		return $evaluator;
	}//end evaluator()

	/**
	 * An ObjectService that records every call.
	 *
	 * @param array<string,mixed>|null $stored   The stored score row.
	 * @param bool                     $asEntity Whether to return it as an entity.
	 *
	 * @return ObjectService
	 */
	private function objectService(?array $stored, bool $asEntity): ObjectService {
		$service = $this->createMock(ObjectService::class);
		$service->method('findAll')->willReturnCallback(
			function (array $config) use ($stored, $asEntity): array {
				$this->calls[] = ['findAll' => $config];
				if ($stored === null) {
					return [];
				}

				if ($asEntity === true) {
					return [OrEntityFactory::make($stored, 'course-quality-score')];
				}

				return [$stored];
			}
		);
		$service->method('saveObject')->willReturnCallback(
			function (...$args): ObjectEntity {
				$this->calls[] = ['saveObject' => PassedArguments::of(method: [ObjectService::class, 'saveObject'], args: $args)];
				return OrEntityFactory::make([], 'course-quality-score');
			}
		);
		return $service;
	}//end objectService()

	/**
	 * A transition of a CourseEvaluationResponse.
	 *
	 * @param array<string,mixed> $data The response.
	 * @param string              $to   The transition target.
	 *
	 * @return ObjectTransitionedEvent
	 */
	private function transitioned(array $data, string $to): ObjectTransitionedEvent {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($data);
		$event = $this->createMock(ObjectTransitionedEvent::class);
		$event->method('getObject')->willReturn($entity);
		$event->method('getRegister')->willReturn('learniq');
		$event->method('getSchema')->willReturn('course-evaluation-response');
		$event->method('getTo')->willReturn($to);
		return $event;
	}//end transitioned()

	/**
	 * Compare with, or record, this scenario's golden entry.
	 *
	 * @param mixed $actual What the scenario did.
	 *
	 * @return void
	 */
	private function assertGolden(mixed $actual): void {
		$actual = self::withoutEntityMetadata(value: $actual);
		$name   = (string)$this->dataName();
		$golden = [];
		if (file_exists(self::GOLDEN) === true) {
			$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		}

		if (getenv('RECORD_GOLDEN') === '1') {
			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::GOLDEN, json_encode($golden, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)) . "\n");
		}

		$this->assertArrayHasKey($name, $golden);
		$this->assertSame($golden[$name], $actual);
	}//end assertGolden()

	/**
	 * The value with every `@self` block replaced by a marker.
	 *
	 * The `@self` block is what the loaded ObjectEntity class serialises, so it
	 * follows OpenRegister's field list rather than anything learniq decides.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	private static function withoutEntityMetadata(mixed $value): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		foreach ($value as $key => $inner) {
			$value[$key] = self::withoutEntityMetadata(value: $inner);
			if ($key === '@self' && is_array($inner) === true) {
				$value[$key] = '<entity metadata>';
			}
		}

		return $value;
	}//end withoutEntityMetadata()
}//end class
