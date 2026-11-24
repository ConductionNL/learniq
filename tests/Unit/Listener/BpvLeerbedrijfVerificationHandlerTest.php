<?php

/**
 * Learniq BpvLeerbedrijfVerificationHandler unit tests.
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
 * @spec openspec/specs/bpv/spec.md#requirement-leerbedrijf-verification-is-a-pluggable-provider
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Learniq\Bpv\IntegriqLeerbedrijfVerification;
use OCA\Learniq\Bpv\ProvidesLeerbedrijfVerification;
use OCA\Learniq\Lifecycle\BpvConfirmationGuard;
use OCA\Learniq\Service\ConnectionReportService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use OCA\Learniq\Listener\BpvLeerbedrijfVerificationHandler;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use Opis\JsonSchema\Validator;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;

/**
 * Tests for BpvLeerbedrijfVerificationHandler::handle() on
 * BpvPlacement → sbb-verification-pending.
 */
class BpvLeerbedrijfVerificationHandlerTest extends TestCase {

	/**
	 * Recorded saveObject() calls, captured by the ObjectService stub used per test.
	 *
	 * @var array<int, array{register: string, schema: string, object: array<string, mixed>}>
	 */
	private array $savedObjects = [];

	/**
	 * Reset the capture buffer before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->savedObjects = [];

	}//end setUp()

	/**
	 * Build a handler with a stubbed ObjectService and the given container.
	 *
	 * @param ContainerInterface $container DI container stub.
	 *
	 * @return BpvLeerbedrijfVerificationHandler
	 */
	private function makeHandler(ContainerInterface $container): BpvLeerbedrijfVerificationHandler {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ObjectSaveOptions $options = new ObjectSaveOptions()): ObjectEntity {
				$extend = $options->extend ?? [];
				$register = $options->target->register;
				$schema = $options->target->schema;
				$this->savedObjects[] = [
					'register' => (string)$register,
					'schema' => (string)$schema,
					'object' => $object,
				];
				return OrEntityFactory::make($object, (string)$schema, (string)$register);
			}
		);

		return new BpvLeerbedrijfVerificationHandler($objectService, $container, $this->createMock(LoggerInterface::class), \OCA\Learniq\Tests\Support\TransitionScope::resolver());
	}//end makeHandler()

	/**
	 * Build a mocked ObjectTransitionedEvent for a BpvPlacement → sbb-verification-pending
	 * transition.
	 *
	 * @param array<string, mixed> $placementData The BpvPlacement's jsonSerialize() payload.
	 *
	 * @return ObjectTransitionedEvent
	 */
	private function makeEvent(array $placementData): ObjectTransitionedEvent {
		$objectEntity = $this->createMock(ObjectEntity::class);
		$objectEntity->method('jsonSerialize')->willReturn($placementData);

		$event = $this->createMock(ObjectTransitionedEvent::class);
		$event->method('getObject')->willReturn($objectEntity);
		$event->method('getRegister')->willReturn('learniq');
		$event->method('getSchema')->willReturn('bpv-placement');
		$event->method('getTo')->willReturn('sbb-verification-pending');
		$event->method('getFrom')->willReturn('proposed');

		return $event;
	}//end makeEvent()

	/**
	 * A configured, resolvable, `verified`-returning provider writes the full result back onto
	 * the BpvPlacement.
	 *
	 * @return void
	 */
	public function testConfiguredProviderWritesVerifiedResultBack(): void {
		$fakeProvider = new class implements ProvidesLeerbedrijfVerification {
			public function verify(string $kvkOrErkenningNumber): array {
				return [
					'status' => 'verified',
					'erkenningNumber' => 'SBB-999',
					'expiresAt' => '2027-01-01T00:00:00+00:00',
					'raw' => ['source' => 'fake'],
				];
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('FakeSbbAdapter')->willReturn($fakeProvider);

		$handler = $this->makeHandler($container);
		$placement = [
			'id' => 'placement-1',
			'trainingCompanyKvkNumber' => '12345678',
			'trainingCompanyVerification' => ['provider' => 'FakeSbbAdapter', 'status' => 'unverified'],
		];

		$handler->handle($this->makeEvent($placement));

		$this->assertCount(1, $this->savedObjects);
		$saved = $this->savedObjects[0]['object']['trainingCompanyVerification'];
		$this->assertSame('verified', $saved['status']);
		$this->assertSame('SBB-999', $saved['erkenningNumber']);
		$this->assertSame('2027-01-01T00:00:00+00:00', $saved['expiresAt']);
		$this->assertSame(['source' => 'fake'], $saved['raw']);
		$this->assertNotEmpty($saved['verifiedAt']);
		$this->assertNotEmpty(actual: $saved['checkedAt']);
		$this->assertNull(actual: $saved['reason']);

	}//end testConfiguredProviderWritesVerifiedResultBack()

	/**
	 * No provider named, or `integriq`: the handler resolves the integriq
	 * provider learniq ships. Without integriq that provider answers pending
	 * with a reason, the handler stores it with the time of the check, and the
	 * confirmation guard still refuses the placement (the real wiring).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/workplace-leerbedrijf-check-through-integriq/specs/bpv/spec.md#requirement-a-missing-integration-fails-closed
	 */
	public function testWithoutANamedProviderTheIntegriqProviderChecksAndFailsClosed(): void {
		foreach ([null, '', 'integriq'] as $named) {
			$this->savedObjects = [];
			$apps = $this->createMock(originalClassName: IAppManager::class);
			$apps->method('isEnabledForUser')->willReturn(false);
			$provider = new IntegriqLeerbedrijfVerification(
				dispatcher: $this->createMock(originalClassName: IEventDispatcher::class),
				appManager: $apps,
				timeFactory: $this->createMock(originalClassName: ITimeFactory::class),
				reports: $this->createMock(originalClassName: ConnectionReportService::class)
			);
			$asked = [];
			$container = $this->createMock(originalClassName: ContainerInterface::class);
			$container->method('get')->willReturnCallback(
				static function (string $id) use (&$asked, $provider): object {
					$asked[] = $id;
					return $provider;
				}
			);

			$this->makeHandler(container: $container)->handle(
				event: $this->makeEvent(
					placementData: [
						'id' => 'placement-1',
						'trainingCompanyKvkNumber' => '',
						'trainingCompanyVerification' => ['provider' => $named, 'status' => 'unverified', 'erkenningNumber' => '100234567'],
					]
				)
			);

			$this->assertSame(expected: [IntegriqLeerbedrijfVerification::class], actual: $asked);
			$this->assertCount(expectedCount: 1, haystack: $this->savedObjects);
			$saved = $this->savedObjects[0]['object'];
			$this->assertSame(expected: 'pending', actual: $saved['trainingCompanyVerification']['status']);
			$this->assertSame(expected: IntegriqLeerbedrijfVerification::REASON_NO_INTEGRIQ, actual: $saved['trainingCompanyVerification']['reason']);
			$this->assertNotEmpty(actual: $saved['trainingCompanyVerification']['checkedAt']);

			$verdict = (new BpvConfirmationGuard(logger: $this->createMock(originalClassName: LoggerInterface::class)))->check(object: $saved, action: 'confirm', userId: '');
			$this->assertFalse(condition: $verdict->isAllowed());
		}//end foreach
	}//end testWithoutANamedProviderTheIntegriqProviderChecksAndFailsClosed()

	/**
	 * A configured provider FQCN the container cannot resolve is also a no-op — the handler
	 * never throws.
	 *
	 * @return void
	 */
	public function testUnresolvableProviderIsNoOp(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(
			$this->createMock(NotFoundExceptionInterface::class)
		);

		$handler = $this->makeHandler($container);
		$placement = [
			'id' => 'placement-1',
			'trainingCompanyKvkNumber' => '12345678',
			'trainingCompanyVerification' => ['provider' => 'MissingClass', 'status' => 'unverified'],
		];

		$handler->handle($this->makeEvent($placement));

		$this->assertCount(0, $this->savedObjects);

	}//end testUnresolvableProviderIsNoOp()

	/**
	 * A resolved service that does NOT implement ProvidesLeerbedrijfVerification is treated as
	 * unconfigured (no-op) — never called, never crashes.
	 *
	 * @return void
	 */
	public function testResolvedServiceNotImplementingInterfaceIsNoOp(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn(new \stdClass());

		$handler = $this->makeHandler($container);
		$placement = [
			'id' => 'placement-1',
			'trainingCompanyKvkNumber' => '12345678',
			'trainingCompanyVerification' => ['provider' => 'NotAnAdapter', 'status' => 'unverified'],
		];

		$handler->handle($this->makeEvent($placement));

		$this->assertCount(0, $this->savedObjects);

	}//end testResolvedServiceNotImplementingInterfaceIsNoOp()

	/**
	 * Each provider that cannot be used logs why, and the container is never
	 * asked for a provider that is not named.
	 *
	 * @return void
	 */
	public function testEveryUnusableProviderLogsWhy(): void {
		$cases = [
			'not named, the shipped provider is not an adapter here' => ['', [IntegriqLeerbedrijfVerification::class], ['[BpvLeerbedrijfVerificationHandler] Configured provider "{class}" does not implement ProvidesLeerbedrijfVerification.', ['class' => IntegriqLeerbedrijfVerification::class]]],
			'not a name' => [42, [], []],
			'unresolvable' => ['MissingClass', ['MissingClass'], ['[BpvLeerbedrijfVerificationHandler] Configured provider "{class}" could not be resolved: {msg}', ['class' => 'MissingClass', 'msg' => 'no such service']]],
			'not an adapter' => ['NotAnAdapter', ['NotAnAdapter'], ['[BpvLeerbedrijfVerificationHandler] Configured provider "{class}" does not implement ProvidesLeerbedrijfVerification.', ['class' => 'NotAnAdapter']]],
		];

		foreach ($cases as $label => [$provider, $asked, $warning]) {
			$requests = [];
			$container = $this->createMock(ContainerInterface::class);
			$container->method('get')->willReturnCallback(
				static function (string $id) use (&$requests): object {
					$requests[] = $id;
					if ($id === 'MissingClass') {
						throw new \RuntimeException('no such service');
					}

					return new \stdClass();
				}
			);
			$warnings = [];
			$logger = $this->createMock(LoggerInterface::class);
			$logger->method('warning')->willReturnCallback(
				static function (string $message, array $context = []) use (&$warnings): void {
					$warnings[] = [$message, $context];
				}
			);
			$handler = new BpvLeerbedrijfVerificationHandler($this->createMock(ObjectService::class), $container, $logger, \OCA\Learniq\Tests\Support\TransitionScope::resolver());

			$handler->handle(
				$this->makeEvent(['id' => 'placement-1', 'trainingCompanyKvkNumber' => '12345678', 'trainingCompanyVerification' => ['provider' => $provider, 'status' => 'unverified']])
			);

			$this->assertSame($asked, $requests, $label);
			$this->assertSame(($warning === [] ? [] : [$warning]), $warnings, $label);
		}
	}//end testEveryUnusableProviderLogsWhy()

	/**
	 * A `rejected`/`pending` result is written back without advancing the lifecycle (the
	 * handler only ever writes the leerbedrijfVerification field — it never calls the
	 * transition engine).
	 *
	 * @return void
	 */
	public function testRejectedResultIsWrittenBackWithoutTransition(): void {
		$fakeProvider = new class implements ProvidesLeerbedrijfVerification {
			public function verify(string $kvkOrErkenningNumber): array {
				return ['status' => 'rejected', 'erkenningNumber' => null, 'expiresAt' => null, 'raw' => []];
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($fakeProvider);

		$handler = $this->makeHandler($container);
		$placement = [
			'id' => 'placement-1',
			'trainingCompanyKvkNumber' => '12345678',
			'trainingCompanyVerification' => ['provider' => 'FakeSbbAdapter', 'status' => 'unverified'],
		];

		$handler->handle($this->makeEvent($placement));

		$this->assertCount(1, $this->savedObjects);
		$this->assertSame('rejected', $this->savedObjects[0]['object']['trainingCompanyVerification']['status']);
		// No lifecycle field write attempted — the saved object carries no 'lifecycle' change
		// beyond what was already on the placement (none was present here).
		$this->assertArrayNotHasKey('lifecycle', $this->savedObjects[0]['object']);

	}//end testRejectedResultIsWrittenBackWithoutTransition()

	/**
	 * Events for other schemas/states are ignored entirely.
	 *
	 * @return void
	 */
	public function testIgnoresUnrelatedEvents(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');
		$handler = $this->makeHandler($container);

		$objectEntity = $this->createMock(ObjectEntity::class);
		$event = $this->createMock(ObjectTransitionedEvent::class);
		$event->method('getObject')->willReturn($objectEntity);
		$event->method('getRegister')->willReturn('learniq');
		$event->method('getSchema')->willReturn('praktijkovereenkomst');
		$event->method('getTo')->willReturn('sbb-verification-pending');

		$handler->handle($event);

		$this->assertCount(0, $this->savedObjects);

	}//end testIgnoresUnrelatedEvents()

	/**
	 * A recognition that ends before the placement's last day is flagged on
	 * the stored result; one that lasts, or a missing date, is not.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/workplace-leerbedrijf-check-through-integriq/specs/bpv/spec.md#requirement-a-recognition-that-ends-during-the-placement-is-flagged
	 */
	public function testARecognitionEndingMidPlacementIsFlagged(): void {
		$fakeProvider = new class implements ProvidesLeerbedrijfVerification {
			public function verify(string $kvkOrErkenningNumber): array {
				return ['status' => 'verified', 'erkenningNumber' => '100234567', 'expiresAt' => '2027-01-31T00:00:00+00:00', 'raw' => []];
			}
		};
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturn($fakeProvider);

		$this->makeHandler(container: $container)->handle(
			event: $this->makeEvent(
				placementData: [
					'id' => 'placement-1',
					'periodTo' => '2027-06-30',
					'trainingCompanyKvkNumber' => '12345678',
					'trainingCompanyVerification' => ['provider' => 'FakeSbbAdapter'],
				]
			)
		);

		$this->assertTrue(condition: $this->savedObjects[0]['object']['trainingCompanyVerification']['endsBeforePlacement']);
		$this->assertFalse(condition: BpvLeerbedrijfVerificationHandler::endsBeforePlacement(expiresAt: '2027-08-31T00:00:00+00:00', periodTo: '2027-06-30'));
		$this->assertFalse(condition: BpvLeerbedrijfVerificationHandler::endsBeforePlacement(expiresAt: '2027-06-30T00:00:00+00:00', periodTo: '2027-06-30'));
		$this->assertFalse(condition: BpvLeerbedrijfVerificationHandler::endsBeforePlacement(expiresAt: null, periodTo: '2027-06-30'));
		$this->assertFalse(condition: BpvLeerbedrijfVerificationHandler::endsBeforePlacement(expiresAt: '2027-01-31', periodTo: null));
	}//end testARecognitionEndingMidPlacementIsFlagged()

	/**
	 * What the handler writes passes the shipped trainingCompanyVerification
	 * schema, validated with Opis: a verified result and the pending one the
	 * integriq provider gives without integriq.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/workplace-leerbedrijf-check-through-integriq/specs/bpv/spec.md#requirement-the-check-stores-sbbs-answer-on-the-placement
	 */
	public function testTheWrittenResultPassesTheShippedSchema(): void {
		$verified = new class implements ProvidesLeerbedrijfVerification {
			public function verify(string $kvkOrErkenningNumber): array {
				return IntegriqLeerbedrijfVerification::mapped(answer: ['found' => true, 'erkenningNumber' => '100234567', 'endDate' => '2027-08-31'], today: '2026-11-24');
			}
		};
		$apps = $this->createMock(originalClassName: IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(false);
		$pending = new IntegriqLeerbedrijfVerification(
			dispatcher: $this->createMock(originalClassName: IEventDispatcher::class),
			appManager: $apps,
			timeFactory: $this->createMock(originalClassName: ITimeFactory::class),
			reports: $this->createMock(originalClassName: ConnectionReportService::class)
		);

		$validator = new Validator();
		foreach (['verified' => $verified, 'pending' => $pending] as $status => $provider) {
			$this->savedObjects = [];
			$container = $this->createMock(originalClassName: ContainerInterface::class);
			$container->method('get')->willReturn($provider);
			$this->makeHandler(container: $container)->handle(
				event: $this->makeEvent(placementData: ['id' => 'placement-1', 'periodTo' => '2027-06-30', 'trainingCompanyKvkNumber' => '12345678', 'trainingCompanyVerification' => ['provider' => 'Adapter']])
			);

			$written = json_decode(json: (string)json_encode(value: $this->savedObjects[0]['object']['trainingCompanyVerification']));
			$this->assertSame(expected: $status, actual: $written->status);
			$result = $validator->validate(data: $written, schema: $this->verificationSchema());
			$this->assertTrue(condition: $result->isValid(), message: $status . ': ' . json_encode(value: $result->error()?->message()));
		}
	}//end testTheWrittenResultPassesTheShippedSchema()

	/**
	 * The shipped trainingCompanyVerification property as a JSON schema: the
	 * OpenAPI `nullable` read as a null type, as OpenRegister validates it.
	 *
	 * @return string
	 */
	private function verificationSchema(): string {
		$register = json_decode(json: (string)file_get_contents(filename: __DIR__ . '/../../../lib/Settings/learniq_register.json'), associative: true);
		$schema = $register['components']['schemas']['BpvPlacement']['properties']['trainingCompanyVerification'];
		$schema['additionalProperties'] = false;
		foreach ($schema['properties'] as $name => $property) {
			if (($property['nullable'] ?? false) === true && is_string(value: ($property['type'] ?? null)) === true) {
				$property['type'] = [$property['type'], 'null'];
			}

			unset($property['nullable']);
			$schema['properties'][$name] = $property;
		}

		return (string)json_encode(value: $schema);
	}//end verificationSchema()
}//end class
