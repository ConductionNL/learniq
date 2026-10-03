<?php

/**
 * Learniq TransitionBridgeListenerRegistrar wiring test.
 *
 * The two transition bridges create a follow-up object (a renewal Enrolment,
 * a bron-rod DataExchangeJob). Registered twice, each would create two. They
 * moved out of the case and scheduling registrars in round1-landing-repairs,
 * so a merge that keeps the old line beside the new one is the realistic way
 * to get a double registration; this test fails on it.
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
 *
 * @spec openspec/specs/certification/spec.md#requirement-auto-enrol-on-renewal-or-content-version-change
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\AppInfo;

use OCA\Learniq\AppInfo\Registrar\EventListenerWiring;
use OCA\Learniq\AppInfo\Registrar\TransitionBridgeListenerRegistrar;
use OCA\Learniq\Listener\CredentialRenewalListener;
use OCA\Learniq\Listener\SchoolAdviesSendToRodHandler;
use OCA\Learniq\Listener\SchoolAdviesVoorlopigRodHandler;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * Asserts each transition bridge is registered exactly once.
 */
class TransitionBridgeListenerRegistrarTest extends TestCase {

	/**
	 * The event => listener pairs a registration run produces.
	 *
	 * @param callable $register Receives the capturing context.
	 *
	 * @return array<int, string>
	 */
	private function capture(callable $register): array {
		$pairs = [];
		$context = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$pairs): void {
				$pairs[] = $event . ' => ' . $listener;
			}
		);

		$register($context);

		return $pairs;
	}//end capture()

	/**
	 * The registrar wires both bridges on the transition event, and the
	 * voorlopig school advice to ROD on create and update.
	 *
	 * @return void
	 */
	public function testTheRegistrarWiresBothBridges(): void {
		$pairs = $this->capture(
			register: static function (IRegistrationContext $context): void {
				(new TransitionBridgeListenerRegistrar())->register(context: $context);
			}
		);

		$this->assertSame(
			expected: [
				ObjectTransitionedEvent::class . ' => ' . CredentialRenewalListener::class,
				ObjectTransitionedEvent::class . ' => ' . SchoolAdviesSendToRodHandler::class,
				ObjectCreatedEvent::class . ' => ' . SchoolAdviesVoorlopigRodHandler::class,
				ObjectUpdatedEvent::class . ' => ' . SchoolAdviesVoorlopigRodHandler::class,
			],
			actual: $pairs
		);
	}//end testTheRegistrarWiresBothBridges()

	/**
	 * Across the whole wiring each bridge is registered once, so a transition
	 * creates one follow-up object.
	 *
	 * @return void
	 */
	public function testEachBridgeIsRegisteredOnceAcrossTheWiring(): void {
		$pairs = $this->capture(
			register: static function (IRegistrationContext $context): void {
				(new EventListenerWiring())->registerAll(context: $context);
			}
		);

		$counts = array_count_values($pairs);
		foreach ([CredentialRenewalListener::class, SchoolAdviesSendToRodHandler::class] as $listener) {
			$this->assertSame(
				expected: 1,
				actual: ($counts[ObjectTransitionedEvent::class . ' => ' . $listener] ?? 0),
				message: $listener
			);
		}
	}//end testEachBridgeIsRegisteredOnceAcrossTheWiring()
}//end class
