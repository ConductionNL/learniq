<?php

/**
 * Learniq SupportRequestSubmitHandler unit tests.
 *
 * Since data-exchange-to-integriq a submitted SupportRequest asks integriq for
 * an swv exchange job, stamps its id on the request, and opens a pending
 * DossierReview the exchange gate waits for. No DataExchangeJob is written.
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
 * @spec openspec/specs/data-exchange/spec.md#requirement-learniq-asks-integriq-to-carry-an-exchange
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\Exception\IntegriqUnavailableException;
use OCA\Learniq\Listener\SupportRequestSubmitHandler;
use OCA\Learniq\Service\IntegriqExchangeClient;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for SupportRequestSubmitHandler.
 */
class SupportRequestSubmitHandlerTest extends TestCase {

	/**
	 * Recorded saveObject() calls.
	 *
	 * @var array<int, array{schema: string, object: array<string, mixed>, uuid: mixed}>
	 */
	private array $saved = [];

	/**
	 * The integriq client double.
	 *
	 * @var IntegriqExchangeClient&MockObject
	 */
	private $integriq;

	/**
	 * Build the handler.
	 *
	 * @return SupportRequestSubmitHandler
	 */
	private function makeHandler(): SupportRequestSubmitHandler {
		$this->saved = [];
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend = [], $register = null, $schema = null, $uuid = null): ObjectEntity {
				$data = ($object instanceof ObjectEntity) ? $object->jsonSerialize() : $object;
				$this->saved[] = ['schema' => (string)$schema, 'object' => $data, 'uuid' => $uuid];
				return OrEntityFactory::make($data, (string)$schema);
			}
		);

		$this->integriq = $this->createMock(IntegriqExchangeClient::class);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('swv-kindkans');

		return new SupportRequestSubmitHandler($objectService, $this->integriq, $config, new NullLogger(), \OCA\Learniq\Tests\Support\TransitionScope::resolver());
	}//end makeHandler()

	/**
	 * A transition event for a support request.
	 *
	 * @param array<string, mixed> $request The request data.
	 * @param string               $to      The target state.
	 * @param string               $schema  The schema slug.
	 *
	 * @return ObjectTransitionedEvent
	 */
	private function event(array $request, string $to = 'submitted', string $schema = 'support-request'): ObjectTransitionedEvent {
		return new ObjectTransitionedEvent(OrEntityFactory::make($request, $schema), 'submit', 'draft', $to, 'coordinator-1', 'learniq', $schema);
	}//end event()

	/**
	 * A submitted request asks integriq for the swv job, stamps it, and opens the review.
	 *
	 * @return void
	 */
	public function testASubmittedRequestAsksIntegriqAndOpensTheParentReview(): void {
		$handler = $this->makeHandler();
		$this->integriq->expects($this->once())->method('requestJob')->with(
			'swv',
			'export',
			'support-request/sr-1',
			['schema' => 'support-request', 'recordIds' => ['sr-1'], 'tenantId' => 't1', 'receiverId' => 'swv-kindkans'],
			'learniq-swv-export-zorgvraag',
			'coordinator-1'
		)->willReturn('job-5');

		$handler->handle($this->event(['id' => 'sr-1', 'learnerId' => 'pupil-1', 'raisedBy' => 'coordinator-1', 'tenant_id' => 't1']));

		self::assertCount(2, $this->saved);
		self::assertSame('support-request', $this->saved[0]['schema']);
		self::assertSame('job-5', $this->saved[0]['object']['dataExchangeJobId']);
		self::assertSame('sr-1', $this->saved[0]['uuid']);
		self::assertSame('dossier-review', $this->saved[1]['schema']);
		self::assertSame(
			['exchangeJobId' => 'job-5', 'target' => 'swv', 'learnerUserId' => 'pupil-1', 'status' => 'pending', 'tenant_id' => 't1'],
			$this->saved[1]['object']
		);
	}//end testASubmittedRequestAsksIntegriqAndOpensTheParentReview()

	/**
	 * Integriq absent: no job id stored and no review opened, and nothing thrown.
	 *
	 * @return void
	 */
	public function testIntegriqIsAbsent(): void {
		$handler = $this->makeHandler();
		$this->integriq->method('requestJob')->willThrowException(new IntegriqUnavailableException('Integriq is not installed.'));

		$handler->handle($this->event(['id' => 'sr-1', 'learnerId' => 'pupil-1', 'tenant_id' => 't1']));

		self::assertSame([], $this->saved);
	}//end testIntegriqIsAbsent()

	/**
	 * Other states, other schemas, other events and a request without a learner are left alone.
	 *
	 * @return void
	 */
	public function testIgnoresEverythingElse(): void {
		$handler = $this->makeHandler();
		$this->integriq->expects($this->never())->method('requestJob');

		$handler->handle(new Event());
		$handler->handle($this->event(['id' => 'sr-1', 'learnerId' => 'pupil-1'], 'routed-to-swv'));
		$handler->handle($this->event(['id' => 'sr-1', 'learnerId' => 'pupil-1'], 'submitted', 'learning-plan'));
		$handler->handle($this->event(['id' => 'sr-1']));

		self::assertSame([], $this->saved);
	}//end testIgnoresEverythingElse()
}//end class
