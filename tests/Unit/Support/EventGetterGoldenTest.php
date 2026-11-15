<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Support;

use OCA\Learniq\Listener\ExchangeGateListener;
use OCA\Learniq\Listener\ExchangeImportLandingListener;
use OCA\Learniq\Listener\ExchangeJobConcludedListener;
use OCA\Learniq\Service\IntegriqExchangeClient;
use OCA\Learniq\Service\OpenMaterial\OpenMaterialSourceClient;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Pins read() of the five classes that read another app's event by getter
 * name: the getter's answer when the event has it, null when it has not.
 */
class EventGetterGoldenTest extends TestCase {

	/**
	 * The classes.
	 *
	 * @return array<string, array{0: class-string}>
	 */
	public static function readers(): array {
		return [
			'exchange gate' => [ExchangeGateListener::class],
			'exchange import landing' => [ExchangeImportLandingListener::class],
			'exchange job concluded' => [ExchangeJobConcludedListener::class],
			'integriq exchange client' => [IntegriqExchangeClient::class],
			'open material source client' => [OpenMaterialSourceClient::class],
		];
	}//end readers()

	/**
	 * A getter the event has answers; one it has not is null.
	 *
	 * @param class-string $class The class.
	 *
	 * @return void
	 *
	 * @dataProvider readers
	 */
	public function testAGetterIsReadWhenTheEventHasIt(string $class): void {
		$event = new class extends Event {
			/**
			 * A getter.
			 *
			 * @return array<string, string>
			 */
			public function getJob(): array {
				return ['id' => 'job-1'];
			}//end getJob()

			/**
			 * A getter answering null.
			 *
			 * @return null
			 */
			public function getNothing(): mixed {
				return null;
			}//end getNothing()
		};
		$instance = (new ReflectionClass($class))->newInstanceWithoutConstructor();
		$read = new ReflectionMethod($class, 'read');

		self::assertSame(['id' => 'job-1'], $read->invoke($instance, $event, 'getJob'));
		self::assertNull($read->invoke($instance, $event, 'getNothing'));
		self::assertNull($read->invoke($instance, $event, 'getMissing'));
		self::assertNull($read->invoke($instance, new Event(), 'getJob'));
	}//end testAGetterIsReadWhenTheEventHasIt()
}//end class
