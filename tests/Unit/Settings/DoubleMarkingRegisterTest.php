<?php

/**
 * Learniq double marking register test.
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
 * @spec openspec/changes/assignments-double-marking/specs/assignments/spec.md#requirement-an-assignment-can-ask-for-more-than-one-marker
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Pins the Assignment marker settings, the SubmissionMark schema and its
 * access rules, and the Submission audit fields.
 */
class DoubleMarkingRegisterTest extends TestCase {

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
	 * One to five markers, default one; the rule defaults to agreed by hand.
	 *
	 * @return void
	 */
	public function testAssignmentAsksForOneToFiveMarkers(): void {
		$properties = $this->schemas()['Assignment']['properties'];

		self::assertSame(['type' => 'integer', 'minimum' => 1, 'maximum' => 5, 'default' => 1], array_intersect_key($properties['markersPerSubmission'], array_flip(['type', 'minimum', 'maximum', 'default'])));
		self::assertSame(['manual', 'average', 'highest'], $properties['finalGradeRule']['enum']);
		self::assertSame('manual', $properties['finalGradeRule']['default']);
	}//end testAssignmentAsksForOneToFiveMarkers()

	/**
	 * A marker reads and edits only their own mark while it is a draft; staff
	 * create; the submit transition asks for a grade and stamps who and when.
	 *
	 * @return void
	 */
	public function testSubmissionMarkAccessAndLifecycle(): void {
		$mark = $this->schemas()['SubmissionMark'];

		self::assertSame('submission-mark', $mark['slug']);
		self::assertSame(['submissionId', 'assignmentId', 'markerId', 'tenant_id'], $mark['required']);
		self::assertSame(['compliance-officers', 'team-leads', ['group' => 'authenticated', 'match' => ['markerId' => '$userId']]], $mark['authorization']['read']);
		self::assertSame(['instructors', 'compliance-officers', 'team-leads'], $mark['authorization']['create']);
		self::assertContains(['group' => 'authenticated', 'match' => ['markerId' => '$userId', 'lifecycle' => 'draft']], $mark['authorization']['update']);
		self::assertNotContains('instructors', $mark['authorization']['read']);

		$submit = $mark['x-openregister-lifecycle']['transitions']['submit'];
		self::assertSame(['from' => 'draft', 'to' => 'submitted'], ['from' => $submit['from'], 'to' => $submit['to']]);
		self::assertSame([['field' => 'proposedGrade', 'required' => true]], $submit['inputs']);
		self::assertSame(['actorField' => 'submittedBy', 'timeField' => 'submittedAt'], $submit['actions'][0]['actionParameters']);
		self::assertArrayNotHasKey('appendOnly', $mark['x-openregister']);
	}//end testSubmissionMarkAccessAndLifecycle()

	/**
	 * The submission records its markers and who set the final grade by
	 * which rule.
	 *
	 * @return void
	 */
	public function testSubmissionRecordsMarkersAndTheFinalGradeAudit(): void {
		$properties = $this->schemas()['Submission']['properties'];

		self::assertSame([], $properties['markerIds']['default']);
		self::assertTrue($properties['finalGradeSetBy']['nullable']);
		self::assertSame(['manual', 'average', 'highest'], $properties['finalGradeRuleApplied']['enum']);
	}//end testSubmissionRecordsMarkersAndTheFinalGradeAudit()
}//end class
