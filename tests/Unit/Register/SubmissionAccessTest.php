<?php

/**
 * Learniq Submission access-rule test.
 *
 * The hand-in screen (SubmitWorkView) creates a draft Submission as the
 * learner, attaches files, saves the references and fires `submit`. Each step
 * needs a grant: create, then update on the learner's own draft (OpenRegister
 * checks update for the file upload, the save and the transition). The block
 * granted create and update to compliance-officers and team-leads only, so the
 * server refused every learner. The marking screen (MarkSubmissionView) is a
 * teacher's, and instructors had no grant either.
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
 * @spec openspec/specs/assignments/spec.md#requirement-a-learner-hands-in-their-own-work-and-the-teacher-marks-it
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Register;

use PHPUnit\Framework\TestCase;

/**
 * Asserts the Submission grants the hand-in and marking screens need.
 */
class SubmissionAccessTest extends TestCase {

	/**
	 * The Submission authorization block as shipped.
	 *
	 * @return array<string, mixed>
	 */
	private function authorization(): array {
		$register = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'),
			true
		);

		return ($register['components']['schemas']['Submission']['authorization'] ?? []);
	}//end authorization()

	/**
	 * A signed-in learner may create a submission. OpenRegister checks create
	 * without the object, so a match cannot narrow it; the submit guard
	 * refuses a hand-in by anyone not named on it.
	 *
	 * @return void
	 */
	public function testALearnerMayCreateASubmission(): void {
		$this->assertContains('authenticated', ($this->authorization()['create'] ?? []));
	}//end testALearnerMayCreateASubmission()

	/**
	 * A learner named on the submission may update it while it is a draft:
	 * the file upload, the save of the references and the submit transition.
	 * Once submitted, only staff update it (feedback, rubric, grade).
	 *
	 * @return void
	 */
	public function testALearnerMayUpdateTheirOwnDraftOnly(): void {
		$this->assertContains(
			[
				'group' => 'authenticated',
				'match' => [
					'learnerIds' => ['$contains' => '$userId'],
					'lifecycle'  => 'draft',
				],
			],
			($this->authorization()['update'] ?? [])
		);
	}//end testALearnerMayUpdateTheirOwnDraftOnly()

	/**
	 * Teachers read and mark submissions.
	 *
	 * @return void
	 */
	public function testInstructorsReadAndMark(): void {
		$authorization = $this->authorization();

		$this->assertContains('instructors', ($authorization['read'] ?? []));
		$this->assertContains('instructors', ($authorization['update'] ?? []));
	}//end testInstructorsReadAndMark()
}//end class
