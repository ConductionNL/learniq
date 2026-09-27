<?php

/**
 * Learniq FraudCaseAppealDeadlineAction unit tests.
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
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle\Action;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Learniq\Lifecycle\Action\FraudCaseAppealDeadlineAction;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use PHPUnit\Framework\TestCase;

/**
 * The stamping half of FraudCase.decide (learniq#983): FraudCaseDecisionGuard
 * only checks, this action writes decidedAt and appealDeadline onto the object
 * OpenRegister saves.
 */
class FraudCaseAppealDeadlineActionTest extends TestCase {

	/**
	 * OpenRegister's action registry refuses a handler without the interface.
	 *
	 * @return void
	 */
	public function testImplementsTheOpenRegisterActionInterface(): void {
		self::assertInstanceOf(LifecycleActionInterface::class, new FraudCaseAppealDeadlineAction());
	}//end testImplementsTheOpenRegisterActionInterface()

	/**
	 * decidedAt is the server's UTC time and appealDeadline is exactly 42 days later.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/exam-board-case-handling/specs/exam-board/spec.md#scenario-deciding-a-case-stamps-the-appeal-deadline
	 */
	public function testDecidedAtAndAppealDeadlineEndUpOnTheSavedObject(): void {
		$object = [
			'id' => 'case-1',
			'lifecycle' => 'decided',
			'verdict' => 'fraud-proven',
			'decisionRationale' => 'Plagiarism confirmed via Turnitin report.',
			'decidedAt' => '1999-01-01T00:00:00+00:00',
		];

		$result = (new FraudCaseAppealDeadlineAction())->execute($object, [], [], FraudCaseAppealDeadlineAction::class);

		$decidedAt = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $result['decidedAt']);
		$appealDeadline = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $result['appealDeadline']);

		self::assertNotFalse($decidedAt);
		self::assertNotFalse($appealDeadline);
		self::assertStringEndsWith('+00:00', $result['decidedAt']);
		self::assertNotSame('1999-01-01T00:00:00+00:00', $result['decidedAt']);
		self::assertSame($decidedAt->modify('+42 days')->format(DateTimeInterface::ATOM), $result['appealDeadline']);
		self::assertSame('fraud-proven', $result['verdict']);
		self::assertSame('decided', $result['lifecycle']);
	}//end testDecidedAtAndAppealDeadlineEndUpOnTheSavedObject()
}//end class
