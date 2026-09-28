<?php

/**
 * Learniq declared-audience enforcement test.
 *
 * OpenRegister enforces a schema's `authorization` block and never reads
 * `x-property-rbac` (openregister#4064). Schemas that declared their audience
 * only in `x-property-rbac` fell through to the register cascade, which lets
 * the four staff groups read every row and gives a learner no read on their
 * own rows; schemas with a group-only block refused the learner the same way
 * (learniq#963). These tests pin every such schema to an enforced block that
 * says what its `x-property-rbac` intended.
 *
 * Role words in `x-property-rbac` are mapped onto the canonical groups of
 * `rbac-declare-groups`: teacher is the four teacher-view groups of
 * DashboardRoleService, mentor is team-leads, coordinator is coordinators,
 * principal is administration-managers, examboard is compliance-officers,
 * manager is team-leads plus administration-managers, finance (a group the
 * design dropped) is administration-managers, and admin is OpenRegister's
 * admin bypass, which needs no entry.
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
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-a-schemas-declared-audience-is-enforced-by-its-authorization-block
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Register;

use PHPUnit\Framework\TestCase;

/**
 * Asserts that every declared audience is an enforced one.
 */
class DeclaredAudienceEnforcedTest extends TestCase {

	private const I = 'instructors';
	private const TL = 'team-leads';
	private const C = 'coordinators';
	private const AM = 'administration-managers';
	private const HR = 'hr';
	private const CO = 'compliance-officers';

	/**
	 * The staff groups the register cascade granted create and update to.
	 */
	private const CASCADE_WRITERS = ['instructors', 'hr', 'compliance-officers', 'team-leads'];

	/**
	 * `x-property-rbac` match fields that are not a property of the schema,
	 * mapped to the property that carries the same person.
	 */
	private const FIELD_ALIASES = [
		'LearnerProfile' => ['learnerId' => 'ncUserId'],
		'Submission'     => ['learnerId' => 'learnerIds'],
	];

	/**
	 * The register as shipped.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $register = null;

	/**
	 * All schemas of the shipped register.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function schemas(): array {
		if (self::$register === null) {
			self::$register = json_decode(
				(string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'),
				true
			);
		}

		return self::$register['components']['schemas'];
	}//end schemas()

	/**
	 * A self-match entry: the caller is the person in `$field`.
	 *
	 * @param string $field The object field holding a user id.
	 *
	 * @return array<string, mixed>
	 */
	private static function self(string $field): array {
		return ['group' => 'authenticated', 'match' => [$field => '$userId']];
	}//end self()

	/**
	 * Assert the exact enforced read audience of each named schema.
	 *
	 * @param array<string, list<mixed>> $expected Schema name to read rules.
	 *
	 * @return void
	 */
	private function assertReadAudience(array $expected): void {
		$schemas = self::schemas();
		foreach ($expected as $name => $read) {
			$this->assertSame(
				$read,
				($schemas[$name]['authorization']['read'] ?? null),
				$name . ' read audience'
			);
		}
	}//end assertReadAudience()

	/**
	 * No schema declares an audience in `x-property-rbac` without an
	 * `authorization` block that OpenRegister enforces.
	 *
	 * @return void
	 */
	public function testNoSchemaDeclaresAnAudienceWithoutAnAuthorizationBlock(): void {
		$missing = [];
		foreach (self::schemas() as $name => $schema) {
			if (isset($schema['x-property-rbac']) === true && empty($schema['authorization']) === true) {
				$missing[] = $name;
			}
		}

		$this->assertSame([], $missing, 'x-property-rbac without an enforced authorization block');
	}//end testNoSchemaDeclaresAnAudienceWithoutAnAuthorizationBlock()

	/**
	 * Every "the person in field F reads this row" rule in `x-property-rbac`
	 * has an enforced read entry on the same person.
	 *
	 * @return void
	 */
	public function testEverySelfMatchIsEnforced(): void {
		$unenforced = [];
		foreach (self::schemas() as $name => $schema) {
			$read = ($schema['authorization']['read'] ?? []);
			foreach (($schema['x-property-rbac']['read']['anyOf'] ?? []) as $rule) {
				$field = ($rule['match']['field'] ?? null);
				if ($field === null) {
					continue;
				}

				$field = (self::FIELD_ALIASES[$name][$field] ?? $field);
				$value = '$userId';
				if (($schema['properties'][$field]['type'] ?? null) === 'array') {
					$value = ['$contains' => '$userId'];
				}

				if (in_array(['group' => 'authenticated', 'match' => [$field => $value]], $read, true) === false) {
					$unenforced[] = $name . '.' . $field;
				}
			}
		}

		$this->assertSame([], $unenforced, 'self-match rules without an enforced read entry');
	}//end testEverySelfMatchIsEnforced()

