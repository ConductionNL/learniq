<?php

/**
 * Unit tests for the Learniq PortalContributionProvider.
 *
 * Pins the ADR-046 contribution contract v2: the dual v2/v1 audience
 * declaration, the fail-closed null for unserved audiences, and the exact
 * declarative manifest shape for the `student` and `parent` audiences
 * (UUID-domain-ref-scoped collections + inbox + strict create whitelists). The
 * provider is constructed directly — it is a plain dependency-free class by
 * contract (amendment A1), so no mocks and no container are involved.
 *
 * A register-drift pin (testManifestMatchesRegisterSchemas) loads the shipped
 * `learniq_register.json` and asserts every schema slug, scope field,
 * whitelisted field AND parent `via` scope field the manifest references
 * actually exists — so a rename in the register (or a missing `portal-identity`
 * ref) fails this test instead of silently breaking the portal at runtime. The
 * `parent` reverse-join collections are covered now that portaliq ships the
 * reverse / scope-value `via` join (`match: 'scopeField'`).
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Portal;

use OCA\Learniq\Portal\PortalContributionProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PortalContributionProvider.
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */
class PortalContributionProviderTest extends TestCase {

	/**
	 * The collections the parent record page adds (portal-parent-child-record),
	 * asserted in ParentRecordPageTest.
	 */
	private const RECORD_PAGE_COLLECTIONS = ['parentAttendanceSummary', 'parentHomework', 'parentSubmissions', 'parentSchoolEvents', 'parentSchoolCalendar'];

	/**
	 * The provider under test.
	 *
	 * @var PortalContributionProvider
	 */
	private PortalContributionProvider $provider;

	/**
	 * A fully server-derived student subject, as portaliq's auth edge builds it.
	 *
	 * @var array<string, mixed>
	 */
	private const STUDENT_SUBJECT = [
		'subjectRef' => '11111111-1111-1111-1111-111111111111',
		'audience' => 'student',
		'organisation' => '00000000-0000-0000-0000-000000000000',
		'trust' => 'low',
	];

	/**
	 * A fully server-derived parent (guardian) subject.
	 *
	 * @var array<string, mixed>
	 */
	private const PARENT_SUBJECT = [
		'subjectRef' => '22222222-2222-2222-2222-222222222222',
		'audience' => 'parent',
		'organisation' => '00000000-0000-0000-0000-000000000000',
		'trust' => 'substantial',
	];

	/**
	 * A fully server-derived praktijkopleider (workplace supervisor) subject.
	 *
	 * @var array<string, mixed>
	 */
	private const PRAKTIJKOPLEIDER_SUBJECT = [
		'subjectRef' => '33333333-3333-3333-3333-333333333333',
		'audience' => 'praktijkopleider',
		'organisation' => '00000000-0000-0000-0000-000000000000',
		'trust' => 'substantial',
	];

	/**
	 * A fully server-derived external-assessor (eportfolio) subject.
	 *
	 * @var array<string, mixed>
	 */
	private const EXTERNAL_ASSESSOR_SUBJECT = [
		'subjectRef' => '44444444-4444-4444-4444-444444444444',
		'audience' => 'external-assessor',
		'organisation' => '00000000-0000-0000-0000-000000000000',
		'trust' => 'low',
	];

	/**
	 * Set up the provider — direct construction, no dependencies by contract.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->provider = new PortalContributionProvider();

	}//end setUp()

	/**
	 * The class is plain: no interfaces, no parent, and no required
	 * constructor deps. Its one optional dependency is Nextcloud's own l10n
	 * factory (never a portaliq class), so `new` with no arguments still
	 * builds an inert, English provider.
	 *
	 * @return void
	 */
	public function testClassIsPlainAndDependencyFree(): void {
		$reflection = new \ReflectionClass(PortalContributionProvider::class);

		$this->assertSame([], $reflection->getInterfaceNames());
		$this->assertFalse($reflection->getParentClass());
		$constructor = $reflection->getConstructor();
		$this->assertNotNull($constructor);
		$this->assertSame(0, $constructor->getNumberOfRequiredParameters());
		foreach ($constructor->getParameters() as $parameter) {
			$this->assertTrue($parameter->allowsNull());
			$this->assertStringStartsWith('OCP\\', (string) $parameter->getType()?->getName());
		}

	}//end testClassIsPlainAndDependencyFree()

	/**
	 * getAudiences() (v2) returns exactly ['student','parent','praktijkopleider',
	 * 'external-assessor'] and getAudience() (v1 fallback) is one of them. The `parent`
	 * audience is re-enabled now that portaliq ships the reverse / scope-value `via` join
	 * (match: 'scopeField'); `praktijkopleider` is the bpv-praktijkovereenkomst change's third
	 * audience; `external-assessor` is the eportfolio change's fourth audience.
	 *
	 * @return void
	 */
	public function testAudienceContract(): void {
		$this->assertSame(
			['student', 'parent', 'praktijkopleider', 'external-assessor'],
			$this->provider->getAudiences()
		);
		$this->assertSame('student', $this->provider->getAudience());
		$this->assertContains($this->provider->getAudience(), $this->provider->getAudiences());

	}//end testAudienceContract()

	/**
	 * Unserved / absent audiences get null — fail-closed audience filtering.
	 * `student`, `parent`, `praktijkopleider` and `external-assessor` are served; everything
	 * else (and an empty subject) is null.
	 *
	 * @return void
	 */
	public function testGetContributionReturnsNullForUnservedSubjects(): void {
		$teacher = self::STUDENT_SUBJECT;
		$teacher['audience'] = 'teacher';

		$this->assertNull($this->provider->getContribution($teacher));
		$this->assertNull($this->provider->getContribution([]));

		// `parent`, `praktijkopleider` and `external-assessor` are served audiences — they
		// return a manifest, not null.
		$this->assertIsArray($this->provider->getContribution(self::PARENT_SUBJECT));
		$this->assertIsArray($this->provider->getContribution(self::PRAKTIJKOPLEIDER_SUBJECT));
		$this->assertIsArray($this->provider->getContribution(self::EXTERNAL_ASSESSOR_SUBJECT));

	}//end testGetContributionReturnsNullForUnservedSubjects()

