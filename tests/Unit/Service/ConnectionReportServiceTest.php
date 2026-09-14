<?php

/**
 * ConnectionReportService unit tests.
 *
 * The service records what a wallet offer met and sends it to integriq's
 * connection registry. Every test guards one way it could quietly stop telling
 * the truth: rewriting config on every call, reporting a connection nobody
 * declared, turning a listener's failure into a failed job, or logging a fault
 * when integriq is simply not installed.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/adopt-connection-registry/specs/integrations/spec.md#requirement-req-int-conn-002-learniq-reports-what-the-last-wallet-offer-met
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use DateTime;
use DateTimeZone;
use OCA\Integriq\Event\ConnectionStatusReportedEvent;
use OCA\Learniq\Service\ConnectionReportService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Unit tests for ConnectionReportService.
 *
 * @covers \OCA\Learniq\Service\ConnectionReportService
 */
class ConnectionReportServiceTest extends TestCase {

	/**
	 * The app-config key the wallet observation lives under.
	 *
	 * @var string
	 */
	private const WALLET_CONFIG_KEY = 'connection_observation_eudi-wallet';

	/**
	 * The stored app config, key to value.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * How often app config was written.
	 *
	 * @var int
	 */
	private int $writes = 0;

	/**
	 * Every event handed to the dispatcher.
	 *
	 * @var array<int, Event>
	 */
	private array $sent = [];

	/**
	 * Mocked event dispatcher.
	 *
	 * @var IEventDispatcher&MockObject
	 */
	private IEventDispatcher&MockObject $dispatcher;

	/**
	 * Mocked logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The app config double, backed by $this->config.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Set up the fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->config = [];
		$this->writes = 0;
		$this->sent = [];

		$this->appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = '', bool $lazy = false): string => ($this->config[$app . '.' . $key] ?? $default)
		);
		$this->appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value, bool $lazy = false): bool {
				$this->assertTrue(condition: $lazy, message: 'an observation is a lazy key, never loaded per request');
				$this->config[$app . '.' . $key] = $value;
				$this->writes++;
				return true;
			}
		);

		$this->dispatcher = $this->createMock(originalClassName: IEventDispatcher::class);
		$this->dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event): void {
				$this->sent[] = $event;
			}
		);

		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
	}//end setUp()

	/**
	 * The service as production builds it, at a fixed clock.
	 *
	 * @param IEventDispatcher|null $dispatcher Another dispatcher, or the recording one.
	 *
	 * @return ConnectionReportService
	 */
	private function service(?IEventDispatcher $dispatcher = null): ConnectionReportService {
		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-09-14 10:30:00', new DateTimeZone('UTC')));

		return new ConnectionReportService(
			appConfig: $this->appConfig,
			eventDispatcher: ($dispatcher ?? $this->dispatcher),
			timeFactory: $time,
			logger: $this->logger,
		);
	}//end service()

	/**
	 * A recorded observation is sent with the app, the key, the status and since when.
	 *
	 * @return void
	 */
	public function testARecordedObservationIsReported(): void {
		$service = $this->service();
		$service->observe(key: 'eudi-wallet', status: 'error', reason: 'Integriq refused the wallet offer: 401.');
		$service->reportObservations();

		$this->assertCount(expectedCount: 1, haystack: $this->sent);
		$event = $this->sent[0];
		$this->assertInstanceOf(expected: ConnectionStatusReportedEvent::class, actual: $event);
		$this->assertSame(expected: 'learniq', actual: $event->app);
		$this->assertSame(expected: 'eudi-wallet', actual: $event->key);
		$this->assertSame(expected: 'error', actual: $event->status);
		$this->assertSame(
			expected: 'Integriq refused the wallet offer: 401. Unchanged since 2026-09-14 10:30 UTC.',
			actual: $event->message
		);
	}//end testARecordedObservationIsReported()

