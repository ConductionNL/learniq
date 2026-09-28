<?php

/**
 * Tests for IntegriqExchangeClient: learniq asks integriq through its typed
 * events, and fails closed without it.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Service
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
 * @spec openspec/changes/data-exchange-to-integriq/specs/data-exchange/spec.md#requirement-learniq-asks-integriq-to-carry-an-exchange
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Integriq\Event\ExchangeJobRequestedEvent;
use OCA\Integriq\Event\ExchangeMappingRequestedEvent;
use OCA\Learniq\Exception\ExchangeRequestRefusedException;
use OCA\Learniq\Exception\IntegriqUnavailableException;
use OCA\Learniq\Service\IntegriqExchangeClient;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;

/**
 * The client against integriq's real event classes (the contract copies in tests/Stubs/Integriq).
 */
class IntegriqExchangeClientTest extends TestCase {

	/**
	 * A client whose dispatcher runs the given integriq-side answer.
	 *
	 * @param callable|null $answer  Receives the real event, or null for nobody answering.
	 * @param bool          $enabled Whether integriq is enabled.
	 *
	 * @return IntegriqExchangeClient The client.
	 */
	private function client(?callable $answer, bool $enabled = true): IntegriqExchangeClient {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function ($event) use ($answer): void {
				if ($answer !== null) {
					$answer($event);
				}
			}
		);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn($enabled);

		return new IntegriqExchangeClient($dispatcher, $apps);
	}//end client()

	/**
	 * An attendance flag asks for a leerplicht report: the event carries learniq as owner.
	 *
	 * @return void
	 */
	public function testAnAttendanceFlagAsksForALeerplichtReport(): void {
		$seen = null;
		$client = $this->client(
			static function (ExchangeJobRequestedEvent $event) use (&$seen): void {
				$seen = $event;
				$event->setJobId('job-1');
			}
		);

		$jobId = $client->requestJob(
			target: 'leerplicht',
			direction: 'export',
			ownerRef: 'attendance-flag/flag-1',
			scope: ['schema' => 'attendance-flag', 'recordIds' => ['flag-1']],
			mappingSlug: 'learniq-leerplicht-export-melding',
			requestedBy: 'system'
		);

		$this->assertSame('job-1', $jobId);
		$this->assertInstanceOf(ExchangeJobRequestedEvent::class, $seen);
		$this->assertSame('learniq', $seen->getOwnerApp());
		$this->assertSame('leerplicht', $seen->getTarget());
		$this->assertSame('attendance-flag/flag-1', $seen->getOwnerRef());
		$this->assertSame('learniq-leerplicht-export-melding', $seen->getMappingSlug());
		$this->assertNull($seen->getHistory());
	}//end testAnAttendanceFlagAsksForALeerplichtReport()

	/**
	 * Integriq absent: nothing is dispatched and the request fails closed.
	 *
	 * @return void
	 */
	public function testIntegriqIsAbsent(): void {
		$client = $this->client(
			function (): void {
				$this->fail('Nothing may be dispatched without integriq.');
			},
			false
		);

		$this->assertFalse($client->isAvailable());
		$this->expectException(IntegriqUnavailableException::class);
		$client->requestJob('bron-rod', 'export', 'x/1', [], null, 'system');
	}//end testIntegriqIsAbsent()

	/**
	 * Integriq refuses: its code travels on the exception.
	 *
	 * @return void
	 */
	public function testIntegriqRefuses(): void {
		$client = $this->client(
			static function (ExchangeJobRequestedEvent $event): void {
				$event->refuse('target-unknown', 'Integriq knows no exchange target "fax".');
			}
		);

		try {
			$client->requestJob('fax', 'export', 'x/1', [], null, 'system');
			$this->fail('A refusal must throw.');
		} catch (ExchangeRequestRefusedException $exception) {
			$this->assertSame('target-unknown', $exception->getRefusalCode());
			$this->assertStringContainsString('fax', $exception->getMessage());
		}
	}//end testIntegriqRefuses()

	/**
	 * Nobody answers the request: fail closed, never an empty job id.
	 *
	 * @return void
	 */
	public function testNobodyAnswers(): void {
		$this->expectException(IntegriqUnavailableException::class);
		$this->client(null)->requestJob('oso', 'export', 'x/1', [], null, 'system');
	}//end testNobodyAnswers()

	/**
	 * A migrated job carries its history; a mapping request carries learniq's slug.
	 *
	 * @return void
	 */
	public function testHistoryAndMappingRequests(): void {
		$jobs = [];
		$mappings = [];
		$client = $this->client(
			static function ($event) use (&$jobs, &$mappings): void {
				if ($event instanceof ExchangeJobRequestedEvent) {
					$jobs[] = $event;
					$event->setJobId('job-9');
				}

				if ($event instanceof ExchangeMappingRequestedEvent) {
					$mappings[] = $event;
					$event->setMappingId('map-1');
				}
			}
		);

		$client->requestJob('bron-rod', 'export', 'data-exchange-job/l1', [], null, 'admin', 'Migrated', ['legacyId' => 'l1', 'status' => 'succeeded']);
		$this->assertSame('l1', $jobs[0]->getHistory()['legacyId']);

		$this->assertSame('map-1', $client->requestMapping('learniq-custom-hr', 'HR', 'Custom', ['achternaam' => 'familyName']));
		$this->assertSame('learniq', $mappings[0]->getOwnerApp());
		$this->assertSame(['achternaam' => 'familyName'], $mappings[0]->getMapping());
		$this->assertFalse($mappings[0]->isPassThrough());
	}//end testHistoryAndMappingRequests()
}//end class