	/**
	 * The student manifest is labelled and carries all four sections, with the
	 * ten learner-scoped read collections plus the inbox.
	 *
	 * @return void
	 */
	public function testStudentManifestShape(): void {
		$manifest = $this->provider->getContribution(self::STUDENT_SUBJECT);

		$this->assertIsArray($manifest);
		$this->assertSame('Learniq', $manifest['label']);
		$this->assertSame([], $manifest['notifications']);

		$collections = $manifest['collections'];
		$this->assertCount(13, $collections);
		$this->assertSame(
			[
				// site-pupil-portal-design T1: her timetable, first so its page follows the overview.
				'studentSessions',
				'studentGrades',
				'studentFinalGrades',
				'studentAttendance',
				'studentEnrolments',
				'studentSubmissions',
				// internship-hours: her own placement, and one row per week of
				// hours with both numbers on it.
				'studentBpvPlacements',
				'studentHourWeeks',
				'studentExcuseRequests',
				'studentInbox',
				'studentTests',
				'studentHomework',
				// site-pupil-portal-design: the absence strip of her overview.
				'studentAttendanceSummary',
			],
			array_column($collections, 'id')
		);

		foreach ($collections as $collection) {
			$this->assertSame('learniq', $collection['register']);
			$this->assertSame('learnerRef', $collection['scopeClaim']);
			$this->assertNotEmpty($collection['fields']);
			if ($collection['id'] === 'studentHomework') {
				// The pupil's uuid is one of the group's pupils on the
				// assignment; portaliq matches list membership (portaliq#750).
				// The list itself is never projected.
				$this->assertSame('learnerRefs', $collection['scopeField']);
				$this->assertNotContains('learnerRefs', $collection['fields']);
				$this->assertSame(['lifecycle' => 'published'], $collection['filter']);
				continue;
			}

			if ($collection['id'] === 'studentSessions') {
				// A lesson belongs to her group: reached through her own live
				// enrolments, never by a field on the lesson (StudentTimetableTest).
				$this->assertSame('cohortId', $collection['scopeField']);
				$this->assertSame('learnerRef', $collection['via']['scopeField']);
				continue;
			}

			// Every other collection, Submission included, is scoped by the
			// scalar learnerRef (assignment-portal-wiring).
			$this->assertSame('learnerRef', $collection['scopeField']);
		}

	}//end testStudentManifestShape()

	/**
	 * The student inbox is a `kind: inbox` collection scoped by learnerRef.
	 *
	 * @return void
	 */
	public function testStudentInboxIsScopedInbox(): void {
		$manifest = $this->provider->getContribution(self::STUDENT_SUBJECT);
		$inbox = array_values(
			array_filter(
				$manifest['collections'],
				static fn (array $c): bool => ($c['id'] ?? '') === 'studentInbox'
			)
		)[0];

		$this->assertSame('inbox', $inbox['kind']);
		$this->assertSame('grade-notification', $inbox['schema']);
		$this->assertSame('learnerRef', $inbox['scopeField']);

	}//end testStudentInboxIsScopedInbox()

	/**
	 * Student create-actions whitelist intake fields only — no grade, status,
	 * lifecycle or staff field is exposed.
	 *
	 * @return void
	 */
	public function testStudentCreateActionsWhitelistIntakeFields(): void {
		$manifest = $this->provider->getContribution(self::STUDENT_SUBJECT);
		$actions = $manifest['actions'];

		$this->assertSame(
			['createSubmission', 'submitHourWeek', 'createExcuseRequest', 'listTests', 'startTest', 'saveTestAnswer', 'submitTest', 'readTestResult', 'handIn', 'listCatalogue', 'signUpForCourse', 'withdrawSignUp', 'listWorkGroups', 'joinWorkGroup', 'leaveWorkGroup', 'checkIn'],
			array_column($actions, 'id')
		);
		$byId = array_column($actions, null, 'id');

		$submission = $byId['createSubmission'];
		$this->assertSame('create', $submission['type']);
		$this->assertSame('submission', $submission['schema']);
		$this->assertSame('learnerRef', $submission['scopeField']);
		$this->assertSame(['assignmentId', 'attachmentRefs'], $submission['fields']);

		// internship-hours: she enters a week of her own placement's hours and
		// nothing else. Who she is, when she sent it, the hours her trainer
		// approved and the state are all server-written.
		$hours = $byId['submitHourWeek'];
		$this->assertSame('create', $hours['type']);
		$this->assertSame('bpv-hour-week', $hours['schema']);
		$this->assertSame('learnerRef', $hours['scopeField']);
		$this->assertSame('low', $hours['minTrust']);
		$this->assertSame(['bpvPlacementId', 'isoWeek', 'hoursSubmitted'], $hours['fields']);
		foreach (['learnerRef', 'submittedBy', 'submittedAt', 'hoursApproved', 'approvedBy', 'approvedByName', 'assuranceLevel', 'lifecycle', 'tenant_id'] as $server) {
			$this->assertNotContains($server, $hours['fields'], $server);
		}

		$excuse = $byId['createExcuseRequest'];
		$this->assertSame('create', $excuse['type']);
		$this->assertSame('excuse-request', $excuse['schema']);
		$this->assertSame('learnerRef', $excuse['scopeField']);
		$this->assertSame('low', $excuse['minTrust']);
		$this->assertSame(
			['dateFrom', 'dateTo', 'reason', 'reasonKind', 'attachmentRef'],
			$excuse['fields']
		);
		// A student create never lets the client set grade/status/staff fields.
		foreach (['value', 'passed', 'lifecycle', 'submittedBy', 'submittedAuthLevel', 'decidedBy'] as $forbidden) {
			$this->assertNotContains($forbidden, $excuse['fields']);
			$this->assertNotContains($forbidden, $submission['fields']);
		}

	}//end testStudentCreateActionsWhitelistIntakeFields()

	/**
	 * The hand-in of a draft (portal-assignment-hand-in-endpoint): an
	 * instance-local POST that stamps `learnerRef`, offered as a row action on
	 * `studentSubmissions` for drafts only, with the row id stamped under
	 * `submissionId`.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-contribution/spec.md#requirement-a-pupil-hands-in-a-draft-submission-from-the-portal-req-pcon-009
	 */
	public function testStudentSubmissionsOffersTheHandInOnDrafts(): void {
		$manifest = $this->provider->getContribution(self::STUDENT_SUBJECT);
		$handIn = array_values(array_filter($manifest['actions'], static fn (array $a): bool => ($a['id'] ?? '') === 'handIn'))[0];
		$submissions = array_values(array_filter($manifest['collections'], static fn (array $c): bool => ($c['id'] ?? '') === 'studentSubmissions'))[0];

		$this->assertSame('endpoint-forward', $handIn['type']);
		$this->assertSame('/apps/learniq/api/portal/submissions/hand-in', $handIn['endpoint']);
		$this->assertSame('POST', $handIn['method']);
		$this->assertSame('low', $handIn['minTrust']);
		$this->assertSame(['submissionId'], $handIn['fields']);
		$this->assertSame('learnerRef', $handIn['subjectField']);
		$this->assertSame('learnerRef', $handIn['scopeClaim']);
		$this->assertSame('submissionId', $handIn['rowField']);
		$this->assertSame(['field' => 'lifecycle', 'in' => ['draft']], $handIn['rowWhen']);
		$this->assertSame(['handIn'], $submissions['rowActions']);
		// The row carries the field rowWhen reads, or portaliq would never offer it.
		$this->assertContains('lifecycle', $submissions['fields']);
	}//end testStudentSubmissionsOffersTheHandInOnDrafts()

