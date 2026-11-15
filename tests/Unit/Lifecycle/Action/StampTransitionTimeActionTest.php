<?php

/**
 * Unit tests for StampTransitionTimeAction (compliance-attestation-locked-after-signing).
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Lifecycle\Action
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/compliance-attestation-locked-after-signing/specs/compliance-audit/spec.md#requirement-revoking-a-signed-attestation-records-a-reason-and-changes-nothing-else
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle\Action;

use DateTimeImmutable;
use OCA\Learniq\Lifecycle\Action\StampTransitionTimeAction;
use OCA\OpenRegister\Lifecycle\LifecycleActionContext;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The sign transition stamps signedAt from the server, before it is signed.
 */
class StampTransitionTimeActionTest extends TestCase {

	/**
	 * The context of a sign transition with the given parameters.
	 *
	 * @param array<string,mixed> $parameters The declared actionParameters.
	 * @param array<string,mixed> $data       The object data.
	 *
	 * @return LifecycleActionContext
	 */
	private function context(array $parameters, array $data = ['lifecycle' => 'signed']): LifecycleActionContext {
		return new LifecycleActionContext(
			objectData: $data,
			previousData: [],
			parameters: $parameters,
			actionName: StampTransitionTimeAction::class
		);
	}//end context()

	/**
	 * OpenRegister's action registry refuses a handler without the interface.
	 *
	 * @return void
	 */
	public function testImplementsTheOpenRegisterActionInterface(): void {
		self::assertInstanceOf(LifecycleActionInterface::class, new StampTransitionTimeAction());
	}//end testImplementsTheOpenRegisterActionInterface()

	/**
	 * The declared field gets the server's UTC time, replacing a caller's value; the rest is kept.
	 *
	 * @return void
	 */
	public function testTheDeclaredFieldGetsTheServerTime(): void {
		$before = new DateTimeImmutable('-1 second');

		$result = (new StampTransitionTimeAction())->execute(
			$this->context(['timeField' => 'signedAt'], ['lifecycle' => 'signed', 'signedAt' => '1999-01-01T00:00:00+00:00', 'score' => 7])
		);

		$stamp = new DateTimeImmutable($result['signedAt']);
		self::assertGreaterThanOrEqual($before, $stamp);
		self::assertLessThanOrEqual(new DateTimeImmutable('+1 second'), $stamp);
		self::assertStringEndsWith('+00:00', $result['signedAt']);
		self::assertSame(7, $result['score']);
		self::assertSame('signed', $result['lifecycle']);
	}//end testTheDeclaredFieldGetsTheServerTime()

	/**
	 * A declaration without a timeField is a register mistake and fails loudly.
	 *
	 * @return void
	 */
	public function testAMissingTimeFieldFails(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('declares no timeField');

		(new StampTransitionTimeAction())->execute($this->context([]));
	}//end testAMissingTimeFieldFails()
}//end class
