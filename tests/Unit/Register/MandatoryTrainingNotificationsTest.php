<?php

/**
 * Learniq mandatory-training reminders test.
 *
 * The learner is reminded from three days before a mandatory enrolment is due;
 * once it is past due, the manager and the hr group are told. Both are declared
 * scheduled notification rules on Enrolment, delivered by OpenRegister. A rule
 * filtering only on mandatory and dueDate kept matching an enrolment that was
 * completed or withdrawn, so both rules also filter on the open states.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Register
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/compliance-rule-coverage/spec.md#requirement-learners-are-reminded-before-managers-are-told-after-the-deadline
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Register;

use PHPUnit\Framework\TestCase;

/**
 * Asserts the Enrolment dueReminder and overdue rules.
 */
class MandatoryTrainingNotificationsTest extends TestCase {

	/**
	 * The Enrolment schema as shipped.
	 *
	 * @return array<string, mixed>
	 */
	private function schema(): array {
		$register = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'),
			true
		);

		return $register['components']['schemas']['Enrolment'];
	}//end schema()

	/**
	 * One notification rule of Enrolment.
	 *
	 * @param string $name The rule's name.
	 *
	 * @return array<string, mixed>
	 */
	private function rule(string $name): array {
		return $this->schema()['x-openregister-notifications'][$name];
	}//end rule()

	/**
	 * Whether a scheduled rule's state filter lets an enrolment in this state through.
	 *
	 * A bare list is membership in OpenRegister's scheduled filter grammar; a
	 * rule without a lifecycle clause lets every state through.
	 *
	 * @param array<string, mixed> $rule  The rule.
	 * @param string               $state The enrolment's lifecycle state.
	 *
	 * @return bool
	 */
	private static function admits(array $rule, string $state): bool {
		$states = ($rule['trigger']['filter']['lifecycle'] ?? null);
		if ($states === null) {
			return true;
		}

		return in_array($state, (array)$states, true);
	}//end admits()

	/**
	 * The learner is reminded daily from three days before a mandatory course is due.
	 *
	 * @return void
	 */
	public function testTheLearnerIsRemindedThreeDaysBeforeTheDueDate(): void {
		$rule = $this->rule('dueReminder');

		self::assertSame('scheduled', $rule['trigger']['type']);
		self::assertSame(86400, $rule['trigger']['intervalSec']);
		self::assertTrue($rule['trigger']['filter']['mandatory']);
		self::assertSame(['operator' => 'withinNext', 'value' => 'P3D'], $rule['trigger']['filter']['dueDate']);
		self::assertSame([['kind' => 'field', 'field' => 'learnerId']], $rule['recipients']);
		self::assertSame('Your mandatory course is due soon', $rule['subject']['en']);
	}//end testTheLearnerIsRemindedThreeDaysBeforeTheDueDate()

	/**
	 * Past the due date, the manager and the hr group are told daily.
	 *
	 * @return void
	 */
	public function testTheManagerAndHrAreToldOnceItIsOverdue(): void {
		$rule = $this->rule('overdue');

		self::assertSame('scheduled', $rule['trigger']['type']);
		self::assertSame(86400, $rule['trigger']['intervalSec']);
		self::assertTrue($rule['trigger']['filter']['mandatory']);
		self::assertSame(['operator' => 'olderThan', 'value' => 'PT0S'], $rule['trigger']['filter']['dueDate']);
		self::assertSame(
			[['kind' => 'field', 'field' => 'managerId'], ['kind' => 'groups', 'groups' => ['hr']]],
			$rule['recipients']
		);
		self::assertSame("An employee's mandatory course is overdue", $rule['subject']['en']);
	}//end testTheManagerAndHrAreToldOnceItIsOverdue()

	/**
	 * An enrolment that is completed, withdrawn or failed matches neither rule; an open one matches both.
	 *
	 * @return void
	 */
	public function testOnlyAnOpenEnrolmentMatchesEitherRule(): void {
		foreach (['dueReminder', 'overdue'] as $name) {
			$rule = $this->rule($name);
			foreach (['completed', 'withdrawn', 'failed'] as $closed) {
				self::assertFalse(self::admits($rule, $closed), $name . ' still matches a ' . $closed . ' enrolment');
			}

			foreach (['pending', 'active'] as $open) {
				self::assertTrue(self::admits($rule, $open), $name . ' misses a ' . $open . ' enrolment');
			}
		}
	}//end testOnlyAnOpenEnrolmentMatchesEitherRule()

	/**
	 * Every state the rules filter on is a state an enrolment can be in.
	 *
	 * @return void
	 */
	public function testTheFilteredStatesAreEnrolmentStates(): void {
		$states = $this->schema()['properties']['lifecycle']['enum'];
		foreach (['dueReminder', 'overdue'] as $name) {
			self::assertSame([], array_diff($this->rule($name)['trigger']['filter']['lifecycle'], $states));
		}
	}//end testTheFilteredStatesAreEnrolmentStates()
}//end class