	/**
	 * The hand-in declares portaliq's file field on attachmentRefs, inside the
	 * limits ConductionNL/portaliq#745's FileFieldConfigNormaliser keeps: a
	 * create action, the field in `fields`, `type: file`, a boolean `multiple`,
	 * at most 20 `accept` entries and `maxSizeMb` from 1 to 50. Anything outside
	 * those limits portaliq drops fail-closed, and the pupil gets a text box.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-contribution/spec.md#requirement-a-pupil-hands-in-work-through-the-portal-with-a-real-file-req-pcon-007
	 */
	public function testSubmissionHandInDeclaresAFileField(): void {
		$manifest = $this->provider->getContribution(self::STUDENT_SUBJECT);
		$submission = array_values(
			array_filter(
				$manifest['actions'],
				static fn (array $a): bool => ($a['id'] ?? '') === 'createSubmission'
			)
		)[0];

		$this->assertSame('create', $submission['type']);
		$this->assertSame('low', $submission['minTrust']);
		$this->assertSame('learnerRef', $submission['scopeClaim']);
		$this->assertArrayHasKey('fieldConfigs', $submission);
		// Every field she is asked for carries a label, the file field included
		// (pupil-flows.spec.ts found the form drawn with its field names).
		$this->assertSame(['assignmentId', 'attachmentRefs'], array_keys($submission['fieldConfigs']));

		$file = $submission['fieldConfigs']['attachmentRefs'];
		$this->assertContains('attachmentRefs', $submission['fields']);
		$this->assertSame('file', $file['type']);
		$this->assertTrue($file['multiple']);
		$this->assertSame(20, $file['maxSizeMb']);
		$this->assertGreaterThanOrEqual(1, $file['maxSizeMb']);
		$this->assertLessThanOrEqual(50, $file['maxSizeMb']);
		$this->assertNotEmpty($file['accept']);
		$this->assertLessThanOrEqual(20, count($file['accept']));
		foreach ($file['accept'] as $accepted) {
			$this->assertMatchesRegularExpression('/^\.[a-z0-9]+$/', $accepted);
		}

		$this->assertNotSame('', trim((string)$file['label']));

	}//end testSubmissionHandInDeclaresAFileField()

	/**
	 * Both absence reports declare their attachment as portaliq's file field.
	 * Without `type: file` portaliq renders `attachmentRef` as a text box and
	 * the guardian can only type a name. The property is a string, so the
	 * field takes one file, inside FileFieldConfigNormaliser's limits.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-contribution/spec.md#requirement-the-parent-audience-can-report-a-childs-absence-validated-against-the-callers-own-children-req-pcon-007
	 */
	public function testAbsenceReportAttachmentIsAFileField(): void {
		$byAudience = [
			'parent'  => $this->provider->getContribution(self::PARENT_SUBJECT),
			'student' => $this->provider->getContribution(self::STUDENT_SUBJECT),
		];

		foreach ($byAudience as $audience => $manifest) {
			$excuse = array_values(
				array_filter(
					$manifest['actions'],
					static fn (array $a): bool => ($a['id'] ?? '') === 'createExcuseRequest'
				)
			)[0];

			$this->assertContains('attachmentRef', $excuse['fields'], $audience);
			$file = $excuse['fieldConfigs']['attachmentRef'];
			$this->assertSame('file', $file['type'], $audience.': attachmentRef must be a file field, not a text box');
			$this->assertFalse($file['multiple'], $audience.': attachmentRef is a string property, one file');
			$this->assertSame('Attachment', $file['label'], $audience);
			$this->assertGreaterThanOrEqual(1, $file['maxSizeMb']);
			$this->assertLessThanOrEqual(50, $file['maxSizeMb']);
			$this->assertContains('.pdf', $file['accept']);
			$this->assertLessThanOrEqual(20, count($file['accept']));
			foreach ($file['accept'] as $accepted) {
				$this->assertMatchesRegularExpression('/^\.[a-z0-9]+$/', $accepted);
			}
		}

		// The parent form keeps its other field configs next to the file field.
		$parent = $byAudience['parent']['actions'][0];
		$this->assertTrue($parent['fieldConfigs']['learnerRef']['required']);

	}//end testAbsenceReportAttachmentIsAFileField()

	/**
	 * studentTests is a timed task over the learner's own attempts: it names
	 * five instance-local POST actions, each stamping learnerRef from the
	 * server, and exposes no response or score.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-contribution/spec.md#requirement-a-pupil-takes-a-timed-test-through-the-portal-req-pcon-008
	 */
	public function testStudentTestsIsATimedTask(): void {
		$manifest = $this->provider->getContribution(self::STUDENT_SUBJECT);
		$tests = array_values(array_filter($manifest['collections'], static fn (array $c): bool => ($c['id'] ?? '') === 'studentTests'))[0];
		$actions = array_column($manifest['actions'], null, 'id');

		$this->assertSame('timedTask', $tests['kind']);
		$this->assertSame('assessment-result', $tests['schema']);
		$this->assertSame('learnerRef', $tests['scopeField']);
		foreach (['responses', 'autoScore', 'manualScore', 'drawnItemRefs', 'accessCode', 'teacherIds', 'managerId'] as $hidden) {
			$this->assertNotContains($hidden, $tests['fields']);
		}

		$this->assertSame(['available', 'start', 'answer', 'submit', 'result'], array_keys($tests['timedTask']));
		foreach ($tests['timedTask'] as $step => $actionId) {
			$this->assertArrayHasKey($actionId, $actions, $step);
			$action = $actions[$actionId];
			$this->assertSame('POST', $action['method']);
			$this->assertStringStartsWith('/apps/learniq/api/portal/assessments', $action['endpoint']);
			$this->assertStringNotContainsString('://', $action['endpoint']);
			$this->assertSame('learnerRef', $action['subjectField']);
			$this->assertSame('learnerRef', $action['scopeClaim']);
			$this->assertSame('low', $action['minTrust']);
			$this->assertNotContains('learnerRef', $action['fields']);
			$this->assertNotContains('learnerId', $action['fields']);
		}

		$this->assertSame(['attemptId', 'itemId', 'response'], $actions['saveTestAnswer']['fields']);
		$this->assertSame(['taskId', 'accessCode'], $actions['startTest']['fields']);

	}//end testStudentTestsIsATimedTask()

