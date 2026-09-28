<?php

/**
 * Learniq SchoolAdviesSendToRodHandler unit tests.
 *
 * Since data-exchange-to-integriq sending a definitief advice to ROD asks
 * integriq for a bron-rod exchange job (berichtsoort schooladvies) and stamps
 * its id on the advice. No DataExchangeJob is written.
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

use OCA\Learniq\Exception\ExchangeRequestRefusedException;
use OCA\Learniq\Listener\SchoolAdviesSendToRodHandler;
use OCA\Learniq\Service\IntegriqExchangeClient;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for SchoolAdviesSendToRodHandler.
 */
class SchoolAdviesSendToRodHandlerTest extends TestCase {

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
	 * @return SchoolAdviesSendToRodHandler
	 */
	private function makeHandler(): SchoolAdviesSendToRodHandler {
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

		return new SchoolAdviesSendToRodHandler($objectService, $this->integriq, new NullLogger());
	}//end makeHandler()

	/**
	 * A school advice transition event.
	 *
	 * @param array<string, mixed> $advies The advice data.
	 * @param string               $to     The target state.
	 *
	 * @return ObjectTransitionedEvent
	 */
	private function event(array $advies, string $to = 'verzonden-naar-rod'): ObjectTransitionedEvent {
		return new ObjectTransitionedEvent(OrEntityFactory::make($advies, 'school-advies'), 'verzendenNaarRod', 'definitief', $to, 'teacher-1', 'learniq', 'school-advies');
	}//end event()

	/**
	 * Sending to ROD asks integriq for a bron-rod schooladvies job, naming the school advice mapping, and links it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/enrolment/spec.md#scenario-sending-a-definitief-advies-creates-and-links-a-bron-rod-dataexchangejob
	 * @spec openspec/changes/rod-bsn-and-school-advice/specs/data-exchange/spec.md#scenario-the-handler-names-the-mapping
	 */
	public function testSendToRodAsksIntegriqAndLinksTheJob(): void {
		$handler = $this->makeHandler();
		$this->integriq->expects($this->once())->method('requestJob')->with(
			'bron-rod',
			'export',
			'school-advies/sa-1',
			['schema' => 'school-advies', 'recordIds' => ['sa-1'], 'tenantId' => 't1', 'berichtsoort' => 'schooladvies'],
			'learniq-bron-rod-export-schooladvies'
		)->willReturn('job-3');

		$handler->handle($this->event(['id' => 'sa-1', 'learnerId' => 'pupil-1', 'tenant_id' => 't1']));

		self::assertCount(1, $this->saved);
		self::assertSame('school-advies', $this->saved[0]['schema']);
		self::assertSame('job-3', $this->saved[0]['object']['dataExchangeJobId']);
		self::assertSame('sa-1', $this->saved[0]['uuid']);
	}//end testSendToRodAsksIntegriqAndLinksTheJob()

	/**
	 * A refused request stamps nothing and throws nothing.
	 *
	 * @return void
	 */
	public function testARefusedRequestStampsNothing(): void {
		$handler = $this->makeHandler();
		$this->integriq->method('requestJob')->willThrowException(new ExchangeRequestRefusedException('store-failed', 'No.'));

		$handler->handle($this->event(['id' => 'sa-1', 'learnerId' => 'pupil-1']));

		self::assertSame([], $this->saved);
	}//end testARefusedRequestStampsNothing()

	/**
	 * Other states and an advice without a learner are left alone.
	 *
	 * @return void
	 */
	public function testIgnoresOtherStatesAndAnAdviceWithoutALearner(): void {
		$handler = $this->makeHandler();
		$this->integriq->expects($this->never())->method('requestJob');

		$handler->handle($this->event(['id' => 'sa-1', 'learnerId' => 'pupil-1'], 'definitief'));
		$handler->handle($this->event(['id' => 'sa-1']));

		self::assertSame([], $this->saved);
	}//end testIgnoresOtherStatesAndAnAdviceWithoutALearner()
}//end class