	/**
	 * A match can only compare a field on the row, so every matched field is
	 * a property of its schema; a misspelt field would match nobody.
	 *
	 * @return void
	 */
	public function testEveryMatchedFieldIsAPropertyOfItsSchema(): void {
		$unknown = [];
		foreach (self::schemas() as $name => $schema) {
			foreach (($schema['authorization'] ?? []) as $action => $rules) {
				foreach ((array)$rules as $rule) {
					foreach (array_keys(($rule['match'] ?? [])) as $field) {
						if (isset($schema['properties'][$field]) === false) {
							$unknown[] = $name . '.' . $action . '.' . $field;
						}
					}
				}
			}
		}

		$this->assertSame([], $unknown);
	}//end testEveryMatchedFieldIsAPropertyOfItsSchema()

	/**
	 * Every group an authorization block names is one the register declares
	 * (and so provisions), or the `authenticated` pseudo-group.
	 *
	 * @return void
	 */
	public function testEveryGroupIsDeclared(): void {
		self::schemas();
		$declared = array_keys(self::$register['components']['securitySchemes']['oauth2']['flows']['authorizationCode']['scopes']);
		$declared[] = 'authenticated';

		$undeclared = [];
		foreach (self::schemas() as $name => $schema) {
			foreach (($schema['authorization'] ?? []) as $rules) {
				foreach ((array)$rules as $rule) {
					$group = $rule;
					if (is_array($rule) === true) {
						$group = ($rule['group'] ?? '');
					}

					if (in_array($group, $declared, true) === false) {
						$undeclared[] = $name . ':' . $group;
					}
				}
			}
		}

		$this->assertSame([], $undeclared);
	}//end testEveryGroupIsDeclared()

	/**
	 * Learner-attributed teaching records: the learner reads their own rows,
	 * teaching staff read them for rosters, gradebooks and progress views.
	 * Enrolment, GradeEntry and FinalGrade add instructors and team-leads
	 * beyond their declared audience because CohortGradebookView and
	 * GradeImpactDetail read them; Enrolment keeps hr and compliance-officers
	 * for the admin dashboard; ReportCard adds instructors for the teacher
	 * comment in RapportvergaderingReviewView; EngagementRiskFlag is read by
	 * the teacher-view groups because the teacher dashboard counts flags.
	 *
	 * @return void
	 */
	public function testTeachingRecordsAreReadByTheLearnerAndTeachingStaff(): void {
		$this->assertReadAudience(
			[
				'LessonCompletion'     => [self::I, self::TL, self::C, self::AM, self::self('learnerId')],
				'Enrolment'            => [self::I, self::TL, self::HR, self::CO, self::self('learnerId'), self::self('managerId')],
				'GradeEntry'           => [self::I, self::TL, self::CO, self::self('learnerId')],
				'FinalGrade'           => [self::I, self::TL, self::CO, self::self('learnerId')],
				'ReportCard'           => [self::I, self::TL, self::AM, self::self('learnerId')],
				'CompetencyAttainment' => [self::HR, self::TL, self::AM, self::self('learnerId')],
				'EngagementScore'      => [self::I, self::TL, self::C, self::AM, self::self('learnerId')],
				'EngagementRiskFlag'   => [self::I, self::TL, self::C, self::AM, self::self('learnerId')],
			]
		);
	}//end testTeachingRecordsAreReadByTheLearnerAndTeachingStaff()

	/**
	 * Item and reliability statistics are staff-only: a learner must not see
	 * which items are safe to guess on.
	 *
	 * @return void
	 */
	public function testAssessmentAnalyticsAreStaffOnly(): void {
		$staff = [self::I, self::TL, self::C, self::AM, self::CO];
		$this->assertReadAudience(
			[
				'ItemStatistics'        => $staff,
				'AssessmentReliability' => $staff,
				'ItemRevisionFlag'      => $staff,
			]
		);
	}//end testAssessmentAnalyticsAreStaffOnly()

	/**
	 * Care, exchange and planning records are read by the named roles only.
	 * ExchangeRejection adds coordinators, the group the waive and resubmit
	 * guards authorise, since they act on the rejection.
	 *
	 * @return void
	 */
	public function testCareExchangeAndPlanningRecordsAreRestricted(): void {
		$this->assertReadAudience(
			[
				'ExamAccommodation'  => [self::CO, self::TL, self::self('learnerId'), self::self('submittedBy')],
				'SupportRequest'     => [self::AM, self::self('raisedBy')],
				'DeliberationRecord' => [self::AM],
				// data-exchange-to-integriq: rejections are integriq dead letters; the
				// exchange gate's own records are staff-only, the parent review adds
				// guardians and the learner the row is about.
				'ExchangePartnerApproval' => [self::AM, self::CO, self::C],
				'TeldatumCheck'           => [self::CO, self::AM],
				'DossierReview'           => [self::AM, self::C, 'guardians', self::self('learnerUserId')],
				'TimetableConflict'  => [self::C],
				'RolloverPlan'       => [self::C],
			]
		);
	}//end testCareExchangeAndPlanningRecordsAreRestricted()