	/**
	 * The parent manifest is labelled and carries exactly the three
	 * reverse-joined read collections (grades, attendance, excuse-requests),
	 * each guardian-claimed, learnerRef-scoped and substantial-trust,
	 * field-projected identically to the student surface.
	 *
	 * @return void
	 */
	public function testParentManifestShape(): void {
		$manifest = $this->provider->getContribution(self::PARENT_SUBJECT);

		$this->assertIsArray($manifest);
		$this->assertSame('School', $manifest['label']);
		$this->assertSame(['conference.answered'], array_column($manifest['notifications'], 'ruleKey'), 'one rule: the teacher answered a booking');

		$collections = $manifest['collections'];
		$this->assertCount(19, $collections);
		$this->assertSame(
			['parentChildren', 'parentGrades', 'parentAttendance', 'parentReportCardGrades', 'parentExcuseRequests', 'parentReportCards', 'parentConferenceRounds', 'parentConferenceFreeSlots', 'parentConferenceSignups', 'parentConferenceSlots', 'parentGroupMemberships', 'parentReportSubjectGrades', 'parentInbox', 'parentGradeInbox', 'parentAttendanceSummary', 'parentHomework', 'parentSubmissions', 'parentSchoolEvents', 'parentSchoolCalendar'],
			array_column($collections, 'id')
		);

		$byId = array_column($collections, null, 'id');

		// parentChildren is a direct match (no via — see
		// testParentChildrenCollectionMatchesDirectly), so it is excluded from
		// this reverse-join-shaped assertion loop.
		// parentConferenceRounds matches a round on its list of invited
		// children and is asserted in
		// testParentBooksAConferenceForTheirOwnChildOnly; parentConferenceFreeSlots
		// matches a free time on the pupils who may book it
		// (ParentConferenceDirectBookingTest).
		$reverseJoinedCollections = array_filter($collections, static fn ($c) => in_array($c['id'], ['parentChildren', 'parentConferenceRounds', 'parentConferenceFreeSlots', ...self::RECORD_PAGE_COLLECTIONS], true) === false);
		foreach ($reverseJoinedCollections as $collection) {
			$this->assertSame('learniq', $collection['register']);
			// Parent scope key is the guardian claim; the outer record scope
			// field is the child's learnerRef (matched by the reverse via).
			$this->assertSame('guardianRef', $collection['scopeClaim']);
			$this->assertSame('learnerRef', $collection['scopeField']);
			// A guardian reading a MINOR's data needs substantial assurance.
			$this->assertSame('substantial', $collection['minTrust']);
			$this->assertNotEmpty($collection['fields']);
			// Portal-contribution-guardian-audiences: a portal groups these
			// per child without a schema change.
			$this->assertSame('learnerRef', $collection['groupByField']);
			// Parent reads never expose staff-only columns (same drop as student).
			// Who decided an absence report is read only as a name
			// (ParentTeacherNamesTest), never as a user id.
			foreach (['grader', 'comment', 'markedBy', 'submittedBy', 'submittedByRef', 'decisionNote'] as $forbidden) {
				$this->assertNotContains($forbidden, $collection['fields']);
			}
		}

		// Parent grade/attendance/excuse projections mirror the student ones.
		// site-guardian-portal-design: a grade names its subject, its test and its weight.
		$gradeFields = ['learnerRef', 'courseId', 'courseName', 'methodName', 'methodBlock', 'weight', 'curriculumPlanId', 'componentId', 'value', 'gradeScaleId', 'period', 'gradedAt'];
		$this->assertSame($gradeFields, $byId['parentGrades']['fields']);
		$student = [];
		foreach ((new PortalContributionProvider())->getContribution(['audience' => 'student'])['collections'] as $collection) {
			$student[$collection['id']] = $collection;
		}

		$this->assertSame($gradeFields, $student['studentGrades']['fields']);
		// Every projected grade field is declared by the shipped GradeEntry schema.
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'), true);
		$declared = array_keys($register['components']['schemas']['GradeEntry']['properties']);
		$this->assertSame([], array_values(array_diff($gradeFields, $declared)));
		$this->assertSame(
			['learnerRef', 'sessionId', 'cohortId', 'status', 'minutesAttended', 'markedAt'],
			$byId['parentAttendance']['fields']
		);
		$this->assertSame(
			['learnerRef', 'dateFrom', 'dateTo', 'reason', 'reasonKind', 'attachmentRef', 'lifecycle', 'decidedAt', 'decidedBy'],
			$byId['parentExcuseRequests']['fields']
		);
		// The newest absence first, on a field the collection projects (the
		// portal drops a sort on a field it does not hand out).
		$this->assertSame(['field' => 'dateFrom', 'direction' => 'desc'], $byId['parentExcuseRequests']['defaultSort']);
		$this->assertContains('dateFrom', $byId['parentExcuseRequests']['fields']);
		$this->assertSame(
			['learnerRef', 'reportPeriodId', 'periodName', 'gradeLines', 'attendanceSummary', 'mentorComment', 'docudeskDocumentRef'],
			$byId['parentReportCards']['fields']
		);
		// The report cards show the readable period and grade lines, never the
		// nested subjectGrades with its uuids.
		$this->assertSame(
			[
				['field' => 'periodName', 'label' => 'Period'],
				['field' => 'mentorComment', 'label' => "Teacher's comment"],
				['field' => 'gradeLines', 'label' => 'Grades'],
			],
			$byId['parentReportCards']['columns']
		);

		// parentReportCards is server-side narrowed to published-to-parents only
		// (report-card-composer's own "never draft/rapportvergadering-review/
		// finalised" requirement) — the reader's singular `filter` key, applied
		// BEFORE the scope filter (ContributionController::collection()).
		$this->assertSame(['lifecycle' => 'published-to-parents'], $byId['parentReportCards']['filter']);

	}//end testParentManifestShape()

