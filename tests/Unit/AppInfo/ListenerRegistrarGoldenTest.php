<?php

/**
 * Learniq listener registrar golden tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\AppInfo
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

namespace OCA\Learniq\Tests\Unit\AppInfo;

use OCA\Learniq\AppInfo\Registrar\BootListenerRegistrar;
use OCA\Learniq\AppInfo\Registrar\GradingListenerRegistrar;
use OCA\Learniq\AppInfo\Registrar\SafeguardingListenerRegistrar;
use OCA\Learniq\AppInfo\Registrar\SchedulingListenerRegistrar;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;

/**
 * Pins every registration the boot, grading, safeguarding and scheduling
 * registrars make, in order, against
 * tests/Unit/AppInfo/golden/ListenerRegistrars.json. With RECORD_GOLDEN=1 the
 * file is written instead of compared.
 *
 * @covers \OCA\Learniq\AppInfo\Registrar\BootListenerRegistrar
 * @covers \OCA\Learniq\AppInfo\Registrar\GradingListenerRegistrar
 * @covers \OCA\Learniq\AppInfo\Registrar\SafeguardingListenerRegistrar
 * @covers \OCA\Learniq\AppInfo\Registrar\SchedulingListenerRegistrar
 * @covers \OCA\Learniq\AppInfo\Registrar\RegistersEventListeners
 * @uses   \OCA\Learniq\AppInfo\OpenRegisterAutoloader
 * @uses   \OCA\Learniq\AppInfo\Registrar\AttemptLimitListenerRegistrar
 * @uses   \OCA\Learniq\AppInfo\Registrar\GovernanceListenerRegistrar
 */
class ListenerRegistrarGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/golden/ListenerRegistrars.json';

	/**
	 * Every registration equals the recorded golden.
	 *
	 * @return void
	 */
	public function testRegistrationsMatchGolden(): void {
		$calls = [];
		$context = $this->createMock(IRegistrationContext::class);
		foreach (['registerEventListener', 'registerService', 'registerServiceAlias', 'registerMiddleware'] as $method) {
			$context->method($method)->willReturnCallback(
				static function (...$args) use (&$calls, $method): void {
					$calls[] = [$method, array_map(static fn ($arg) => is_string($arg) ? $arg : get_debug_type($arg), $args)];
				}
			);
		}

		(new GradingListenerRegistrar())->register($context);
		(new SafeguardingListenerRegistrar())->register($context);
		(new SchedulingListenerRegistrar())->register($context);

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('addServiceListener')->willReturnCallback(
			static function (...$args) use (&$calls): void {
				$calls[] = ['addServiceListener', $args];
			}
		);
		$boot = new class extends BootListenerRegistrar {
			/**
			 * Every filtered registration.
			 *
			 * @var list<array<int,mixed>>
			 */
			public array $filtered = [];

			/**
			 * Record the filtered registration instead of subscribing it.
			 *
			 * @param IEventDispatcher    $dispatcher   The dispatcher.
			 * @param array<string,mixed> $subscription The app id, event class, listener class, register slugs and schema slugs.
			 *
			 * @return void
			 */
			public function registerFilteredObjectListener(IEventDispatcher $dispatcher, array $subscription): void {
				$this->filtered[] = [$subscription['appId'], $subscription['event'], $subscription['listener'], $subscription['registers'], $subscription['schemas']];
			}
		};
		$boot->register($dispatcher, 'learniq');
		$actual = ['context' => $calls, 'filtered' => $boot->filtered];

		$actual = json_decode((string)json_encode($actual, JSON_UNESCAPED_SLASHES), true);
		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n");
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), $actual);
	}//end testRegistrationsMatchGolden()
}//end class
