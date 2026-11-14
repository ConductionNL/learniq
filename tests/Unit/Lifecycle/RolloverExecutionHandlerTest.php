<?php

/**
 * Learniq RolloverExecutionHandler unit tests.
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

use OCA\Learniq\Exception\RolloverInterruptedException;
use OCA\Learniq\Lifecycle\RolloverExecutionHandler;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Service\RolloverExecutionService;
use OCA\Learniq\Service\RolloverService;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef;
use OCA\OpenRegister\Service\Lifecycle\TransitionActor;
use OCA\OpenRegister\Service\Lifecycle\TransitionMove;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * What a failed rollover run writes onto its plan.
 *
 * @covers \OCA\Learniq\Lifecycle\RolloverExecutionHandler
 * @uses   \OCA\Learniq\Exception\RolloverInterruptedException
 */
class RolloverExecutionHandlerTest extends TestCase {

	/**
	 * Run the handler for a plan reaching `executing` whose execution throws, and return the saved plans.
	 *
	 * @param Throwable $failure What execute() throws.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function savedPlansWhenExecutionThrows(Throwable $failure): array {
		$saved   = [];
		$objects = $this->createMock(ObjectService::class);
		$objects->method('saveObject')->willReturnCallback(
			static function (array $object, ?array $extend = [], $register = null, $schema = null) use (&$saved) {
				$saved[] = $object;
				return OrEntityFactory::make($object, (string)$schema, (string)$register);
			}
		);

		$rollover = $this->createMock(RolloverService::class);
		$rollover->method('previewMatchesMappings')->willReturn(true);
		$execution = $this->createMock(RolloverExecutionService::class);
		$execution->method('execute')->willThrowException($failure);
		$schemas = $this->createMock(ListenerSchemaResolver::class);
		$schemas->method('eventRegister')->willReturn('learniq');
		$schemas->method('eventSchema')->willReturn('rollover-plan');

		$plan  = ['id' => 'plan-1', 'lifecycle' => 'executing', 'perMappingProgress' => ['c0' => 'done']];
		$event = new ObjectTransitionedEvent(object: OrEntityFactory::make($plan, 'rollover-plan'), move: new TransitionMove(action: 'execute', from: 'previewed', to: 'executing'), scope: new RegisterSchemaRef(register: 'learniq', schema: 'rollover-plan'), actor: new TransitionActor(userId: 'admin'));
		(new RolloverExecutionHandler($objects, $rollover, $execution, $this->createMock(LoggerInterface::class), $schemas))->handle($event);

		return $saved;
	}//end savedPlansWhenExecutionThrows()

	/**
	 * A run that stopped part-way fails the plan with the progress it had made.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/school-year-rollover/spec.md#scenario-resume-after-mid-run-failure
	 */
	public function testAnInterruptedRunRecordsItsProgressOnTheFailedPlan(): void {
		$saved = $this->savedPlansWhenExecutionThrows(
			new RolloverInterruptedException(progress: ['c0' => 'done', 'c1' => 'done'], cause: new RuntimeException('database gone'))
		);

		$this->assertCount(1, $saved);
		$this->assertSame('failed', $saved[0]['lifecycle']);
		$this->assertSame('database gone', $saved[0]['failureReason']);
		$this->assertSame(['c0' => 'done', 'c1' => 'done'], $saved[0]['perMappingProgress']);
	}//end testAnInterruptedRunRecordsItsProgressOnTheFailedPlan()

	/**
	 * Any other failure leaves the plan's progress as it was.
	 *
	 * @return void
	 */
	public function testAnyOtherFailureKeepsThePlansProgress(): void {
		$saved = $this->savedPlansWhenExecutionThrows(new RuntimeException('boom'));

		$this->assertCount(1, $saved);
		$this->assertSame('failed', $saved[0]['lifecycle']);
		$this->assertSame('boom', $saved[0]['failureReason']);
		$this->assertSame(['c0' => 'done'], $saved[0]['perMappingProgress']);
	}//end testAnyOtherFailureKeepsThePlansProgress()
}//end class