	/**
	 * A primary school records no grade entries, only report cards. The
	 * guardian reads the grades on the child's report cards through the same
	 * reverse join and behind the same lifecycle filter as parentReportCards,
	 * so a draft or a card in review never reaches her. The columns are the
	 * readable copies (period name, one line per subject), never the nested
	 * subjectGrades with its uuids, and no pupil tracking (Cito) result.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-parent-report-card-grades/specs/portal-contribution/spec.md#requirement-the-parent-audience-reads-the-grades-on-the-childs-published-report-cards
	 */
	public function testParentReadsTheGradesOnPublishedReportCards(): void {
		$manifest = $this->provider->getContribution(self::PARENT_SUBJECT);
		$byId = array_column($manifest['collections'], null, 'id');
		$grades = $byId['parentReportCardGrades'] ?? null;

		$this->assertIsArray($grades, 'parentReportCardGrades collection MUST exist');
		$this->assertSame('report-card', $grades['schema']);
		$this->assertSame(['lifecycle' => 'published-to-parents'], $grades['filter']);
		$this->assertSame($byId['parentReportCards']['filter'], $grades['filter'], 'the same lifecycle filter as parentReportCards');
		$this->assertSame($byId['parentReportCards']['via'], $grades['via'], 'the same reverse join as every parent read');
		$this->assertSame('substantial', $grades['minTrust']);
		$this->assertTrue($grades['listable']);
		$this->assertSame(['learnerRef', 'periodName', 'gradeLines'], $grades['fields']);
		$this->assertSame(
			[
				['field' => 'periodName', 'label' => 'Period'],
				['field' => 'gradeLines', 'label' => 'Grades'],
			],
			$grades['columns']
		);
		$this->assertNotContains('subjectGrades', $grades['fields'], 'the nested grades read as uuids and bare numbers in the portal');

		// Pupil tracking results are not report card grades: no parent
		// collection reads lvs-result.
		$this->assertNotContains('lvs-result', array_column($manifest['collections'], 'schema'));

	}//end testParentReadsTheGradesOnPublishedReportCards()

	/**
	 * parentChildren matches `learner-profile` DIRECTLY — `guardianRefs`
	 * (array, on the schema being read) containing the guardian's own
	 * subjectRef. It carries NO `via` (no cross-object hop is
	 * needed), and exposes the full co-guardian group plus current
	 * beeldmateriaal consent state.
	 *
	 * @return void
	 * @spec openspec/specs/portal-contribution/spec.md#requirement-the-parent-audience-exposes-per-child-and-per-guardian-group-directory-data-req-pcon-006
	 */
	public function testParentChildrenCollectionMatchesDirectly(): void {
		$manifest = $this->provider->getContribution(self::PARENT_SUBJECT);
		$byId = array_column($manifest['collections'], null, 'id');
		$children = $byId['parentChildren'] ?? null;

		$this->assertIsArray($children, 'parentChildren collection MUST exist');
		$this->assertArrayNotHasKey('via', $children, 'parentChildren MUST NOT declare a via join — no cross-object hop is needed');
		$this->assertSame('learniq', $children['register']);
		$this->assertSame('learner-profile', $children['schema']);
		$this->assertSame('guardianRefs', $children['scopeField']);
		$this->assertSame('guardianRef', $children['scopeClaim']);
		$this->assertSame('substantial', $children['minTrust']);
		$this->assertSame(
			['givenName', 'familyName', 'groupLabel', 'guardianRefs', 'schoolId', 'beeldmateriaalConsent', 'beeldmateriaalConsentReviewDueAt'],
			$children['fields']
		);

	}//end testParentChildrenCollectionMatchesDirectly()

	/**
	 * Every parent read collection carries the reverse / scope-value `via` join
	 * with EXACTLY the reader's contract keys — `{register, schema, scopeField,
	 * targetField, match}` — and `match: 'scopeField'`. The join resolves the
	 * guardian's children through `learner-profile.guardianRefs` and collects
	 * each child profile's own OR object UUID (`id`), which the outer records
	 * match on their own `learnerRef`. Invented keys (`matchField`/`selectField`)
	 * would fail portaliq's `isValidVia()` fail-closed — so pin the exact set.
	 *
	 * @return void
	 */
	public function testParentCollectionsUseReverseScopeValueVia(): void {
		$manifest = $this->provider->getContribution(self::PARENT_SUBJECT);

		// parentChildren is deliberately excluded — it matches learner-profile
		// directly (see testParentChildrenCollectionMatchesDirectly), the one
		// parent collection with no cross-object hop and therefore no via.
		$reverseJoinedCollections = array_filter(
			$manifest['collections'],
			static fn ($c) => $c['id'] !== 'parentChildren'
		);

		foreach ($reverseJoinedCollections as $collection) {
			$via = $collection['via'] ?? null;
			$this->assertIsArray($via, "parent collection '{$collection['id']}' must declare a via join");

			// The reader (PortalObjectReader::isValidVia) recognises EXACTLY these
			// keys; anything else (matchField/selectField) is ignored/fails closed.
			$this->assertSame(
				['register', 'schema', 'scopeField', 'targetField', 'match'],
				array_keys($via),
				"via keys must be exactly the reader's contract for '{$collection['id']}'"
			);

			$this->assertSame('learniq', $via['register']);
			$this->assertSame('learner-profile', $via['schema']);
			// The join row's field matched against the guardian scope value.
			$this->assertSame('guardianRefs', $via['scopeField']);
			// The child LearnerProfile's own object UUID — a normalised OR row
			// exposes it at top-level `id` (ObjectEntity::jsonSerialize sets
			// $object['id'] = $this->uuid), which is what learnerRef points at.
			// The school calendar joins on the child's school instead
			// (portal-parent-child-record).
			$school = in_array($collection['id'], ['parentSchoolEvents', 'parentSchoolCalendar'], true);
			$this->assertSame($school === true ? 'schoolId' : 'id', $via['targetField']);
			// Reverse mode: keep outer rows whose OWN scopeField is in the set.
			$this->assertSame('scopeField', $via['match']);

			// The outer collection's own scope field the reverse match reads:
			// the child's learnerRef, or for a conference round the list of
			// invited children (portal-parent-conference-booking).
			$expected = [
				'parentConferenceRounds' => 'invitedLearnerRefs',
				'parentHomework' => 'learnerRefs',
				'parentSchoolEvents' => 'schoolId',
				'parentSchoolCalendar' => 'schoolId',
			][$collection['id']] ?? 'learnerRef';

			// A free conference time: the pupils who may book it (direct-conference-booking).
			if ($collection['id'] === 'parentConferenceFreeSlots') {
				$expected = 'eligibleLearnerRefs';
			}

			$this->assertSame($expected, $collection['scopeField']);
		}

	}//end testParentCollectionsUseReverseScopeValueVia()

