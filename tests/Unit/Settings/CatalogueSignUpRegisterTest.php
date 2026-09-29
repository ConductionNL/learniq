<?php

/**
 * Learniq catalogue sign-up register test.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Settings
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
 * @spec openspec/changes/enrolment-catalogue-self-signup/specs/enrolment/spec.md#requirement-the-learner-is-told-in-words-that-fit-a-chosen-course
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Pins selfEnrolment, the request fields, the approve and decline
 * transitions, the manager's narrow write and the sign-up notifications.
 */
class CatalogueSignUpRegisterTest extends TestCase {

	/**
	 * The shipped schemas.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function schemas(): array {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'), true);

		return $register['components']['schemas'];
	}//end schemas()

	/**
	 * Course and programme are closed for sign-up unless a school opens them.
	 *
	 * @return void
	 */
	public function testCoursesAndProgrammesAreClosedByDefault(): void {
		foreach (['Course', 'Programme'] as $name) {
			$property = $this->schemas()[$name]['properties']['selfEnrolment'];
			self::assertSame(['closed', 'open', 'on-request'], $property['enum'], $name);
			self::assertSame('closed', $property['default'], $name);
		}
	}//end testCoursesAndProgrammesAreClosedByDefault()

	/**
	 * Learners still cannot create or update enrolments; a manager updates
	 * only a pending self sign-up of their own report.
	 *
	 * @return void
	 */
	public function testOnlyStaffAndTheManagerOfAPendingRequestWrite(): void {
		$authorization = $this->schemas()['Enrolment']['authorization'];

		self::assertSame(['instructors', 'hr', 'compliance-officers', 'team-leads'], $authorization['create']);
		self::assertSame(
			['group' => 'authenticated', 'match' => ['managerId' => '$userId', 'source' => 'self', 'lifecycle' => 'pending']],
			$authorization['update'][4]
		);
		self::assertCount(5, $authorization['update']);
	}//end testOnlyStaffAndTheManagerOfAPendingRequestWrite()

	/**
	 * Approve and decline move a pending request; decline needs a reason;
	 * withdraw stays the first pending-to-withdrawn transition.
	 *
	 * @return void
	 */
	public function testApproveAndDeclineMoveAPendingRequest(): void {
		$transitions = $this->schemas()['Enrolment']['x-openregister-lifecycle']['transitions'];

		self::assertSame(['pending', 'active'], [$transitions['approve']['from'], $transitions['approve']['to']]);
		self::assertSame([['field' => 'declineReason', 'required' => true]], $transitions['decline']['inputs']);
		$names = array_keys($transitions);
		self::assertLessThan(array_search('decline', $names, true), array_search('withdraw', $names, true));
	}//end testApproveAndDeclineMoveAPendingRequest()

	/**
	 * A chosen course gets its own message, fired on create, so the
	 * mandatory-course message (on `activate`) never reaches a self sign-up.
	 *
	 * @return void
	 */
	public function testAChosenCourseHasItsOwnMessage(): void {
		$notifications = $this->schemas()['Enrolment']['x-openregister-notifications'];

		self::assertSame(['type' => 'created', 'filter' => ['source' => 'self', 'lifecycle' => 'active']], $notifications['selfSignedUp']['trigger']);
		self::assertSame('You are signed up for a course', $notifications['selfSignedUp']['subject']['en']);
		self::assertSame('activate', $notifications['activated']['trigger']['action']);
		self::assertSame('approve', $notifications['signUpApproved']['trigger']['action']);
		self::assertSame('decline', $notifications['signUpDeclined']['trigger']['action']);
	}//end testAChosenCourseHasItsOwnMessage()
}//end class
