<?php

/**
 * Tests for the two integriq exchange listeners: the gate answer and the
 * conclusion projection. Both run against integriq's real event classes.
 *
 * @category Test
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
 * @spec openspec/changes/data-exchange-to-integriq/specs/data-exchange/spec.md#requirement-the-gate-enforces-partner-approval-teldatum-confirmation-and-flag-handling
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Integriq\Event\ExchangeGateRequestedEvent;
use OCA\Integriq\Event\ExchangeJobConcludedEvent;
use OCA\Learniq\Listener\ExchangeGateListener;
use OCA\Learniq\Listener\ExchangeJobConcludedListener;
use OCA\Learniq\Service\ExchangeGateService;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The listeners' contract with integriq.
 */
class ExchangeGateListenerTest extends TestCase {

	/**
	 * A gate listener whose service answers the given decision.
	 *
	 * @param array<string, mixed>|null $decision The decision, or null to throw.
	 * @param array<int, mixed>         $calls    Receives the evaluate() arguments.
	 *
	 * @return ExchangeGateListener The listener.
	 */
	private function gateListener(?array $decision, array &$calls = []): ExchangeGateListener {
		$gate = $this->createMock(ExchangeGateService::class);
		$gate->method('evaluate')->willReturnCallback(
			static function (...$args) use ($decision, &$calls): array {
				$calls[] = $args;
				if ($decision === null) {
					throw new RuntimeException('database gone');
				}

				return $decision;
			}
		);

		return new ExchangeGateListener($gate, new NullLogger());
	}//end gateListener()

	/**
	 * The gate event for a learniq job.
	 *
	 * @param string $owner The owning app.
	 *
	 * @return ExchangeGateRequestedEvent The event.
	 */
	private function gateEvent(string $owner = 'learniq'): ExchangeGateRequestedEvent {
		return new ExchangeGateRequestedEvent('job-1', $owner, 'bron-rod', 'export', 'user/admin', ['schema' => 'learner-profile']);
	}//end gateEvent()

	/**
	 * An allow hands integriq the records.
	 *
	 * @return void
	 */
	public function testAllowHandsOverTheRecords(): void {
		$calls = [];
		$event = $this->gateEvent();
		$records = [['recordId' => 'lp-1', 'sourceKind' => 'learner-profile', 'data' => ['eckId' => 'eck-1']]];

		$this->gateListener(['decision' => 'allow', 'code' => '', 'reason' => '', 'records' => $records], $calls)->handle($event);

		$this->assertTrue($event->isAllowed());
		$this->assertSame($records, $event->getRecords());
		$this->assertSame(['job-1', 'bron-rod', 'export', 'user/admin', ['schema' => 'learner-profile']], array_slice($calls[0], 0, 5));
	}//end testAllowHandsOverTheRecords()

	/**
	 * A refusal carries learniq's code and reason.
	 *
	 * @return void
	 */
	public function testARefusalCarriesTheCode(): void {
		$event = $this->gateEvent();

		$this->gateListener(['decision' => 'refuse', 'code' => 'teldatum-unconfirmed', 'reason' => 'Not yet.', 'records' => []])->handle($event);

		$this->assertTrue($event->isAnswered());
		$this->assertFalse($event->isAllowed());
		$this->assertSame(['code' => 'teldatum-unconfirmed', 'reason' => 'Not yet.'], $event->getRefusal());
	}//end testARefusalCarriesTheCode()

	/**
	 * A failing gate is a refusal, never an exception into integriq's runner.
	 *
	 * @return void
	 */
	public function testAFailingGateRefuses(): void {
		$event = $this->gateEvent();

		$this->gateListener(null)->handle($event);

		$this->assertSame('gate-error', $event->getRefusal()['code']);
	}//end testAFailingGateRefuses()

	/**
	 * Another app's job and another event are left unanswered.
	 *
	 * @return void
	 */
	public function testAnotherAppsJobIsLeftAlone(): void {
		$calls = [];
		$listener = $this->gateListener(['decision' => 'allow', 'records' => []], $calls);
		$foreign = $this->gateEvent('dossiq');

		$listener->handle($foreign);
		$listener->handle(new Event());

		$this->assertFalse($foreign->isAnswered());
		$this->assertSame([], $calls);
	}//end testAnotherAppsJobIsLeftAlone()

	/**
	 * A succeeded SWV hand-off routes its submitted support request, once.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/data-exchange-to-integriq/specs/data-exchange/spec.md#requirement-a-succeeded-swv-exchange-routes-its-support-request
	 */
	public function testTheSwvHandOffSucceeded(): void {
		$state = 'submitted';
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			static function ($id) use (&$state) {
				return OrEntityFactory::make(['id' => $id, 'lifecycle' => $state], 'support-request');
			}
		);
		$engine = $this->createMock(TransitionEngine::class);
		$engine->expects($this->once())->method('transition')->with('sr-1', 'routeToSwv')->willReturnCallback(
			static function () use (&$state) {
				$state = 'routed-to-swv';
				return null;
			}
		);
		$listener = new ExchangeJobConcludedListener($objects, $engine, new NullLogger());

		$event = new ExchangeJobConcludedEvent('learniq', 'job-1', 'swv', 'export', 'support-request/sr-1', 'succeeded');
		$listener->handle($event);
		$listener->handle($event);
	}//end testTheSwvHandOffSucceeded()

	/**
	 * Any other outcome, target or owner changes nothing.
	 *
	 * @return void
	 */
	public function testOtherOutcomesChangeNothing(): void {
		$engine = $this->createMock(TransitionEngine::class);
		$engine->expects($this->never())->method('transition');
		$listener = new ExchangeJobConcludedListener($this->createMock(ObjectService::class), $engine, new NullLogger());

		$listener->handle(new ExchangeJobConcludedEvent('learniq', 'job-1', 'swv', 'export', 'support-request/sr-1', 'refused'));
		$listener->handle(new ExchangeJobConcludedEvent('learniq', 'job-1', 'bron-rod', 'export', 'support-request/sr-1', 'succeeded'));
		$listener->handle(new ExchangeJobConcludedEvent('dossiq', 'job-1', 'swv', 'export', 'support-request/sr-1', 'succeeded'));
		$listener->handle(new ExchangeJobConcludedEvent('learniq', 'job-1', 'swv', 'export', 'learner-profile/x', 'succeeded'));
	}//end testOtherOutcomesChangeNothing()
}//end class
