<?php

/**
 * Learniq exam schedule unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\ExamSchedule
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

namespace OCA\Learniq\Tests\Unit\ExamSchedule;

use OCA\Learniq\Lifecycle\InvigilatorResponseGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Only the invigilator asked may confirm or decline, and the register names the guard on both transitions.
 *
 * @spec openspec/specs/exam-schedule/spec.md#requirement-invigilator-assignment
 */
class InvigilatorResponseGuardTest extends TestCase {

	/**
	 * The invigilator confirms their own request.
	 *
	 * @return void
	 */
	public function testTheInvigilatorMayAnswer(): void {
		$result = (new InvigilatorResponseGuard(new NullLogger()))->check(['invigilatorId' => 'inv-1'], 'confirm', 'inv-1');

		self::assertTrue($result->isAllowed());
	}//end testTheInvigilatorMayAnswer()

	/**
	 * A planner cannot confirm on someone's behalf.
	 *
	 * @return void
	 */
	public function testSomeoneElseMayNot(): void {
		$result = (new InvigilatorResponseGuard(new NullLogger()))->check(['invigilatorId' => 'inv-1'], 'decline', 'planner-1');

		self::assertFalse($result->isAllowed());
	}//end testSomeoneElseMayNot()

	/**
	 * No session, no answer.
	 *
	 * @return void
	 */
	public function testNoSessionMayNot(): void {
		$result = (new InvigilatorResponseGuard(new NullLogger()))->check(['invigilatorId' => ''], 'confirm', '');

		self::assertFalse($result->isAllowed());
	}//end testNoSessionMayNot()

	/**
	 * The register wires the guard on confirm and decline, and the guard class exists.
	 *
	 * @return void
	 */
	public function testTheRegisterNamesTheGuardOnBothAnswers(): void {
		$schemas = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'), true)['components']['schemas'];
		$transitions = $schemas['InvigilatorAssignment']['x-openregister-lifecycle']['transitions'];

		foreach (['confirm', 'decline'] as $action) {
			self::assertSame(InvigilatorResponseGuard::class, $transitions[$action]['requires']);
		}

		self::assertSame('declined', $transitions['decline']['to']);
	}//end testTheRegisterNamesTheGuardOnBothAnswers()
}//end class
