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
use OCA\Learniq\Service\Settings\AppealTerm;
use OCA\OpenRegister\Lifecycle\LifecycleActionContext;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCP\IAppConfig;
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
		self::assertInstanceOf(expected: LifecycleActionInterface::class, actual: new FraudCaseAppealDeadlineAction());
	}//end testImplementsTheOpenRegisterActionInterface()

	/**
	 * decidedAt is the server's UTC time and appealDeadline is exactly 42 days later.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/exam-board/spec.md#scenario-deciding-a-case-stamps-the-appeal-deadline
	 */
	public function testDecidedAtAndAppealDeadlineEndUpOnTheSavedObject(): void {
		$object = [
			'id' => 'case-1',
			'lifecycle' => 'decided',
			'verdict' => 'fraud-proven',
			'decisionRationale' => 'Plagiarism confirmed via Turnitin report.',
			'decidedAt' => '1999-01-01T00:00:00+00:00',
		];

		$result = (new FraudCaseAppealDeadlineAction())->execute(
			new LifecycleActionContext(
				objectData: $object,
				previousData: [],
				parameters: [],
				actionName: FraudCaseAppealDeadlineAction::class
			)
		);

		$decidedAt = DateTimeImmutable::createFromFormat(format: DateTimeInterface::ATOM, datetime: $result['decidedAt']);
		$appealDeadline = DateTimeImmutable::createFromFormat(format: DateTimeInterface::ATOM, datetime: $result['appealDeadline']);

		self::assertNotFalse(condition: $decidedAt);
		self::assertNotFalse(condition: $appealDeadline);
		self::assertStringEndsWith(suffix: '+00:00', string: $result['decidedAt']);
		self::assertNotSame(expected: '1999-01-01T00:00:00+00:00', actual: $result['decidedAt']);
		self::assertSame(expected: $decidedAt->modify('+42 days')->format(DateTimeInterface::ATOM), actual: $result['appealDeadline']);
		self::assertSame(expected: 'fraud-proven', actual: $result['verdict']);
		self::assertSame(expected: 'decided', actual: $result['lifecycle']);
	}//end testDecidedAtAndAppealDeadlineEndUpOnTheSavedObject()

	/**
	 * A school that set 28 days gets a deadline 28 days after the decision.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/exam-board/spec.md#scenario-the-school-sets-its-own-appeal-term
	 */
	public function testTheSchoolsOwnAppealTermSetsTheDeadline(): void {
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturn('28');
		$action = new FraudCaseAppealDeadlineAction(appealTerm: new AppealTerm(appConfig: $config));

		$result = $action->execute(
			new LifecycleActionContext(
				objectData: ['lifecycle' => 'decided'],
				previousData: [],
				parameters: [],
				actionName: FraudCaseAppealDeadlineAction::class
			)
		);

		$decidedAt = DateTimeImmutable::createFromFormat(format: DateTimeInterface::ATOM, datetime: $result['decidedAt']);
		self::assertNotFalse(condition: $decidedAt);
		self::assertSame(expected: $decidedAt->modify('+28 days')->format(DateTimeInterface::ATOM), actual: $result['appealDeadline']);
	}//end testTheSchoolsOwnAppealTermSetsTheDeadline()
}//end class
