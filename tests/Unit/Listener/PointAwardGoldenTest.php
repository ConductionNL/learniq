<?php

/**
 * Learniq PointAwardTriggerHandler characterization test.
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
 * @spec openspec/specs/engagement/spec.md#requirement-pointaward-creation-is-idempotent-and-immutable
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use DateTime;
use DateTimeZone;
use OCA\Learniq\Grading\GradeFormulaEvaluator;
use OCA\Learniq\Listener\PointAwardTriggerHandler;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Tests\Support\TransitionScope;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Throwable;

/**
 * Pins every ObjectService and evaluator call PointAwardTriggerHandler::handle()
 * makes, and how it ends, per event, against
 * tests/Unit/Listener/golden/PointAward.json. With RECORD_GOLDEN=1 the file is
 * written instead of compared.
 *
 * @covers \OCA\Learniq\Listener\PointAwardTriggerHandler
 * @covers \OCA\Learniq\Listener\PointAwardScope
 * @covers \OCA\Learniq\Listener\PointAwardSource
 * @uses   \OCA\Learniq\Service\ListenerSchemaResolver
 */
class PointAwardGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/golden/PointAward.json';

	/**
	 * The stored point rules, by kind, and the existing awards.
	 *
	 * @return array{rules: array<string, list<array<string, mixed>>>, awards: list<array<string, mixed>>}
	 */
	private static function store(): array {
		return [
			'rules' => [
				'enrolment-completed' => [
					['id' => 'pr-1', 'points' => 10],
					['uuid' => 'pr-2', 'points' => '2.5', 'scope' => ['cohortId' => 'c-1']],
					['id' => 'pr-3', 'points' => 4, 'scope' => ['courseId' => 'k-1']],
					['id' => 'pr-4', 'points' => 1, 'scope' => ['cohortId' => 'c-1', 'courseId' => 'k-2']],
					['points' => 7],
					['id' => 'pr-5', 'scope' => ['cohortId' => '', 'courseId' => 5]],
					['id' => 'pr-6', 'points' => 3, 'scope' => []],
				],
				'submission-on-time' => [
					['id' => 'pr-7', 'points' => 5],
					['id' => 'pr-8', 'points' => 1, 'scope' => ['cohortId' => 'c-1']],
				],
				'finalgrade-passed' => [
					['id' => 'pr-9', 'points' => 20, 'scope' => ['courseId' => 'k-1']],
					['id' => 'pr-10', 'points' => 2, 'scope' => ['cohortId' => 'c-7']],
				],
			],
			'awards' => [
				['learnerId' => 'ann', 'pointRuleId' => 'pr-1', 'sourceObjectId' => 'e-2'],
				['learnerId' => 'bob', 'pointRuleId' => 'pr-7', 'sourceObjectId' => 'sub-1'],
			],
		];
	}//end store()

	/**
	 * Each scenario: register, schema, target state, object data and the evaluator's pass.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: array<string, mixed>, 4?: bool}>
	 */
	private static function scenarios(): array {
		$enrol = ['id' => 'e-1', 'learnerId' => 'ann', 'tenant_id' => 't-1', 'cohortId' => 'c-1', 'courseId' => 'k-1'];
		return [
			'enrolment completed, scoped rules' => ['learniq', 'enrolment', 'completed', $enrol],
			'enrolment completed, other course' => ['learniq', 'enrolment', 'completed', array_merge($enrol, ['courseId' => 'k-2', 'tenant_id' => null])],
			'enrolment completed, no scope' => ['learniq', 'enrolment', 'completed', ['uuid' => 'e-2', 'learnerId' => 'ann']],
			'enrolment completed, already awarded' => ['learniq', 'enrolment', 'completed', array_merge($enrol, ['id' => 'e-2'])],
			'enrolment completed, no learner' => ['learniq', 'enrolment', 'completed', ['id' => 'e-3']],
			'enrolment completed, no id' => ['learniq', 'enrolment', 'completed', ['learnerId' => 'ann']],
			'enrolment completed, numeric id' => ['learniq', 'enrolment', 'completed', array_merge($enrol, ['id' => 42])],
			'enrolment completed, numeric learner' => ['learniq', 'enrolment', 'completed', array_merge($enrol, ['learnerId' => 7])],
			'enrolment completed, numeric tenant' => ['learniq', 'enrolment', 'completed', array_merge($enrol, ['tenant_id' => 3])],
			'enrolment completed, numeric cohort' => ['learniq', 'enrolment', 'completed', array_merge($enrol, ['cohortId' => 9])],
			'enrolment completed, numeric course' => ['learniq', 'enrolment', 'completed', array_merge($enrol, ['courseId' => 9])],
			'enrolment to active' => ['learniq', 'enrolment', 'active', $enrol],
			'other register' => ['elsewhere', 'enrolment', 'completed', $enrol],
			'submission on time' => ['learniq', 'submission', 'submitted', ['id' => 'sub-1', 'tenant_id' => 't-1', 'cohortId' => 'c-1', 'learnerIds' => ['ann', '', 7, 'bob']]],
			'submission late' => ['learniq', 'submission', 'submitted', ['id' => 'sub-2', 'isLate' => true, 'learnerIds' => ['ann']]],
			'submission isLate null' => ['learniq', 'submission', 'submitted', ['id' => 'sub-3', 'isLate' => null, 'learnerIds' => ['ann']]],
			'submission learners not a list' => ['learniq', 'submission', 'submitted', ['id' => 'sub-4', 'learnerIds' => 'ann']],
			'submission numeric id, no learners' => ['learniq', 'submission', 'submitted', ['id' => 5, 'learnerIds' => [7]]],
			'submission numeric id' => ['learniq', 'submission', 'submitted', ['id' => 5, 'learnerIds' => ['ann']]],
			'grade published, pass' => ['learniq', 'grade-entry', 'published', ['learnerId' => 'ann', 'curriculumPlanId' => 'cp-1', 'tenant_id' => 't-1', 'cohortId' => 'c-7', 'courseId' => 'k-1'], true],
			'grade published, fail' => ['learniq', 'grade-entry', 'published', ['learnerId' => 'ann', 'curriculumPlanId' => 'cp-1'], false],
			'grade published, no plan' => ['learniq', 'grade-entry', 'published', ['learnerId' => 'ann'], true],
		];
	}//end scenarios()

	/**
	 * Every scenario's calls and outcome equal the recorded golden.
	 *
	 * @return void
	 */
	public function testEveryScenarioMakesTheRecordedCalls(): void {
		$actual = [];
		foreach (self::scenarios() as $name => $scenario) {
			$actual[$name] = $this->callsFor($scenario);
		}

		$json = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)) . "\n";
		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, $json);
		}

		$this->assertSame(file_get_contents(self::GOLDEN), $json);
	}//end testEveryScenarioMakesTheRecordedCalls()

	/**
	 * Run handle() for one event and return the calls it made and how it ended.
	 *
	 * @param array{0: string, 1: string, 2: string, 3: array<string, mixed>, 4?: bool} $scenario The scenario.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function callsFor(array $scenario): array {
		$calls   = [];
		$store   = self::store();
		$service = $this->createMock(ObjectService::class);
		$service->method('findAll')->willReturnCallback(
			static function (...$args) use ($store, &$calls): array {
				$calls[]  = ['findAll' => self::named('findAll', $args)];
				$filters  = $args[0]['filters'];
				if ($filters['schema'] === 'point-rule') {
					$rules = $store['rules'][$filters['kind']] ?? [];
					return array_map(static fn (array $rule, int $i) => ($i % 2 === 0) ? $rule : OrEntityFactory::make($rule, 'point-rule'), $rules, array_keys($rules));
				}

				foreach ($store['awards'] as $award) {
					if ($award['learnerId'] === $filters['learnerId'] && $award['pointRuleId'] === $filters['pointRuleId'] && $award['sourceObjectId'] === $filters['sourceObjectId']) {
						return [OrEntityFactory::make($award, 'point-award')];
					}
				}

				return [];
			}
		);
		$service->method('saveObject')->willReturnCallback(
			static function (...$args) use (&$calls) {
				$named   = self::named('saveObject', $args);
				$calls[] = ['saveObject' => $named];
				return OrEntityFactory::make($named['object'], (string)$named['schema']);
			}
		);
		$evaluator = $this->createMock(GradeFormulaEvaluator::class);
		$evaluator->method('evaluate')->willReturnCallback(
			static function (...$args) use ($scenario, &$calls): array {
				$calls[] = ['evaluate' => $args];
				return ['passed' => ($scenario[4] ?? false)];
			}
		);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-03-04T05:06:07', new DateTimeZone('UTC')));

		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($scenario[3]);
		$event = $this->createMock(ObjectTransitionedEvent::class);
		$event->method('getObject')->willReturn($entity);
		$event->method('getRegister')->willReturn($scenario[0]);
		$event->method('getSchema')->willReturn($scenario[1]);
		$event->method('getTo')->willReturn($scenario[2]);

		try {
			(new PointAwardTriggerHandler($service, $evaluator, $time, TransitionScope::resolver()))->handle($event);
			$calls[] = ['returned' => true];
		} catch (Throwable $e) {
			$calls[] = ['threw' => get_class($e)];
		}

		return $calls;
	}//end callsFor()

	/**
	 * A mock call's positional arguments keyed by the loaded ObjectService's parameter names.
	 *
	 * @param string      $method The ObjectService method.
	 * @param list<mixed> $args   The positional arguments.
	 *
	 * @return array<string, mixed>
	 */
	private static function named(string $method, array $args): array {
		$params = (new ReflectionMethod(ObjectService::class, $method))->getParameters();
		$named  = [];
		foreach ($args as $i => $value) {
			if ($params[$i]->isDefaultValueAvailable() === true && $params[$i]->getDefaultValue() === $value) {
				continue;
			}

			$named[$params[$i]->getName()] = $value;
		}

		return $named;
	}//end named()
}//end class