	/**
	 * portal-contribution-guardian-audiences: the parent audience now ships
	 * `createExcuseRequest`, now that portaliq's writer cross-reference guard
	 * (portaliq#607, merged 2026-09-18) validates a client-supplied
	 * cross-reference against the subject's own `via`-derived scope. The
	 * load-bearing assertion is `scopeField`: it MUST be `submittedByRef`
	 * (who filed it), never `learnerRef` (which child it concerns) — stamping
	 * `learnerRef` from the guardian's own resolved UUID would silently write
	 * the guardian's UUID into the child-identifying field, the exact write
	 * IDOR shape this action was withheld to avoid before portaliq#607 landed.
	 * `via` MUST be byte-identical to the read collections' own reverse join
	 * (belt-and-braces per the lane's orchestrator instruction).
	 *
	 * @return void
	 * @spec openspec/specs/portal-contribution/spec.md#requirement-the-parent-audience-can-report-a-childs-absence-validated-against-the-callers-own-children-req-pcon-007
	 */
	public function testParentShipsCreateExcuseRequestValidatedAgainstOwnChildren(): void {
		$manifest = $this->provider->getContribution(self::PARENT_SUBJECT);

		// The absence report, then the three conference actions (direct-conference-booking).
		$this->assertCount(4, $manifest['actions']);
		$action = $manifest['actions'][0];

		$this->assertSame('createExcuseRequest', $action['id']);
		$this->assertSame('create', $action['type']);
		$this->assertSame('learniq', $action['register']);
		$this->assertSame('excuse-request', $action['schema']);
		$this->assertSame('submittedByRef', $action['scopeField'], 'scopeField MUST be submittedByRef, never learnerRef');
		$this->assertSame('guardianRef', $action['scopeClaim']);
		$this->assertSame('substantial', $action['minTrust']);
		$this->assertContains('learnerRef', $action['fields'], 'the guardian MUST supply which child the excuse concerns');

		// Drift pin: the create action's via MUST be the exact reverse-join
		// descriptor every parent read collection already uses — the same
		// scope the guardian's supplied learnerRef is validated against.
		$readCollectionVia = $manifest['collections'][1]['via'] ?? null;
		$this->assertIsArray($readCollectionVia, 'a reverse-joined read collection must exist to compare against');
		$this->assertSame($readCollectionVia, $action['via'], "the create action's via MUST match the read collections' via exactly");

	}//end testParentShipsCreateExcuseRequestValidatedAgainstOwnChildren()

	/**
	 * The praktijkopleider manifest carries a single direct-scoped BpvPlacement read
	 * collection (praktijkopleiderId == subjectRef), field-projected to drop
	 * schoolCoachId and leerbedrijfVerification.raw.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bpv/spec.md#requirement-praktijkopleider-portal-access-is-a-direct-scope-portalcontributionprovider-audience
	 */
	public function testPraktijkopleiderManifestShape(): void {
		$manifest = $this->provider->getContribution(self::PRAKTIJKOPLEIDER_SUBJECT);

		$this->assertIsArray($manifest);
		$this->assertSame('Learniq', $manifest['label']);
		$this->assertSame([], $manifest['notifications']);

		$collections = $manifest['collections'];
		// Her placements, the portfolios shared with her, the assessments she
		// wrote (site-workplace-trainer-portal-design) and the weeks of hours
		// waiting for her (internship-hours).
		$this->assertSame(
			['poBpvPlacements', 'poSharedPortfolios', 'poWerkprocesAssessments', 'poHourWeeks'],
			array_column($collections, 'id')
		);
		$collection = $collections[0];

		$this->assertSame('poBpvPlacements', $collection['id']);
		$this->assertSame('learniq', $collection['register']);
		$this->assertSame('bpv-placement', $collection['schema']);
		// Direct match — not a reverse `via` join like `parent`.
		$this->assertSame('practicalTrainerId', $collection['scopeField']);
		$this->assertSame('practicalTrainerId', $collection['scopeClaim']);
		$this->assertArrayNotHasKey('via', $collection);
		$this->assertSame('low', $collection['minTrust']);

		foreach (['schoolCoachId', 'trainingCompanyVerification', 'leerbedrijfVerification.raw'] as $forbidden) {
			$this->assertNotContains($forbidden, $collection['fields']);
		}

	}//end testPraktijkopleiderManifestShape()

	/**
	 * eportfolio: the praktijkopleider audience gains exactly one new collection,
	 * `poSharedPortfolios` — direct-matched over `portfolio-share`
	 * (`sharedWithPraktijkopleiderId == subject.subjectRef`), mirroring `poBpvPlacements`'s
	 * shape, filtered to `lifecycle: active` so a revoked share resolves no rows. No change to
	 * the existing `poBpvPlacements` collection.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/eportfolio/spec.md#requirement-bpv-praktijkopleider-and-external-assessor-sharing-reuse-the-adr-046-portal-audience-mechanism
	 */
	public function testPraktijkopleiderGainsSharedPortfoliosCollection(): void {
		$manifest = $this->provider->getContribution(self::PRAKTIJKOPLEIDER_SUBJECT);
		$collection = $manifest['collections'][1];

		$this->assertSame('poSharedPortfolios', $collection['id']);
		$this->assertSame('learniq', $collection['register']);
		$this->assertSame('portfolio-share', $collection['schema']);
		$this->assertSame('sharedWithPracticalTrainerId', $collection['scopeField']);
		$this->assertSame('practicalTrainerId', $collection['scopeClaim']);
		$this->assertArrayNotHasKey('via', $collection);
		// A revoked share must resolve no rows.
		$this->assertSame(['lifecycle' => 'active'], $collection['filter']);
		$this->assertContains('portfolioId', $collection['fields']);
		$this->assertContains('entryIds', $collection['fields']);

	}//end testPraktijkopleiderGainsSharedPortfoliosCollection()

	/**
	 * eportfolio: `external-assessor` mirrors `poSharedPortfolios`'s shape exactly, scoped by
	 * `sharedWithExternalAssessorId` instead, and ships zero create-actions (read-only per the
	 * brief).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/eportfolio/spec.md#requirement-bpv-praktijkopleider-and-external-assessor-sharing-reuse-the-adr-046-portal-audience-mechanism
	 */
	public function testExternalAssessorManifestShape(): void {
		$manifest = $this->provider->getContribution(self::EXTERNAL_ASSESSOR_SUBJECT);

		$this->assertIsArray($manifest);
		$this->assertSame('Learniq', $manifest['label']);
		$this->assertSame([], $manifest['notifications']);
		// Read-only — zero create-actions.
		$this->assertSame([], $manifest['actions']);

		$collections = $manifest['collections'];
		$this->assertCount(1, $collections);
		$collection = $collections[0];

		$this->assertSame('eaSharedPortfolios', $collection['id']);
		$this->assertSame('learniq', $collection['register']);
		$this->assertSame('portfolio-share', $collection['schema']);
		$this->assertSame('sharedWithExternalAssessorId', $collection['scopeField']);
		$this->assertSame('externalAssessorId', $collection['scopeClaim']);
		$this->assertArrayNotHasKey('via', $collection);
		// A revoked share must resolve no rows.
		$this->assertSame(['lifecycle' => 'active'], $collection['filter']);
		$this->assertContains('portfolioId', $collection['fields']);
		$this->assertContains('entryIds', $collection['fields']);

	}//end testExternalAssessorManifestShape()

