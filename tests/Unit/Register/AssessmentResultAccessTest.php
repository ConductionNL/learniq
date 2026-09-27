<?php

/**
 * Learniq AssessmentResult access-rule test.
 *
 * OpenRegister enforces a schema's `authorization` block. It does not read
 * `x-property-rbac` at all, so the "learner sees own results; admins see all"
 * rule that block declared was never applied: AssessmentResult had no
 * `authorization`, and OpenRegister's default for a schema without one is to
 * let every signed-in user read every row (learniq#949). This test pins the
 * enforced rule to exactly the intended audience.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Register
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
 * @spec openspec/specs/assessment/spec.md#requirement-assessment-results-are-read-by-the-learner-their-manager-and-the-courses-teachers
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Register;

use PHPUnit\Framework\TestCase;

/**
 * Asserts the enforced read audience of AssessmentResult.
 */
class AssessmentResultAccessTest extends TestCase {

	/**
	 * The AssessmentResult schema as shipped.
	 *
	 * @return array<string, mixed>
	 */
	private function schema(): array {
		$register = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'),
			true
		);

		return $register['components']['schemas']['AssessmentResult'];
	}//end schema()

	/**
	 * Read is granted to the learner, their manager and the course's teachers,
	 * and to nobody else (admins pass OpenRegister's admin bypass).
	 *
	 * @return void
	 */
	public function testReadIsLimitedToLearnerManagerAndCourseTeachers(): void {
		$authorization = ($this->schema()['authorization'] ?? []);

		$this->assertSame(
			[
				['group' => 'authenticated', 'match' => ['learnerId' => '$userId']],
				['group' => 'authenticated', 'match' => ['managerId' => '$userId']],
				['group' => 'authenticated', 'match' => ['teacherIds' => ['$contains' => '$userId']]],
			],
			($authorization['read'] ?? null)
		);
	}//end testReadIsLimitedToLearnerManagerAndCourseTeachers()

	/**
	 * A non-empty authorization block makes every unlisted action fail closed,
	 * so the actions the take and marking screens need are listed: a learner
	 * starts their own attempt; the learner and the course's teachers update it
	 * (answers, submit, grade). Nobody but an admin deletes.
	 *
	 * @return void
	 */
	public function testWriteActionsTheScreensNeedAreListed(): void {
		$authorization = ($this->schema()['authorization'] ?? []);

		$this->assertSame(['authenticated'], ($authorization['create'] ?? null));
		$this->assertSame(
			[
				['group' => 'authenticated', 'match' => ['learnerId' => '$userId']],
				['group' => 'authenticated', 'match' => ['teacherIds' => ['$contains' => '$userId']]],
			],
			($authorization['update'] ?? null)
		);
		$this->assertArrayNotHasKey('delete', $authorization);
	}//end testWriteActionsTheScreensNeedAreListed()

	/**
	 * The audience fields exist, and are stamped by the server rather than
	 * written by the client.
	 *
	 * @return void
	 */
	public function testAudienceFieldsAreDeclaredReadOnly(): void {
		$properties = $this->schema()['properties'];

		$this->assertSame('array', ($properties['teacherIds']['type'] ?? null));
		$this->assertTrue(($properties['teacherIds']['readOnly'] ?? false));
		$this->assertSame('string', ($properties['managerId']['type'] ?? null));
		$this->assertTrue(($properties['managerId']['readOnly'] ?? false));
	}//end testAudienceFieldsAreDeclaredReadOnly()
}//end class
