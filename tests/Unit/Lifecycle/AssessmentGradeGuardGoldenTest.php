<?php

/**
 * Golden tests for AssessmentGradeGuard and the tenant-scoped lookups it
 * shares with AssessmentScoringHandler.
 *
 * Pins every allowed and refused grade outcome, the exact log line per
 * refusal, and the exact Assessment and Item queries both classes send.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Lifecycle
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
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\Lifecycle\AssessmentGradeGuard;
use OCA\Learniq\Lifecycle\AssessmentScoringHandler;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * Golden tests for the grade guard and the scoring handler's lookups.
 */
class AssessmentGradeGuardGoldenTest extends TestCase {

	private const DENIAL = 'Every item that needs manual scoring must have a score before the result can be graded.';

	/**
	 * Every query and log line, in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	public array $trace = [];

	/**
	 * An object service answering from fixtures and recording each query.
	 *
	 * @param array<int,mixed>|null $assessment The Assessment row, null when unreachable.
	 * @param array<int,mixed>      $items      Item rows (arrays or entities).
	 *
	 * @return ObjectService
	 */
	private function objects(?array $assessment, array $items): ObjectService {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config) use ($assessment, $items): array {
				$this->trace[] = ['findAll', $config];
				if ($config['filters']['schema'] === 'exam') {
					return $assessment === null ? [] : [$assessment];
				}

				return $items;
			}
		);

		return $objects;
	}//end objects()

	/**
	 * A logger recording into the trace.
	 *
	 * @return LoggerInterface
	 */
	private function logger(): LoggerInterface {
		$test = $this;
		return new class($test) extends AbstractLogger {
			/**
			 * Constructor.
			 *
			 * @param AssessmentGradeGuardGoldenTest $test Recorder.
			 */
			public function __construct(private AssessmentGradeGuardGoldenTest $test) {
			}

			/**
			 * Record.
			 *
			 * @param mixed              $level   Level.
			 * @param string|\Stringable $message Message.
			 * @param array<mixed>       $context Context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->test->trace[] = ['log', (string)$level, (string)$message, $context];
			}
		};
	}//end logger()

	/**
	 * The assessment with an essay (manual), a choice item (auto) and an item without a key (manual).
	 *
	 * @return array<string,mixed>
	 */
	private static function assessment(): array {
		return ['itemRefs' => [['itemId' => 'essay'], ['itemId' => 'choice'], ['itemId' => 'nokey'], ['points' => 2], ['itemId' => 'missing']]];
	}//end assessment()

	/**
	 * Item rows for assessment(), one as an entity.
	 *
	 * @return array<int,mixed>
	 */
	private static function items(): array {
		$entity = new class implements \JsonSerializable {
			/**
			 * Serialised item.
			 *
			 * @return array<string,mixed>
			 */
			public function jsonSerialize(): array {
				return ['id' => 'nokey', 'interactionType' => 'choice'];
			}
		};

		return [
			['uuid' => 'essay', 'interactionType' => 'extendedText', 'correctResponse' => 'x'],
			['uuid' => 'choice', 'interactionType' => 'choice', 'correctResponse' => 'B'],
			$entity,
			['interactionType' => 'choice'],
		];
	}//end items()

	/**
	 * Grade an AssessmentResult.
	 *
	 * @param array<string,mixed> $result     The result.
	 * @param array<string,mixed>|null $assessment The Assessment.
	 *
	 * @return \OCA\OpenRegister\Lifecycle\GuardResult
	 */
	private function grade(array $result, ?array $assessment): \OCA\OpenRegister\Lifecycle\GuardResult {
		$guard = new AssessmentGradeGuard($this->objects($assessment, self::items()), $this->logger());
		return $guard->check($result, 'grade', 'teacher');
	}//end grade()

	/**
	 * Every manual item scored: allowed, with the exact tenant-scoped queries and no log line.
	 *
	 * @return void
	 */
	public function testEveryManualItemScoredIsAllowed(): void {
		$result = [
			'assessmentId' => 'exam-1',
			'tenant_id' => 't1',
			'responses' => [['itemId' => 'essay', 'manualScore' => 3], ['itemId' => 'nokey', 'autoScore' => 0], ['noItem' => true]],
		];

		self::assertTrue($this->grade($result, self::assessment())->isAllowed());
		self::assertSame(
			[
				['findAll', ['ids' => ['exam-1'], 'filters' => ['register' => 'learniq', 'schema' => 'exam', 'tenant_id' => 't1'], 'limit' => 1]],
				['findAll', ['ids' => ['essay', 'choice', 'nokey', 'missing'], 'filters' => ['register' => 'learniq', 'schema' => 'item', 'tenant_id' => 't1'], 'limit' => 5]],
			],
			$this->trace
		);
	}//end testEveryManualItemScoredIsAllowed()

	/**
	 * No item refs, or none with an item id: allowed without an item query; no tenant, no tenant filter.
	 *
	 * @return void
	 */
	public function testNothingToScoreIsAllowed(): void {
		self::assertTrue($this->grade(['assessmentId' => 'exam-1'], ['title' => 'x'])->isAllowed());
		self::assertTrue($this->grade(['assessmentId' => 'exam-1'], ['itemRefs' => [['points' => 1]]])->isAllowed());
		self::assertSame(
			[
				['findAll', ['ids' => ['exam-1'], 'filters' => ['register' => 'learniq', 'schema' => 'exam'], 'limit' => 1]],
				['findAll', ['ids' => ['exam-1'], 'filters' => ['register' => 'learniq', 'schema' => 'exam'], 'limit' => 1]],
			],
			$this->trace
		);
	}//end testNothingToScoreIsAllowed()

	/**
	 * Refusals: no assessmentId, an unreachable Assessment, and the first unscored manual item.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		$noId = $this->grade(['responses' => []], self::assessment());
		self::assertFalse($noId->isAllowed());
		self::assertSame(self::DENIAL, $noId->getMessage());
		self::assertSame([['log', 'info', '[AssessmentGradeGuard] AssessmentResult has no assessmentId; blocking grade.', []]], $this->trace);

		$this->trace = [];
		self::assertFalse($this->grade(['assessmentId' => 'exam-9', 'tenant_id' => 't1'], null)->isAllowed());
		self::assertSame(['log', 'info', '[AssessmentGradeGuard] Assessment {id} not found; blocking grade.', ['id' => 'exam-9']], $this->trace[1]);
		self::assertCount(2, $this->trace);

		$line = '[AssessmentGradeGuard] Item {itemId} (interactionType={type}) needs manual scoring but '
			. 'neither manualScore nor autoScore is set; blocking grade.';
		$this->trace = [];
		self::assertFalse($this->grade(['assessmentId' => 'exam-1', 'responses' => [['itemId' => 'nokey', 'autoScore' => 1]]], self::assessment())->isAllowed());
		self::assertSame(['log', 'info', $line, ['itemId' => 'essay', 'type' => 'extendedText']], $this->trace[2]);

		$this->trace = [];
		self::assertFalse($this->grade(['assessmentId' => 'exam-1', 'responses' => [['itemId' => 'essay', 'autoScore' => 1]]], self::assessment())->isAllowed());
		self::assertSame(['log', 'info', $line, ['itemId' => 'nokey', 'type' => 'choice']], $this->trace[2]);
	}//end testRefusals()

	/**
	 * The scoring handler's Assessment and per-item queries, tenant-scoped when the result names a tenant.
	 *
	 * @return void
	 */
	public function testScoringHandlerQueries(): void {
		$handler = new AssessmentScoringHandler($this->objects(['itemRefs' => [['itemId' => 'choice', 'points' => 2]]], []), $this->logger());
		$handler->score(['assessmentId' => 'exam-1', 'tenant_id' => 't1', 'responses' => [['itemId' => 'choice', 'response' => 'B']]]);
		$handler->score(['assessmentId' => 'exam-1', 'responses' => [['itemId' => 'choice', 'response' => 'B']]]);

		$queries = array_values(array_map(static fn (array $t): array => $t[1], array_filter($this->trace, static fn (array $t): bool => $t[0] === 'findAll')));
		self::assertSame(
			[
				['ids' => ['exam-1'], 'filters' => ['register' => 'learniq', 'schema' => 'exam', 'tenant_id' => 't1'], 'limit' => 1],
				['ids' => ['choice'], 'filters' => ['register' => 'learniq', 'schema' => 'item', 'tenant_id' => 't1'], 'limit' => 1],
				['ids' => ['exam-1'], 'filters' => ['register' => 'learniq', 'schema' => 'exam'], 'limit' => 1],
				['ids' => ['choice'], 'filters' => ['register' => 'learniq', 'schema' => 'item'], 'limit' => 1],
			],
			$queries
		);

		$this->trace = [];
		$missing = new AssessmentScoringHandler($this->objects(null, []), $this->logger());
		self::assertNull($missing->score(['assessmentId' => 'exam-9', 'responses' => [['itemId' => 'choice']]]));
		self::assertSame(
			['log', 'warning', '[AssessmentScoringHandler] Assessment {id} not found or out-of-tenant; blocking submit transition (fail-closed).', ['id' => 'exam-9']],
			$this->trace[1]
		);
	}//end testScoringHandlerQueries()
}//end class
