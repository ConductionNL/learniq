<?php

/**
 * Unit tests for BpvLeerbedrijfVerificationHandler's guards: events it skips,
 * placements it cannot verify and a provider call that fails.
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
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\Bpv\ProvidesLeerbedrijfVerification;
use OCA\Learniq\Listener\BpvLeerbedrijfVerificationHandler;
use OCA\Learniq\Tests\Support\TransitionScope;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Learniq\Listener\BpvLeerbedrijfVerificationHandler
 * @uses   \OCA\Learniq\Service\ListenerSchemaResolver
 * @uses   \OCA\Learniq\Service\ListenerSlugContract
 */
class BpvLeerbedrijfVerificationGuardsTest extends TestCase {

	/** @var ObjectService&MockObject */
	private ObjectService $objectService;

	/** @var ContainerInterface&MockObject */
	private ContainerInterface $container;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	/** @var ProvidesLeerbedrijfVerification&MockObject */
	private ProvidesLeerbedrijfVerification $provider;

	private BpvLeerbedrijfVerificationHandler $handler;

	/**
	 * Build the handler; the container resolves `Provider` to a mocked provider.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectService::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->provider = $this->createMock(ProvidesLeerbedrijfVerification::class);
		$this->container->method('get')->with('Provider')->willReturn($this->provider);
		$this->handler = new BpvLeerbedrijfVerificationHandler($this->objectService, $this->container, $this->logger, TransitionScope::resolver());
	}//end setUp()

	/**
	 * A placement transition event.
	 *
	 * @param array<string, mixed> $placement The placement data.
	 * @param string               $to        The target state.
	 * @param string               $schema    The schema slug.
	 * @param string               $register  The register slug.
	 *
	 * @return ObjectTransitionedEvent
	 */
	private function event(
		array $placement,
		string $to = 'sbb-verification-pending',
		string $schema = 'bpv-placement',
		string $register = 'learniq'
	): ObjectTransitionedEvent {
		$object = $this->createMock(ObjectEntity::class);
		$object->method('jsonSerialize')->willReturn($placement);
		$event = $this->createMock(ObjectTransitionedEvent::class);
		$event->method('getRegister')->willReturn($register);
		$event->method('getSchema')->willReturn($schema);
		$event->method('getTo')->willReturn($to);
		$event->method('getObject')->willReturn($object);
		return $event;
	}//end event()

	/**
	 * A plain event, another register, another schema or another state is skipped.
	 *
	 * @return void
	 */
	public function testOtherEventsAreSkipped(): void {
		$this->provider->expects(self::never())->method('verify');
		$this->logger->expects(self::never())->method(self::anything());

		$placement = ['id' => 'p-1', 'trainingCompanyKvkNumber' => '123', 'trainingCompanyVerification' => ['provider' => 'Provider']];
		$this->handler->handle(new Event());
		$this->handler->handle($this->event($placement, register: 'shillinq'));
		$this->handler->handle($this->event($placement, schema: 'enrolment'));
		$this->handler->handle($this->event($placement, to: 'confirmed'));
	}//end testOtherEventsAreSkipped()

	/**
	 * A placement without an id is not verified and logs an error.
	 *
	 * @return void
	 */
	public function testAPlacementWithoutAnIdIsNotVerified(): void {
		$this->provider->expects(self::never())->method('verify');
		$this->logger->expects(self::once())->method('error')->with(self::stringContains('has no id'));

		$this->handler->handle($this->event(['trainingCompanyKvkNumber' => '123', 'trainingCompanyVerification' => ['provider' => 'Provider']]));
	}//end testAPlacementWithoutAnIdIsNotVerified()

	/**
	 * A non-array verification block means no provider is configured.
	 *
	 * @return void
	 */
	public function testANonArrayVerificationMeansNoProvider(): void {
		$this->container->expects(self::never())->method('get');
		$this->logger->expects(self::once())->method('info')->with(self::stringContains('No leerbedrijfVerification.provider'), ['id' => 'p-1']);

		$this->handler->handle($this->event(['uuid' => 'p-1', 'trainingCompanyKvkNumber' => '123', 'trainingCompanyVerification' => 'Provider']));
	}//end testANonArrayVerificationMeansNoProvider()

	/**
	 * A placement without a KvK number is not verified and logs a warning.
	 *
	 * @return void
	 */
	public function testAPlacementWithoutAKvkNumberIsNotVerified(): void {
		$this->provider->expects(self::never())->method('verify');
		$this->objectService->expects(self::never())->method('saveObject');
		$this->logger->expects(self::once())->method('warning')->with(self::stringContains('has no leerbedrijfKvkNumber'), ['id' => 'p-1']);

		$this->handler->handle($this->event(['id' => 'p-1', 'trainingCompanyVerification' => ['provider' => 'Provider']]));
	}//end testAPlacementWithoutAKvkNumberIsNotVerified()

	/**
	 * A provider that throws leaves the placement unchanged and logs the error.
	 *
	 * @return void
	 */
	public function testAFailingProviderLeavesThePlacementUnchanged(): void {
		$this->provider->expects(self::once())->method('verify')->with('123')->willThrowException(new RuntimeException('SBB unreachable'));
		$this->objectService->expects(self::never())->method('saveObject');
		$this->logger->expects(self::once())->method('error')->with(
			self::stringContains('Provider call failed'),
			['id' => 'p-1', 'msg' => 'SBB unreachable']
		);

		$this->handler->handle($this->event(['id' => 'p-1', 'trainingCompanyKvkNumber' => '123', 'trainingCompanyVerification' => ['provider' => 'Provider']]));
	}//end testAFailingProviderLeavesThePlacementUnchanged()
}//end class