	/**
	 * Nothing recorded means nothing sent.
	 *
	 * @return void
	 */
	public function testNothingRecordedSendsNothing(): void {
		$this->service()->reportObservations();

		$this->assertSame(expected: [], actual: $this->sent);
	}//end testNothingRecordedSendsNothing()

	/**
	 * The same outcome again writes no config, so a run of offers stays cheap.
	 *
	 * @return void
	 */
	public function testTheSameOutcomeAgainWritesNothing(): void {
		$service = $this->service();
		for ($i = 0; $i < 25; $i++) {
			$service->observe(key: 'eudi-wallet', status: 'configured', reason: 'The last wallet offer reached integriq.');
		}

		$this->assertSame(expected: 1, actual: $this->writes);
	}//end testTheSameOutcomeAgainWritesNothing()

	/**
	 * A changed outcome replaces the stored one, and the report follows it.
	 *
	 * @return void
	 */
	public function testAChangedOutcomeReplacesTheStoredOne(): void {
		$service = $this->service();
		$service->observe(key: 'eudi-wallet', status: 'error', reason: 'Integriq refused the wallet offer: 401.');
		$service->observe(key: 'eudi-wallet', status: 'configured', reason: 'The last wallet offer reached integriq.');
		$service->reportObservations();

		$this->assertSame(expected: 2, actual: $this->writes);
		$this->assertSame(expected: ['configured'], actual: array_map(static fn ($event): string => $event->status, $this->sent));
	}//end testAChangedOutcomeReplacesTheStoredOne()

	/**
	 * Every run sends again, so a report integriq refused before its sync lands later.
	 *
	 * @return void
	 */
	public function testEveryRunSendsTheObservationAgain(): void {
		$service = $this->service();
		$service->observe(key: 'eudi-wallet', status: 'configured', reason: 'The last wallet offer reached integriq.');
		$service->reportObservations();
		$service->reportObservations();

		$this->assertCount(expectedCount: 2, haystack: $this->sent);
	}//end testEveryRunSendsTheObservationAgain()

	/**
	 * An undeclared key or a status learniq cannot observe is refused, not stored.
	 *
	 * @return void
	 */
	public function testAnUnknownKeyOrStatusIsRefused(): void {
		$this->logger->expects($this->exactly(count: 2))->method('warning')
			->with($this->stringContains(string: 'refused'), $this->arrayHasKey(key: 'key'));

		$service = $this->service();
		$service->observe(key: 'payment', status: 'error', reason: 'Not declared as reported.');
		$service->observe(key: 'eudi-wallet', status: 'simulated', reason: 'Not something learniq observes.');

		$this->assertSame(expected: 0, actual: $this->writes);
	}//end testAnUnknownKeyOrStatusIsRefused()

	/**
	 * A long reason is cut, so an exception text cannot flood the row.
	 *
	 * @return void
	 */
	public function testALongReasonIsCut(): void {
		$service = $this->service();
		$service->observe(key: 'eudi-wallet', status: 'error', reason: str_repeat('x', 1000));

		$stored = json_decode($this->config['learniq.' . self::WALLET_CONFIG_KEY], true);
		$this->assertSame(expected: 300, actual: mb_strlen($stored['reason']));
	}//end testALongReasonIsCut()

	/**
	 * A stored value that is not an observation is ignored, not sent.
	 *
	 * @return void
	 */
	public function testAGarbledStoredValueIsIgnored(): void {
		$this->config['learniq.' . self::WALLET_CONFIG_KEY] = '{"status": 3}';

		$this->service()->reportObservations();

		$this->assertSame(expected: [], actual: $this->sent);
	}//end testAGarbledStoredValueIsIgnored()

