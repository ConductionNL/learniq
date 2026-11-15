<?php

/**
 * Learniq BackfillWerkprocesAssessmentLearner unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Repair
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
 * @spec openspec/specs/portable-learning-record/spec.md#requirement-learningrecordaggregationservice-composes-a-learner-s-trajectory-live-with-no-materialized-rollup
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Repair;

use OCA\Learniq\Listener\WerkprocesAssessmentLearnerStamp;
use OCA\Learniq\Repair\BackfillWerkprocesAssessmentLearner;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for BackfillWerkprocesAssessmentLearner::run().
 */
class BackfillWerkprocesAssessmentLearnerTest extends TestCase {

	/**
	 * The fake OpenRegister store.
	 *
	 * @var RegisterFaithfulStore
	 */
	private RegisterFaithfulStore $store;

	/**
	 * The `_rbac` flag of every save.
	 *
	 * @var array<int, bool>
	 */
	private array $saveRbac = [];

	/**
	 * When true, every save is refused the way OpenRegister throws.
	 *
	 * @var bool
	 */
	private bool $refuseSaves = false;

	/**
	 * When true, findAll answers plain arrays instead of entities.
	 *
	 * @var bool
	 */
	private bool $answerArrays = false;

	/**
	 * Info lines the step reported.
	 *
	 * @var array<int, string>
	 */
	private array $messages = [];