	/**
	 * Both praktijkopleider create-actions are `type: create`, direct-scope-stamped from
	 * `subject.subjectRef` (never the request body), `minTrust: substantial`, and whitelist
	 * only placement/kwalificatiedossier/beoordeling/signature-evidence fields — never a
	 * staff decision or an already-published grade/status field.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bpv/spec.md#requirement-praktijkopleider-portal-actions-never-trust-client-supplied-identity
	 */
	public function testPraktijkopleiderActionsAreDirectScopeStampedAndWhitelisted(): void {
		$manifest = $this->provider->getContribution(self::PRAKTIJKOPLEIDER_SUBJECT);
		$actions = $manifest['actions'];

		$this->assertSame(
			['createWerkprocesAssessment', 'approveHourWeek', 'signPraktijkovereenkomst'],
			array_column($actions, 'id')
		);
		$byId = array_column($actions, null, 'id');

		// an-invited-trainer-may-assess: the assessment posts to learniq's own
		// endpoint, because only a forward carries the sign-in level, and an
		// invited trainer may assess.
		$assessment = $byId['createWerkprocesAssessment'];
		$this->assertSame('endpoint-forward', $assessment['type']);
		$this->assertSame('/apps/learniq/api/portal/werkproces-assessments', $assessment['endpoint']);
		$this->assertSame('POST', $assessment['method']);
		$this->assertArrayNotHasKey('schema', $assessment);
		$this->assertSame('practicalTrainerId', $assessment['subjectField']);
		$this->assertSame('practicalTrainerId', $assessment['scopeClaim']);
		$this->assertSame('low', $assessment['minTrust']);
		$this->assertSame(
			[
				'bpvPlacementId',
				'curriculumPlanId',
				'componentId',
				'kwalificatiedossierCode',
				'coreTaskCode',
				'werkprocesCode',
				'werkprocesLabel',
				'competencyId',
				'assessment',
				'notes',
			],
			$assessment['fields']
		);
		// Who assessed and how sure the school is are never client-writable.
		foreach (['assessorId', 'assessorName', 'assessorCompany', 'assessorCompanyKvkNumber', 'assuranceLevel'] as $server) {
			$this->assertNotContains($server, $assessment['fields'], $server);
		}

		// internship-hours: approving a week is her word about a student's
		// record, like an assessment, so it takes the same route and the same
		// floor. The hours she approves and her note are hers to send; who
		// approved, when, and how sure the school is are not.
		$approval = $byId['approveHourWeek'];
		$this->assertSame('endpoint-forward', $approval['type']);
		$this->assertSame('/apps/learniq/api/portal/hour-weeks/approve', $approval['endpoint']);
		$this->assertSame('POST', $approval['method']);
		$this->assertArrayNotHasKey('schema', $approval);
		$this->assertSame('practicalTrainerId', $approval['subjectField']);
		$this->assertSame('practicalTrainerId', $approval['scopeClaim']);
		$this->assertSame('low', $approval['minTrust']);
		$this->assertSame(['hourWeekId', 'hoursApproved', 'note'], $approval['fields']);
		foreach (['approvedBy', 'approvedByName', 'approvedAt', 'assuranceLevel', 'lifecycle', 'hoursSubmitted', 'learnerRef'] as $server) {
			$this->assertNotContains($server, $approval['fields'], $server);
		}

		// The POK signature is a contract signature, not an assessment: it keeps
		// its substantial floor until Ruben says otherwise.
		$signature = $byId['signPraktijkovereenkomst'];
		$this->assertSame('substantial', $signature['minTrust']);
		$this->assertSame('create', $signature['type']);
		$this->assertSame('pok-signature', $signature['schema']);
		$this->assertSame('signerId', $signature['scopeField']);
		$this->assertSame('practicalTrainerId', $signature['scopeClaim']);
		$this->assertSame('substantial', $signature['minTrust']);
		$this->assertSame(
			['subjectId', 'subjectVersion', 'assuranceLevel', 'method', 'evidenceRef'],
			$signature['fields']
		);

		// Neither create lets the client set a staff/grade/status field.
		foreach (['assessorId', 'signerId', 'lifecycle', 'signedAt'] as $forbidden) {
			$this->assertNotContains($forbidden, $assessment['fields']);
			$this->assertNotContains($forbidden, $signature['fields']);
		}

	}//end testPraktijkopleiderActionsAreDirectScopeStampedAndWhitelisted()

