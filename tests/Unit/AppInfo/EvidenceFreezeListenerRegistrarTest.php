<?php

/**
 * The evidence guard that refuses a delete also refuses a move out of the register.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\AppInfo;

use OCA\Learniq\Listener\Registrar\EvidenceFreezeListenerRegistrar;
use OCA\Learniq\Listener\AssessmentResultIntegrityListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * OpenRegister dispatches ObjectLeavingEvent, not ObjectDeletingEvent, before a
 * move takes an object out of its register, so a guard on the delete event alone
 * is skipped by a move.
 *
 * @covers \OCA\Learniq\Listener\Registrar\EvidenceFreezeListenerRegistrar
 */
final class EvidenceFreezeListenerRegistrarTest extends TestCase {
	/**
	 * Every listener on the delete event is on the move event too.
	 *
	 * @return void
	 */
	public function testEveryDeleteGuardAlsoHearsAMove(): void {
		$recorded = [];
		$context  = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener, int $priority = 0) use (&$recorded): void {
				$recorded[$event][] = $listener;
			}
		);

		(new EvidenceFreezeListenerRegistrar())->register(context: $context);

		$deleting = array_unique($recorded['OCA\\OpenRegister\\Event\\ObjectDeletingEvent'] ?? []);
		$this->assertContains(needle: AssessmentResultIntegrityListener::class, haystack: $deleting);
		$this->assertSame(
			expected: [],
			actual: array_values(array_diff($deleting, ($recorded['OCA\\OpenRegister\\Event\\ObjectLeavingEvent'] ?? [])))
		);
	}//end testEveryDeleteGuardAlsoHearsAMove()
}//end class