	/**
	 * Build the step over the fake store, with the real stamp's lookup.
	 *
	 * @return BackfillWerkprocesAssessmentLearner
	 */
	private function makeStep(): BackfillWerkprocesAssessmentLearner {
		$this->store = new RegisterFaithfulStore();
		$this->store->rows['bpv-placement'] = [
			['id' => 'bp-jan', 'learnerId' => 'jan'],
			['id' => 'bp-nobody'],
		];
		$this->store->rows['werkproces-assessment'] = [
			['id' => 'wa-1', 'bpvPlacementId' => 'bp-jan', 'lifecycle' => 'confirmed'],
			['id' => 'wa-2', 'bpvPlacementId' => 'bp-jan', 'lifecycle' => 'draft'],
			['id' => 'wa-3', 'bpvPlacementId' => 'bp-jan', 'learnerId' => 'jan', 'lifecycle' => 'confirmed'],
			['id' => 'wa-4', 'bpvPlacementId' => 'bp-nobody', 'lifecycle' => 'confirmed'],
		];
		$this->saveRbac = [];

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$found = $this->store->findAll($config, $_rbac, $_multitenancy);
				if ($this->answerArrays === false) {
					return $found;
				}

				return array_map(static fn (ObjectEntity $entity): array => $entity->jsonSerialize(), $found);
			}
		);
		// Read arguments by the real parameter names: the stub and
		// OpenRegister's own ObjectService order them differently.
		$names = array_map(static fn (\ReflectionParameter $parameter): string => $parameter->getName(), (new \ReflectionMethod(ObjectService::class, 'saveObject'))->getParameters());
		$objectService->method('saveObject')->willReturnCallback(
			function (mixed ...$args) use ($names): ObjectEntity {
				$named = array_combine(array_slice($names, 0, count($args)), $args);
				$this->saveRbac[] = (bool)($named['_rbac'] ?? true);
				if ($this->refuseSaves === true) {
					throw new \RuntimeException("Property 'learnerId' is not allowed.");
				}

				return $this->store->save((string)$named['schema'], (array)$named['object'], ($named['uuid'] ?? null), (bool)($named['_rbac'] ?? true));
			}
		);

		$stamp = new WerkprocesAssessmentLearnerStamp(
			schemaResolver: $this->createMock(ListenerSchemaResolver::class),
			objectService: $objectService,
			logger: new NullLogger(),
		);

		return new BackfillWerkprocesAssessmentLearner(objectService: $objectService, stamp: $stamp, logger: new NullLogger());
	}//end makeStep()

	/**
	 * The stored learnerId per assessment id.
	 *
	 * @return array<string, mixed>
	 */
	private function learners(): array {
		$learners = [];
		foreach ($this->store->rows['werkproces-assessment'] as $row) {
			$learners[$row['id']] = ($row['learnerId'] ?? null);
		}

		return $learners;
	}//end learners()

	/**
	 * Rows without a student get the placement's; the rest are left alone,
	 * and a second run saves nothing.
	 *
	 * @return void
	 */
	public function testStampsOnlyRowsWithoutAStudentAndIsIdempotent(): void {
		$step = $this->makeStep();
		$output = $this->createMock(IOutput::class);

		$step->run($output);

		self::assertSame(['wa-1' => 'jan', 'wa-2' => 'jan', 'wa-3' => 'jan', 'wa-4' => null], $this->learners());
		self::assertSame([false, false], $this->saveRbac, 'Two rows saved, both without a session.');
		self::assertSame('confirmed', $this->store->rows['werkproces-assessment'][0]['lifecycle']);

		$saves = count($this->store->saves);
		$step->run($output);
		self::assertCount($saves, $this->store->saves, 'A second run saves nothing new.');
	}//end testStampsOnlyRowsWithoutAStudentAndIsIdempotent()

	/**
	 * An output double that records info lines.
	 *
	 * @return IOutput
	 */
	private function recorder(): IOutput {
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			function (string $message): void {
				$this->messages[] = $message;
			}
		);
		return $output;
	}//end recorder()

	/**
	 * The step names what it does, for the upgrade output.
	 *
	 * @return void
	 */
	public function testTheStepNamesWhatItDoes(): void {
		self::assertStringContainsString('werkproces assessments', $this->makeStep()->getName());
	}//end testTheStepNamesWhatItDoes()

	/**
	 * Without a readable register the step stops quietly and writes nothing.
	 *
	 * @return void
	 */
	public function testAnUnreadableRegisterStopsQuietly(): void {
		$step = $this->makeStep();
		$this->store->failReads = 'register not imported';

		$step->run($this->recorder());

		self::assertSame([], $this->store->saves);
		self::assertSame(['BackfillWerkprocesAssessmentLearner: 0 stamped, 0 whose placement names no learner, 0 failed, of 0 scanned.'], $this->messages);
	}//end testAnUnreadableRegisterStopsQuietly()

	/**
	 * A refused save counts as failed and leaves the row unstamped; the run
	 * carries on with the next row.
	 *
	 * @return void
	 */
	public function testARefusedSaveCountsAsFailed(): void {
		$step = $this->makeStep();
		$this->refuseSaves = true;

		$step->run($this->recorder());

		self::assertSame(['wa-1' => null, 'wa-2' => null, 'wa-3' => 'jan', 'wa-4' => null], $this->learners());
		self::assertSame(['BackfillWerkprocesAssessmentLearner: 0 stamped, 1 whose placement names no learner, 2 failed, of 4 scanned.'], $this->messages);
	}//end testARefusedSaveCountsAsFailed()

	/**
	 * Rows read as plain arrays are stamped too; a row without a placement or
	 * without an id is skipped.
	 *
	 * @return void
	 */
	public function testArrayRowsAreReadAndIncompleteRowsSkipped(): void {
		$step = $this->makeStep();
		$this->answerArrays = true;
		$this->store->rows['werkproces-assessment'] = [
			['id' => 'wa-1', 'bpvPlacementId' => 'bp-jan', 'lifecycle' => 'confirmed'],
			['id' => 'wa-5', 'lifecycle' => 'confirmed'],
			['id' => 'wa-6', 'bpvPlacementId' => '', 'lifecycle' => 'confirmed'],
		];

		$step->run($this->recorder());

		self::assertSame(['wa-1' => 'jan', 'wa-5' => null, 'wa-6' => null], $this->learners());
		self::assertSame(['BackfillWerkprocesAssessmentLearner: 1 stamped, 0 whose placement names no learner, 0 failed, of 3 scanned.'], $this->messages);
	}//end testArrayRowsAreReadAndIncompleteRowsSkipped()
}//end class