	/**
	 * Register-drift pin: every schema slug, scope field, whitelisted field and
	 * `via` scope-field the manifest references MUST exist in the shipped
	 * learniq_register.json — proving the `portal-identity` refs are present and
	 * that no register rename silently broke the portal. Covers the parent
	 * reverse-join collections too (their via `scopeField` is `guardianRefs` on
	 * `learner-profile`; `targetField` is the OR object-identity token `id`, not
	 * a schema property, so it is checked against the identity tokens).
	 *
	 * @return void
	 */
	public function testManifestMatchesRegisterSchemas(): void {
		$registerPath = __DIR__ . '/../../../lib/Settings/learniq_register.json';
		$this->assertFileExists($registerPath);

		$register = json_decode((string)file_get_contents($registerPath), true);
		$this->assertIsArray($register);

		// Build slug => property-names map from the register.
		$propsBySlug = [];
		foreach (($register['components']['schemas'] ?? []) as $schema) {
			$slug = $schema['slug'] ?? null;
			if ($slug !== null) {
				$propsBySlug[$slug] = array_keys($schema['properties'] ?? []);
			}
		}

		// The portal-identity refs MUST exist (the change this provider depends on).
		$this->assertContains('learnerRef', $propsBySlug['grade-entry'] ?? []);
		$this->assertContains('learnerRefs', $propsBySlug['submission'] ?? []);
		$this->assertContains('learnerRef', $propsBySlug['submission'] ?? []);
		$this->assertContains('submittedByRef', $propsBySlug['excuse-request'] ?? []);
		$this->assertContains('guardianRefs', $propsBySlug['learner-profile'] ?? []);

		// Portal-contribution-guardian-audiences: the parentChildren
		// collection's whitelisted fields.
		$this->assertContains('beeldmateriaalConsent', $propsBySlug['learner-profile'] ?? []);
		$this->assertContains('beeldmateriaalConsentReviewDueAt', $propsBySlug['learner-profile'] ?? []);

		// The bpv-praktijkovereenkomst refs the praktijkopleider audience depends on.
		$this->assertContains('practicalTrainerId', $propsBySlug['bpv-placement'] ?? []);
		$this->assertContains('assessorId', $propsBySlug['werkproces-assessment'] ?? []);
		$this->assertContains('signerId', $propsBySlug['pok-signature'] ?? []);

		// The eportfolio refs the poSharedPortfolios/eaSharedPortfolios collections depend on.
		$this->assertContains('sharedWithPracticalTrainerId', $propsBySlug['portfolio-share'] ?? []);
		$this->assertContains('sharedWithExternalAssessorId', $propsBySlug['portfolio-share'] ?? []);
		$this->assertContains('portfolioId', $propsBySlug['portfolio-share'] ?? []);
		$this->assertContains('entryIds', $propsBySlug['portfolio-share'] ?? []);

		// All four audiences are served; each yields a manifest.
		foreach (
			[
				self::STUDENT_SUBJECT,
				self::PARENT_SUBJECT,
				self::PRAKTIJKOPLEIDER_SUBJECT,
				self::EXTERNAL_ASSESSOR_SUBJECT,
			] as $subject
		) {
			$manifest = $this->provider->getContribution($subject);
			$this->assertIsArray($manifest);

			foreach (($manifest['collections'] ?? []) as $collection) {
				$slug = $collection['schema'];
				$this->assertArrayHasKey($slug, $propsBySlug, "manifest schema '$slug' missing from register");
				$props = $propsBySlug[$slug];

				$this->assertContains($collection['scopeField'], $props, "scopeField on '$slug' not in register");
				foreach (($collection['fields'] ?? []) as $field) {
					$this->assertContains($field, $props, "field '$field' on '$slug' not in register");
				}

				if (isset($collection['via']) === true) {
					$via = $collection['via'];
					$viaSlug = $via['schema'];
					$this->assertArrayHasKey($viaSlug, $propsBySlug, "via schema '$viaSlug' missing from register");
					// The via's join scope field (guardianRefs) MUST be a real
					// property on the via schema — this is the drift-detectable ref.
					$this->assertContains(
						$via['scopeField'],
						$propsBySlug[$viaSlug],
						"via scopeField '{$via['scopeField']}' not in register schema '$viaSlug'"
					);
					// The via's targetField is either a schema property OR the OR
					// object-identity token ('id'/'uuid') the normalised row
					// exposes — never an invented key.
					$this->assertContains(
						$via['targetField'],
						array_merge($propsBySlug[$viaSlug], ['id', 'uuid']),
						"via targetField '{$via['targetField']}' is neither a register property on '$viaSlug' nor an identity token"
					);
				}
			}

			foreach (($manifest['actions'] ?? []) as $action) {
				// An endpoint-forward action writes nothing itself: its fields
				// are the body of a learniq endpoint, not register properties
				// (checked in testStudentTestsIsATimedTask).
				if (($action['type'] ?? '') === 'endpoint-forward') {
					continue;
				}

				$slug = $action['schema'];
				$this->assertArrayHasKey($slug, $propsBySlug, "action schema '$slug' missing from register");
				$props = $propsBySlug[$slug];
				$this->assertContains($action['scopeField'], $props, "action scopeField on '$slug' not in register");
				foreach (($action['fields'] ?? []) as $field) {
					$this->assertContains($field, $props, "action field '$field' on '$slug' not in register");
				}
			}
		}

	}//end testManifestMatchesRegisterSchemas()
	/**
	 * A guardian books a parent-teacher conversation: the booking is scoped
	 * to the guardian's own claim, names only their own child (portaliq
	 * cross reference over learner-profile.guardianRefs) and whitelists only
	 * the round, the child and a note.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-parent-conference-booking/specs/parent-conferences/spec.md
	 */
	public function testParentBooksAConferenceForTheirOwnChildOnly(): void {
		$manifest = $this->provider->getContribution(self::PARENT_SUBJECT);
		$actions = array_column($manifest['actions'], null, 'id');
		$booking = $actions['createConferenceSignup'];

		$this->assertSame('conference-signup', $booking['schema']);
		$this->assertSame('guardianRef', $booking['scopeField']);
		$this->assertSame('guardianRef', $booking['scopeClaim']);
		$this->assertSame('substantial', $booking['minTrust']);
		$this->assertSame(['conferenceRoundId', 'learnerRef', 'notes'], $booking['fields']);
		$this->assertSame(
			['register' => 'learniq', 'schema' => 'learner-profile', 'scopeField' => 'guardianRefs', 'scopeClaim' => 'guardianRef', 'required' => true],
			$booking['crossRefs']['learnerRef']
		);
		$this->assertSame($booking['crossRefs'], $actions['createExcuseRequest']['crossRefs']);

		$rounds = array_column($manifest['collections'], null, 'id')['parentConferenceRounds'];
		$this->assertSame(['lifecycle' => 'booking-open'], $rounds['filter']);
	}//end testParentBooksAConferenceForTheirOwnChildOnly()
	/**
	 * The parent contribution tells portaliq which collections give the
	 * guardian's news audience, and each named collection exists.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-parent-conference-booking/specs/portal-contribution/spec.md
	 */
	public function testParentDeclaresTheNewsAudience(): void {
		$manifest = $this->provider->getContribution(self::PARENT_SUBJECT);
		$ids = array_column($manifest['collections'], 'id');

		$this->assertSame('parentChildren', $manifest['guardianAudience']['children']);
		$this->assertSame('schoolId', $manifest['guardianAudience']['schoolField']);
		$this->assertContains($manifest['guardianAudience']['children'], $ids);
		$this->assertContains($manifest['guardianAudience']['groups']['collection'], $ids);
	}//end testParentDeclaresTheNewsAudience()

	/**
	 * The guardian reads the group's name, not its uuid. Portaliq leaves a
	 * uuid out of a cell, so a `cohortId` column read empty. The column reads
	 * the enrolment's own readable copy, `cohortName` (ReadableCopyStamp), so
	 * the guardian reads nothing beyond their child's own enrolments; the
	 * news audience still matches on `cohortId`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parent-groups-read-by-name/specs/portal-contribution/spec.md#requirement-the-guardian-reads-the-name-of-the-childs-group
	 */
	public function testParentGroupColumnReadsTheGroupName(): void {
		$manifest = $this->provider->getContribution(self::PARENT_SUBJECT);
		$groups = array_column($manifest['collections'], null, 'id')['parentGroupMemberships'];

		$this->assertSame([['field' => 'cohortName', 'label' => 'Group']], $groups['columns']);
		$this->assertSame(['learnerRef', 'cohortId', 'cohortName'], $groups['fields']);
		$this->assertSame('enrolment', $groups['schema']);
		$this->assertSame('cohortId', $manifest['guardianAudience']['groups']['field']);

		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'), true);
		$enrolment = array_column($register['components']['schemas'], null, 'slug')['enrolment'];
		$this->assertSame('string', $enrolment['properties']['cohortName']['type']);
		$this->assertArrayNotHasKey('format', $enrolment['properties']['cohortName']);
	}//end testParentGroupColumnReadsTheGroupName()
}//end class