	/**
	 * A portfolio, a learning record and engagement points are the learner's.
	 * Teaching staff also read portfolios and entries, because the grading
	 * teacher reviews a submitted course-bound portfolio in PortfolioReviewView.
	 *
	 * @return void
	 */
	public function testPortfolioAndLearningRecordBelongToTheLearner(): void {
		$this->assertReadAudience(
			[
				'Portfolio'            => [self::I, self::TL, self::self('learnerId')],
				'PortfolioEntry'       => [self::I, self::TL, self::self('learnerId')],
				'LearnerEngagement'    => [self::self('learnerId')],
				'LearningRecordExport' => [self::HR, self::TL, self::AM, self::self('learnerId')],
				'LearningRecordShare'  => [self::HR, self::TL, self::AM, self::self('learnerId')],
			]
		);
	}//end testPortfolioAndLearningRecordBelongToTheLearner()

	/**
	 * Entitlements are read by the learner and administration. Orders and
	 * payment transactions left learniq for shillinq (D19).
	 *
	 * @return void
	 */
	public function testPaymentsAreReadByPayerLearnerAndAdministration(): void {
		$this->assertReadAudience(
			[
				'Entitlement' => [self::AM, self::self('learnerId')],
			]
		);
	}//end testPaymentsAreReadByPayerLearnerAndAdministration()

	/**
	 * Schemas that had a group-only block keep their groups and add the
	 * person the row is about, so a learner can read their own row.
	 *
	 * @return void
	 */
	public function testGroupOnlyBlocksAddThePersonTheRowIsAbout(): void {
		$staff = ['compliance-officers', 'team-leads'];
		$this->assertReadAudience(
			[
				'Credential'             => ['hr', 'compliance-officers', self::self('learnerId')],
				'ExternalTrainingRecord' => ['hr', 'compliance-officers', self::self('learnerId'), self::self('submittedBy')],
				'LearnerProfile'         => ['instructors', 'hr', 'compliance-officers', self::self('ncUserId')],
				'Submission'             => ['instructors', ...$staff, ['group' => 'authenticated', 'match' => ['learnerIds' => ['$contains' => '$userId']]]],
				'PeerReview'             => [...$staff, self::self('reviewerId')],
				'SelfAssessment'         => [...$staff, self::self('learnerId')],
				'ExemptionCase'          => ['instructors', 'compliance-officers', self::self('learnerId')],
				'FraudCase'              => ['instructors', 'compliance-officers', self::self('accusedLearnerId'), self::self('reporterId')],
				'DossierNote'            => ['instructors', 'compliance-officers', self::self('authorId')],
				'BehaviourIncident'      => ['instructors', 'compliance-officers', self::self('reportedBy')],
				'WellbeingCheckIn'       => ['instructors', 'compliance-officers', self::self('learnerId')],
				'BsaDecision'            => [...$staff, self::self('learnerId')],
			]
		);
	}//end testGroupOnlyBlocksAddThePersonTheRowIsAbout()

	/**
	 * Writes are not what `x-property-rbac` speaks to, so the 25 schemas that
	 * fell through to the register cascade keep exactly the create and update
	 * grants the cascade gave them, and nobody but an admin deletes. The four
	 * portfolio and learning-record schemas add the learner's own writes after
	 * the staff grants (learniq#981), pinned in LearnerTransitionAccessTest.
	 *
	 * @return void
	 */
	public function testWritesKeepTheCascadeGrants(): void {
		$learnerWrites = ['Portfolio', 'PortfolioEntry', 'LearningRecordExport', 'LearningRecordShare'];
		$names = [
			'LessonCompletion', 'Enrolment', 'RolloverPlan', 'TimetableConflict', 'ExamAccommodation',
			'ItemStatistics', 'AssessmentReliability', 'ItemRevisionFlag', 'GradeEntry', 'FinalGrade',
			'ReportCard', 'CompetencyAttainment', 'SupportRequest', 'DeliberationRecord', 'EngagementScore',
			'EngagementRiskFlag', 'Portfolio', 'PortfolioEntry', 'LearningRecordExport',
			'LearningRecordShare', 'LearnerEngagement', 'Entitlement',
		];
		$schemas = self::schemas();
		foreach ($names as $name) {
			$authorization = ($schemas[$name]['authorization'] ?? []);
			$create = ($authorization['create'] ?? []);
			$update = ($authorization['update'] ?? []);
			if (in_array($name, $learnerWrites, true) === true) {
				$create = array_slice($create, 0, count(self::CASCADE_WRITERS));
				$update = array_slice($update, 0, count(self::CASCADE_WRITERS));
			}

			$this->assertSame(self::CASCADE_WRITERS, $create, $name . ' create');
			$this->assertSame(self::CASCADE_WRITERS, $update, $name . ' update');
			$this->assertArrayNotHasKey('delete', $authorization, $name . ' delete');
		}
	}//end testWritesKeepTheCascadeGrants()
}//end class
