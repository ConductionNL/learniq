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
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $this->store->findAll($config, $_rbac, $_multitenancy)
		);
		// Read arguments by the real parameter names: the stub and
		// OpenRegister's own ObjectService order them differently.
		$names = array_map(static fn (\ReflectionParameter $parameter): string => $parameter->getName(), (new \ReflectionMethod(ObjectService::class, 'saveObject'))->getParameters());
		$objectService->method('saveObject')->willReturnCallback(
			function (mixed ...$args) use ($names): ObjectEntity {
				$named = array_combine(array_slice($names, 0, count($args)), $args);
				$this->saveRbac[] = (bool)($named['_rbac'] ?? true);
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
}//end class