	/**
	 * Without integriq nothing is sent or logged, and the observation is kept.
	 *
	 * Only the class lookup is replaced. The stub makes the event class
	 * resolvable in this process, so absence is simulated at the one seam
	 * that asks.
	 *
	 * @return void
	 */
	public function testWithoutIntegriqNothingIsSentOrLogged(): void {
		$this->dispatcher->expects($this->never())->method('dispatchTyped');
		$this->logger->expects($this->never())->method('warning');
		$this->logger->expects($this->never())->method('error');

		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-09-14 10:30:00', new DateTimeZone('UTC')));

		$service = new class($this->appConfig, $this->dispatcher, $time, $this->logger) extends ConnectionReportService {

			/**
			 * Integriq is not installed, so no class resolves.
			 *
			 * @param string $eventClass The class name asked for.
			 *
			 * @return string|null Always null.
			 */
			protected function resolveEventClass(string $eventClass): ?string {
				return null;
			}//end resolveEventClass()
		};

		$service->observe(key: 'eudi-wallet', status: 'configured', reason: 'The last wallet offer reached integriq.');
		$service->reportObservations();

		$this->assertSame(expected: [], actual: $this->sent);
		$this->assertArrayHasKey(key: 'learniq.' . self::WALLET_CONFIG_KEY, array: $this->config);
	}//end testWithoutIntegriqNothingIsSentOrLogged()

	/**
	 * The class lookup answers null for a class nobody ships, and the class for the stub.
	 *
	 * This is the real guard, not the test double above.
	 *
	 * @return void
	 */
	public function testTheLookupAnswersNullForAnAbsentClass(): void {
		$method = new ReflectionMethod(ConnectionReportService::class, 'resolveEventClass');
		$service = $this->service();

		$this->assertNull(actual: $method->invoke($service, 'OCA\\Nobody\\Event\\ShipsThisEvent'));
		$this->assertSame(
			expected: '\\' . ConnectionReportService::STATUS_EVENT,
			actual: $method->invoke($service, ConnectionReportService::STATUS_EVENT)
		);
	}//end testTheLookupAnswersNullForAnAbsentClass()

	/**
	 * The event name is the one the contract fixes.
	 *
	 * A string class name is exactly the reference that rots into a silent
	 * no-op after a rename, so it is compared to the stub's real name.
	 *
	 * @return void
	 */
	public function testTheEventNameIsTheContractName(): void {
		$this->assertSame(expected: ConnectionStatusReportedEvent::class, actual: ConnectionReportService::STATUS_EVENT);
	}//end testTheEventNameIsTheContractName()

	/**
	 * A listener that throws never escapes, and is logged per connection.
	 *
	 * @return void
	 */
	public function testAThrowingListenerNeverEscapes(): void {
		$dispatcher = $this->createMock(originalClassName: IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willThrowException(new RuntimeException('registry down'));
		$this->logger->expects($this->once())->method('warning')
			->with($this->stringContains(string: 'could not send'), $this->arrayHasKey(key: 'key'));

		$service = $this->service(dispatcher: $dispatcher);
		$service->observe(key: 'eudi-wallet', status: 'configured', reason: 'The last wallet offer reached integriq.');

		// Reaching the next line is the assertion that nothing escaped.
		$service->reportObservations();
		$this->assertSame(expected: [], actual: $this->sent);
	}//end testAThrowingListenerNeverEscapes()

	/**
	 * A config store that throws never escapes an observation.
	 *
	 * @return void
	 */
	public function testAFailingConfigStoreNeverEscapesAnObservation(): void {
		$this->appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$this->appConfig->method('getValueString')->willThrowException(new RuntimeException('database gone'));
		$this->logger->expects($this->once())->method('warning')
			->with($this->stringContains(string: 'could not record'), $this->arrayHasKey(key: 'key'));

		// Reaching the next line is the assertion that nothing escaped.
		$this->service()->observe(key: 'eudi-wallet', status: 'error', reason: 'Integriq refused the wallet offer: 401.');
		$this->assertSame(expected: 0, actual: $this->writes);
	}//end testAFailingConfigStoreNeverEscapesAnObservation()
}//end class
