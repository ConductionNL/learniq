<?php

/**
 * Learniq StudentPortalPages
 *
 * The pupil's pages on the site, as `site-pupil-portal-design` and the
 * approved mockup `LearniqPupil.dc.html` describe them, built only from keys
 * portaliq development keeps today: an overview on `/mijn` (`home: true`)
 * with the work to hand in first, a short menu (Overzicht, Inleveren, Cijfers,
 * Toetsen, Afwezig melden) and every other collection page kept on its route
 * but out of the menu. Her timetable (`studentSessions`) reads the lessons of
 * the groups she is actively enrolled in, through portaliq's `via.when`.
 *
 * Also declares `studentHomework`: the published assignments of the pupil's
 * groups, read by `Assignment.learnerRefs`, which the server stamps from the
 * group's enrolments and which is never projected.
 *
 * @category Portal
 * @package  OCA\Learniq\Portal
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
 * @spec openspec/changes/site-pupil-portal-design/specs/portal-contribution/spec.md#requirement-a-pupil-lands-on-an-overview-of-today
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

/**
 * Builds the pupil's homework collection, her BPV placement and hour weeks,
 * her overview and her menu.
 *
 * @spec openspec/changes/site-pupil-portal-design/specs/portal-contribution/spec.md#requirement-a-pupil-lands-on-an-overview-of-today
 */
class StudentPortalPages {

	private const REGISTER = 'learniq';

	/**
	 * The collection pages that stay in the pupil's menu, with their menu
	 * label: Inleveren, Cijfers, Toetsen, BPV en uren, Afwezig melden.
	 *
	 * @spec openspec/changes/student-portal-reads-like-the-boards/specs/portal-contribution/spec.md#requirement-the-student-pages-use-the-words-of-the-boards
	 */
	private const MENU_PAGES = [
		'studentSessions'       => 'Timetable',
		'studentHomework'       => 'Hand in',
		'studentGrades'         => 'Grades',
		'studentTests'          => 'Tests',
		'studentExcuseRequests' => 'Report an absence',
		// The student's BPV hours page, "BPV en uren" on the esdoornveen
		// board (MijnMenu). A pupil without a placement sees an empty list.
		'studentHourWeeks'      => 'BPV and hours',
	];

	/**
	 * The words a pupil reads for a changed lesson, by `changeReasonKind`.
	 * A cancelled lesson says "Vervalt" through the timetable itself, so
	 * `teacher-absence` here is the lesson that goes ahead with another
	 * teacher. `other` gets no word: a code without one draws no pill.
	 */
	public const LESSON_CHANGE = [
		'room-unavailable' => 'Other room',
		'teacher-absence'  => 'Other teacher',
		'timetable-change' => 'Changed',
	];

	private const STUDENT_SESSIONS_FIELDS = [
		'cohortId',
		'courseId',
		'title',
		'startsAt',
		'endsAt',
		'location',
		'changeReasonKind',
		'changeReason',
		'lifecycle',
	];

	private const STUDENT_SESSIONS_VIA = [
		'register' => self::REGISTER,
		'schema' => 'enrolment',
		'scopeField' => 'learnerRef',
		'targetField' => 'cohortId',
		'match' => 'scopeField',
		'when' => ['field' => 'lifecycle', 'in' => ['active']],
	];

	private const STUDENT_SESSIONS_COLUMNS = [
		['field' => 'startsAt', 'label' => 'Starts at', 'render' => 'datetime'],
		['field' => 'title', 'label' => 'Subject'],
		['field' => 'location', 'label' => 'Room'],
	];

	private const STUDENT_SESSIONS_FIELD_CONFIGS = [
		'changeReasonKind' => ['label' => 'Change', 'valueLabels' => self::LESSON_CHANGE],
	];

	private const TIMETABLE_BLOCK_SOURCES = [
		[
			'collection' => 'studentSessions',
			'startField' => 'startsAt',
			'endField' => 'endsAt',
			'titleField' => 'title',
			'metaField' => 'location',
			'noteField' => 'changeReason',
			'statusField' => 'changeReasonKind',
			'cancelledWhen' => ['field' => 'lifecycle', 'in' => ['cancelled']],
		],
	];

	private const STUDENT_HOMEWORK_COLUMNS = [
		['field' => 'title', 'label' => 'To do'],
		['field' => 'dueAt', 'label' => 'Hand in by', 'render' => 'date'],
	];

	private const STUDENT_BPV_PLACEMENTS = [
		// Her own placement, so her hours have something to be about.
		// Without it the week form could only ask her to type a uuid:
		// portaliq fills a `collection` option provider from the
		// subject-scoped collection over that schema, and a pupil had
		// none (internship-hours).
		'id' => 'studentBpvPlacements',
		'register' => self::REGISTER,
		'schema' => 'bpv-placement',
		'scopeField' => 'learnerRef',
		'scopeClaim' => 'learnerRef',
		'label' => 'My placement',
		'listable' => true,
		'minTrust' => 'low',
		// Followed like a case: the placement has steps (placement-steps-and-assessment-draft).
		'kind' => 'cases',
		'steps' => ['label' => 'Where do you stand?', 'provider' => 'bpvPlacementSteps'],
		// The school's own people and the SBB payload stay out, the same
		// projection her trainer reads.
		'fields' => [
			'learnerRef',
			'trainingCompanyName',
			'periodFrom',
			'periodTo',
			'agreedHours',
			'hoursApprovedTotal',
			// The bar on her hours page: approved, waiting and sent back (bpv-hours-match-the-board).
			'hoursWaitingTotal',
			'hoursReturnedTotal',
			'lifecycle',
			// The agreements on the board (Detail, "Afspraken"; board-data-the-schemas-lacked).
			'workdaysLabel',
			'workplaceAddress',
			'qualificationName',
			'crebo',
		],
		'columns' => [
			['field' => 'trainingCompanyName', 'label' => 'Training company'],
			['field' => 'hoursApprovedTotal', 'label' => 'Hours approved'],
			['field' => 'agreedHours', 'label' => 'Agreed hours'],
		],
		// Words on the record, never field keys such as "Period From" (REPORT-2, item 8).
		'fieldConfigs' => [
			'trainingCompanyName' => ['label' => 'Training company'],
			'periodFrom'          => ['label' => 'From'],
			'periodTo'            => ['label' => 'Until'],
			'agreedHours'         => ['label' => 'Agreed hours'],
			'hoursApprovedTotal'  => ['label' => 'Hours approved'],
			'hoursWaitingTotal'   => ['label' => 'Waiting for approval'],
			'hoursReturnedTotal'  => ['label' => 'Sent back'],
			'lifecycle'           => ['label' => 'Status', 'valueLabels' => PortalValueLabels::PLACEMENT_STATUS],
			'workdaysLabel'       => ['label' => 'Workdays'],
			'workplaceAddress'    => ['label' => 'Address'],
			'qualificationName'   => ['label' => 'Qualification'],
			'crebo'               => ['label' => 'Crebo'],
		],
	];

	private const STUDENT_HOUR_WEEKS_FIELDS = [
		'learnerRef',
		'bpvPlacementId',
		'isoWeek',
		'hoursSubmitted',
		'submittedAt',
		'hoursApproved',
		'approvedByName',
		'approvedAt',
		'note',
		'lifecycle',
	];

	private const STUDENT_HOUR_WEEKS_COLUMNS = [
		['field' => 'isoWeek', 'label' => 'Week'],
		['field' => 'hoursSubmitted', 'label' => 'Hours you entered'],
		// When she sent it, which is what answers "have I actually
		// handed in week 39?" while the week waits.
		['field' => 'submittedAt', 'label' => 'Sent on', 'render' => 'date'],
		['field' => 'hoursApproved', 'label' => 'Hours approved'],
		// A corrected week says so in words. Reading "approved"
		// over a number she did not write is exactly how a
		// correction becomes silent.
		['field' => 'lifecycle', 'label' => 'Status', 'valueLabels' => PortalValueLabels::HOUR_WEEK_STATUS],
	];

	private const STUDENT_SCHOOL_COACHES = [
		'id' => 'studentSchoolCoaches',
		'register' => self::REGISTER,
		'schema' => 'staff',
		'scopeField' => 'ncUserId',
		'scopeClaim' => 'learnerRef',
		// Reverse join: a staff row counts when its user id is the
		// schoolCoachId of a placement of this student.
		'via' => [
			'register' => self::REGISTER,
			'schema' => 'bpv-placement',
			'scopeField' => 'learnerRef',
			'targetField' => 'schoolCoachId',
			'match' => 'scopeField',
		],
		'label' => 'Your BPV supervisor at school',
		'listable' => false,
		'minTrust' => 'low',
		'fields' => ['ncUserId'],
		'columns' => [
			['field' => 'ncUserId', 'label' => 'BPV supervisor', 'render' => 'user'],
		],
	];

	private const STUDENT_TRAINERS = [
		'id' => 'studentTrainers',
		'register' => self::REGISTER,
		'schema' => 'praktijkopleider',
		// Not read in forward join mode; portaliq matches the row's own id.
		'scopeField' => 'trainingCompanyName',
		'scopeClaim' => 'learnerRef',
		// Forward join: a trainer counts when her own id is a placement's
		// practicalTrainerId of a placement of this student.
		'via' => [
			'register' => self::REGISTER,
			'schema' => 'bpv-placement',
			'scopeField' => 'learnerRef',
			'targetField' => 'practicalTrainerId',
		],
		'label' => 'Your trainer',
		'listable' => false,
		'minTrust' => 'low',
		'fields' => ['givenName', 'familyName', 'trainingCompanyName'],
	];

	private const STUDENT_WORK_PROCESSES_COLUMNS = [
		['field' => 'werkprocesCode', 'label' => 'Code'],
		['field' => 'werkprocesLabel', 'label' => 'Work process'],
		['field' => 'hoursSpent', 'label' => 'Hours'],
		['field' => 'selfAssessment', 'label' => 'Your estimate', 'valueLabels' => PortalValueLabels::SELF_ASSESSMENT],
	];

	private const SUBMIT_HOUR_WEEK_CROSS_REFS = [
		'bpvPlacementId' => [
			'register' => self::REGISTER,
			'schema' => 'bpv-placement',
			'scopeField' => 'learnerRef',
			'scopeClaim' => 'learnerRef',
			'required' => true,
		],
	];

	/**
	 * The student's placements to choose from, by the company's name.
	 */
	public const PLACEMENT_OPTIONS = [
		'type' => 'collection',
		'register' => self::REGISTER,
		'schema' => 'bpv-placement',
		'labelField' => 'trainingCompanyName',
		'valueField' => 'id',
	];

	private const SUBMIT_HOUR_WEEK_OPTIONS_PROVIDERS = [
		'bpvPlacementId' => self::PLACEMENT_OPTIONS,
	];

	private const SUBMIT_HOUR_WEEK_FIELD_CONFIGS = [
		'bpvPlacementId' => ['label' => 'Your placement', 'required' => true],
		'isoWeek' => ['label' => 'The week, as 2026-W39', 'required' => true],
		'hoursSubmitted' => ['label' => 'Hours you worked', 'required' => true],
	];

	/**
	 * Work to hand in on the overview. Her own submission is looked up per
	 * assignment; work handed in, handed in late or marked leaves the list,
	 * a draft or no submission keeps it (portaliq REQ-SMO-025).
	 *
	 * @spec openspec/changes/site-pupil-portal-design/specs/portal-contribution/spec.md
	 */
	private const STUDENT_HOMEWORK_TASKS_BLOCK = [
		'type' => 'tasks',
		'label' => 'Homework and tests',
		'display' => 'highlight',
		'collection' => 'studentHomework',
		'dueField' => 'dueAt',
		'titleFields' => ['title'],
		'lookups' => [
			[
				'as' => 'submission',
				'collection' => 'studentSubmissions',
				'matchField' => 'assignmentId',
				'valueField' => 'lifecycle',
			],
		],
		'excludeWhen' => ['lookup' => 'submission', 'in' => ['submitted', 'late', 'returned']],
	];

	private const STUDENT_LATEST_GRADES_BLOCK = [
		'type' => 'collection',
		'label' => 'Latest grades',
		'collection' => 'studentGrades',
		'limit' => 3,
		'sort' => ['field' => 'gradedAt', 'direction' => 'desc'],
	];

	private const STUDENT_SUPERVISORS_BLOCK = [
		'type' => 'collection',
		'label' => 'Your supervisors',
		'collection' => 'studentTrainers',
		'display' => 'rows',
		'titleFields' => ['givenName', 'familyName'],
		'subtitleField' => 'trainingCompanyName',
	];

	private const HOURS_BAR_SEGMENTS = [
		['field' => 'hoursApprovedTotal', 'label' => 'Approved', 'tone' => 'positive'],
		['field' => 'hoursWaitingTotal', 'label' => 'Waiting', 'tone' => 'waiting'],
		['field' => 'hoursReturnedTotal', 'label' => 'Sent back', 'tone' => 'warning'],
	];

	private const HOURS_BAR_CARDS = [
		['field' => 'hoursApprovedTotal', 'label' => 'Approved', 'unit' => ['one' => 'hour', 'other' => 'hours']],
		['field' => 'hoursWaitingTotal', 'label' => 'Waiting', 'unit' => ['one' => 'hour', 'other' => 'hours']],
		['field' => 'hoursReturnedTotal', 'label' => 'Sent back', 'unit' => ['one' => 'hour', 'other' => 'hours'], 'highlight' => true],
	];

	/**
	 * Her timetable: the lessons of the groups she is enrolled in.
	 *
	 * A session belongs to a group (`cohortId`), not to a pupil, so the
	 * collection is scoped through a reverse join on her own enrolments
	 * (`enrolment.learnerRef` is her claim, its `cohortId` the target, and a
	 * session counts when its `cohortId` is in that set). `via.when` keeps
	 * only live enrolments: a withdrawn or completed one (last year's group)
	 * grants no lesson (site-pupil-portal-design T1, T2).
	 *
	 * Projected: when and where, the subject, whether it is cancelled and the
	 * school's own words about a change. Never the substitute's user id, the
	 * affected pupils or parents, or the source system's reference.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/site-pupil-portal-design/specs/portal-contribution/spec.md#requirement-new-a-pupil-sees-her-own-timetable
	 */
	public function sessionsCollection(): array {
		return [
			'id' => 'studentSessions',
			'register' => self::REGISTER,
			'schema' => 'session',
			'scopeField' => 'cohortId',
			'scopeClaim' => 'learnerRef',
			'via' => self::STUDENT_SESSIONS_VIA,
			'label' => 'My timetable',
			'listable' => true,
			'minTrust' => 'low',
			'fields' => self::STUDENT_SESSIONS_FIELDS,
			'fieldConfigs' => self::STUDENT_SESSIONS_FIELD_CONFIGS,
			'columns' => self::STUDENT_SESSIONS_COLUMNS,
		];
	}//end sessionsCollection()

	/**
	 * A timetable block over her lessons: today on the overview, the week
	 * with day tiles on the timetable page (portaliq
	 * `calendar-timetable-display`). The pill reads the change's word, a
	 * cancelled lesson is struck through, the note is the school's own words.
	 *
	 * @param string $label The heading.
	 * @param string $range `day` or `week`.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/site-pupil-portal-design/specs/portal-contribution/spec.md#requirement-new-a-pupil-sees-her-own-timetable
	 */
	public function timetableBlock(string $label, string $range): array {
		return [
			'type' => 'calendar',
			'label' => $label,
			'display' => 'timetable',
			'range' => $range,
			'firstLabel' => 'Your first lesson',
			'sources' => self::TIMETABLE_BLOCK_SOURCES,
		];
	}//end timetableBlock()

	/**
	 * The published assignments of the pupil's groups.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/site-pupil-portal-design/specs/portal-contribution/spec.md#requirement-new-a-pupil-sees-the-work-she-has-to-hand-in
	 */
	public function homeworkCollection(): array {
		return [
			'id' => 'studentHomework',
			'register' => self::REGISTER,
			'schema' => 'assignment',
			// A pupil's uuid sits in the assignment's list of its group's
			// pupils; portaliq matches list membership (portaliq#750).
			'scopeField' => 'learnerRefs',
			'scopeClaim' => 'learnerRef',
			'filter' => ['lifecycle' => 'published'],
			'label' => 'To hand in',
			'listable' => true,
			'minTrust' => 'low',
			'fields' => ['title', 'instructions', 'dueAt', 'cohortId', 'allowLateSubmission', 'lifecycle'],
			'columns' => self::STUDENT_HOMEWORK_COLUMNS,
		];
	}//end homeworkCollection()

	/**
	 * Her own absence and lateness per school year, the strip at the bottom
	 * of the board's overview ("1 dag ziek, 2 keer te laat, 0 uur zonder
	 * melding"). Scoped on her own learnerRef, the same as her attendance
	 * marks; the summary holds counts only, never a reason.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/site-pupil-portal-design/specs/portal-contribution/spec.md#requirement-the-pupil-overview-follows-the-designed-board
	 */
	public function attendanceSummaryCollection(): array {
		return [
			'id' => 'studentAttendanceSummary',
			'register' => self::REGISTER,
			'schema' => 'attendance-summary',
			'scopeField' => 'learnerRef',
			'scopeClaim' => 'learnerRef',
			'label' => 'Absence this school year',
			'listable' => false,
			'minTrust' => 'low',
			'fields' => ['learnerRef', 'schoolYear', 'absentDays', 'absentAuthorisedDays', 'absentUnauthorisedDays', 'lateCount', 'lateMinutes'],
		];
	}//end attendanceSummaryCollection()

	/**
	 * Her own BPV placement and her weeks of realised hours.
	 *
	 * Both are declared because one needs the other: portaliq fills a
	 * `collection` option provider from the subject-scoped collection over that
	 * schema, so without a placement collection the week form could only ask
	 * her to type a uuid.
	 *
	 * @return array<int, array<string, mixed>> Two collections.
	 *
	 * @spec openspec/specs/bpv/spec.md#requirement-a-week-of-bpv-hours-is-a-record-of-its-own
	 */
	public function bpvCollections(): array {
		return [
		self::STUDENT_BPV_PLACEMENTS,
		$this->hourWeeksCollection(),
		$this->workProcessesCollection(),
		...$this->supervisorCollections(),
		];

	}//end bpvCollections()

	/**
	 * Her weeks of realised hours, with both numbers on each week.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/bpv/spec.md#requirement-a-week-of-bpv-hours-is-a-record-of-its-own
	 */
	private function hourWeeksCollection(): array {
		return [
			'id' => 'studentHourWeeks',
			'register' => self::REGISTER,
			'schema' => 'bpv-hour-week',
			'scopeField' => 'learnerRef',
			'scopeClaim' => 'learnerRef',
			'label' => 'My hours',
			'listable' => true,
			'minTrust' => 'low',
			// She reads her own number, when she sent it, the number her
			// trainer approved, the note and who approved it: being overruled
			// is visible rather than silent (internship-hours).
			//
			// `submittedBy` is deliberately NOT projected. On her own page it
			// is always her own profile uuid, because the schema holds a
			// LearnerProfile and the server derives it from the placement, so
			// it is a value that never varies and tells her nothing. It stays
			// what the school reads afterwards, and the e2e asserts it from an
			// admin read, where it is evidence that the stamp ran.
			'fields' => self::STUDENT_HOUR_WEEKS_FIELDS,
			'columns' => self::STUDENT_HOUR_WEEKS_COLUMNS,
		];
	}//end hourWeeksCollection()

	/**
	 * Who supervises her placement, readable by her alone: the trainer at the
	 * company (praktijkopleider), joined through her own placements' trainer
	 * reference, and her coach at school, joined through their coach user id
	 * (board Detail, "Je begeleiders"). Names and the company only, never a
	 * phone number or an e-mail address.
	 *
	 * @return array<int, array<string, mixed>> Two collections.
	 *
	 * @spec openspec/changes/board-data-the-schemas-lacked/specs/portal-contribution/spec.md#requirement-the-placement-page-shows-the-agreements-and-the-work-processes
	 */
	public function supervisorCollections(): array {
		return [
			self::STUDENT_TRAINERS,
			self::STUDENT_SCHOOL_COACHES,
		];
	}//end supervisorCollections()

	/**
	 * Her own record per work process of her placement: the hours she spent
	 * on it and her own estimate (board Detail, "Werkprocessen"). Read on her
	 * placement page only, narrowed to the open placement.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/board-data-the-schemas-lacked/specs/portal-contribution/spec.md#requirement-the-placement-page-shows-the-agreements-and-the-work-processes
	 */
	public function workProcessesCollection(): array {
		return [
			'id' => 'studentWorkProcesses',
			'register' => self::REGISTER,
			'schema' => 'werkproces-progress',
			'scopeField' => 'learnerRef',
			'scopeClaim' => 'learnerRef',
			'label' => 'Work processes',
			'listable' => false,
			'minTrust' => 'low',
			'fields' => ['learnerRef', 'bpvPlacementId', 'werkprocesCode', 'werkprocesLabel', 'hoursSpent', 'selfAssessment'],
			'columns' => self::STUDENT_WORK_PROCESSES_COLUMNS,
			// "Nu invullen" on each row: her own estimate, nothing else.
			'rowActions' => [StudentSelfAssessment::ACTION],
		];
	}//end workProcessesCollection()

	/**
	 * She enters a week of her own placement's hours.
	 *
	 * Only the placement, the week and the hours: who she is, when she sent it,
	 * which school it belongs to, the hours her trainer approves and the state
	 * are all server-written (HourWeekSubmissionStamp, PortalHourWeekApproval).
	 *
	 * @return array<string, mixed> The create action.
	 *
	 * @spec openspec/specs/bpv/spec.md#requirement-a-week-of-bpv-hours-is-a-record-of-its-own
	 */
	public function hourWeekAction(): array {
		return [
		'id' => 'submitHourWeek',
		'type' => 'create',
		'label' => 'Enter the hours of a week',
		'register' => self::REGISTER,
		'schema' => 'bpv-hour-week',
		'scopeField' => 'learnerRef',
		'scopeClaim' => 'learnerRef',
		'minTrust' => 'low',
		'fields' => ['bpvPlacementId', 'isoWeek', 'hoursSubmitted'],
		// The placement must be her own. Portaliq stamps `learnerRef`
		// from her claim, but `bpvPlacementId` comes from the form, so
		// without this guard she could file hours against another
		// student's placement and HourWeekTotalRollup would add them to
		// that placement's total.
		'crossRefs' => self::SUBMIT_HOUR_WEEK_CROSS_REFS,
		// And she picks it from her own placements rather than typing a
		// uuid, the way her guardian picks a child.
		'optionsProviders' => self::SUBMIT_HOUR_WEEK_OPTIONS_PROVIDERS,
		'fieldConfigs' => self::SUBMIT_HOUR_WEEK_FIELD_CONFIGS,
		'submitLabel' => 'Send these hours',
		'successMessage' => 'Your hours are with your workplace trainer. You see her decision in the list.',
		];

	}//end hourWeekAction()

	/**
	 * The pupil's pages: the overview, the menu pages, then every other
	 * listable collection's page out of the menu. Page ids are the collection
	 * ids, so the routes portaliq gave them before stay the same.
	 *
	 * @param array<int, array<string, mixed>> $collections Every student collection.
	 * @param array<int, array<string, mixed>> $actions     Every student action.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/site-pupil-portal-design/specs/portal-contribution/spec.md#requirement-the-pupil-menu-is-short
	 */
	public function pages(array $collections, array $actions): array {
		$pages = [$this->overviewPage()];
		// Her timetable's page follows the overview in the menu, wherever the
		// collection sits in the list (site-pupil-portal-design T5b).
		$isTimetable = static fn (array $c): int => (int)(($c['id'] ?? '') === 'studentSessions');
		usort($collections, static fn (array $a, array $b): int => $isTimetable($b) <=> $isTimetable($a));
		foreach ($collections as $collection) {
			if (($collection['listable'] ?? true) !== true) {
				continue;
			}

			$pages[] = $this->collectionPage(collection: $collection, actions: $actions);
		}

		// Her self-assessment, where her work processes are read.
		if (in_array('studentWorkProcesses', array_column($collections, 'id'), true) === true) {
			$pages[] = (new StudentSelfAssessment())->page();
		}

		return $pages;
	}//end pages()

	/**
	 * The overview, in the order of the board (school-design vaartveld,
	 * MijnOverzicht): the greeting with today's date, homework and tests as
	 * the first thing to do, the newest grades, then the absence strip, the
	 * two quick actions and the messages. Today's timetable sits between the
	 * greeting and the homework, with a link to the whole week
	 * (site-pupil-portal-design T5b).
	 *
	 * The greeting and the highlight display are lane L2's block contract;
	 * portaliq drops a key it does not know yet.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/site-pupil-portal-design/specs/portal-contribution/spec.md#requirement-a-pupil-lands-on-an-overview-of-today
	 * @spec openspec/changes/site-pupil-portal-design/specs/portal-contribution/spec.md#requirement-the-pupil-overview-follows-the-designed-board
	 */
	private function overviewPage(): array {
		return [
			'id' => 'studentOverview',
			'label' => 'Overview',
			'icon' => 'ViewDashboard',
			'group' => ParentSitePages::GROUP,
			'home' => true,
			'blocks' => [
				['type' => 'greeting'],
				$this->timetableBlock(label: 'Your timetable today', range: 'day'),
				['type' => 'cta', 'page' => 'studentSessions', 'label' => 'Whole week'],
				self::STUDENT_HOMEWORK_TASKS_BLOCK,
				self::STUDENT_LATEST_GRADES_BLOCK,
				$this->absenceFigures(),
				['type' => 'cta', 'action' => 'createSubmission', 'label' => 'Hand in work'],
				['type' => 'cta', 'action' => 'createExcuseRequest', 'label' => 'Report an absence'],
				['type' => 'inbox', 'label' => 'Messages', 'collection' => 'studentInbox', 'limit' => 2],
			],
		];
	}//end overviewPage()

	/**
	 * The absence strip: days absent, times late, days without a report, for
	 * her latest school year.
	 *
	 * @return array<string, mixed>
	 */
	private function absenceFigures(): array {
		$days = ['one' => 'day', 'other' => 'days'];

		return [
			'type' => 'kpi',
			'collection' => 'studentAttendanceSummary',
			'label' => 'Absence this school year',
			'pick' => ['field' => 'schoolYear', 'direction' => 'desc'],
			'cards' => [
				['field' => 'absentDays', 'label' => 'Absent', 'unit' => $days],
				['field' => 'lateCount', 'label' => 'Late', 'unit' => ['one' => 'time', 'other' => 'times']],
				['field' => 'absentUnauthorisedDays', 'label' => 'Without a report', 'unit' => $days, 'highlight' => true],
			],
		];
	}//end absenceFigures()

	/**
	 * One collection's page, built the way portaliq builds a default page (its
	 * create form, its table, the selected row), in the menu or out of it.
	 * The homework page carries the hand-in form.
	 *
	 * @param array<string, mixed>             $collection The collection.
	 * @param array<int, array<string, mixed>> $actions    Every student action.
	 *
	 * @return array<string, mixed>
	 */
	private function collectionPage(array $collection, array $actions): array {
		$id = (string)$collection['id'];
		if ($id === 'studentSessions') {
			// The timetable page is the week, not a table of every lesson.
			return $this->timetablePage(id: $id);
		}

		$page = ['id' => $id, 'label' => (string)($collection['label'] ?? $id)];
		// A collection with steps is a record page: the open row's steps under the list.
		if (isset($collection['steps']) === true) {
			$page['record'] = ['collection' => $id, 'titleFields' => ['trainingCompanyName']];
		}

		$page['blocks'] = $this->pageBlocks(collection: $collection, actions: $actions);

		return $this->menuPage(page: $page);
	}//end collectionPage()

	/**
	 * A collection page's blocks: the list, its steps, then the record tail.
	 *
	 * @param array<string, mixed>            $collection The collection.
	 * @param array<int, array<string, mixed>> $actions    The audience's actions.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function pageBlocks(array $collection, array $actions): array {
		return array_merge(
			$this->listBlocks(collection: $collection, actions: $actions),
			$this->stepBlocks(collection: $collection),
			$this->recordTail(id: (string)$collection['id'])
		);
	}//end pageBlocks()

	/**
	 * The week timetable page of a student's lessons.
	 *
	 * @param string $id The collection id.
	 *
	 * @return array<string, mixed>
	 */
	private function timetablePage(string $id): array {
		return [
			'id' => $id,
			'label' => self::MENU_PAGES[$id],
			'group' => ParentSitePages::GROUP,
			'blocks' => [$this->timetableBlock(label: 'Timetable', range: 'week')],
		];
	}//end timetablePage()

	/**
	 * The blocks above and of a collection's list: the hours bar of the hour
	 * weeks, the collection's first create form, then the list itself.
	 *
	 * @param array<string, mixed>            $collection The collection.
	 * @param array<int, array<string, mixed>> $actions    The audience's actions.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function listBlocks(array $collection, array $actions): array {
		$id = (string)$collection['id'];
		$blocks = [];
		if ($id === 'studentHourWeeks') {
			$blocks[] = $this->hoursBar(collection: 'studentBpvPlacements');
		}

		$form = $this->firstCreateFor(schema: $this->formSchema(collection: $collection), actions: $actions);
		if ($form !== null) {
			$blocks[] = ['type' => 'action', 'action' => $form];
		}

		$blocks[] = ['type' => 'collection', 'collection' => $id];

		return $blocks;
	}//end listBlocks()

	/**
	 * The schema whose create form a collection page offers: homework is
	 * handed in as a submission.
	 *
	 * @param array<string, mixed> $collection The collection.
	 *
	 * @return string
	 */
	private function formSchema(array $collection): string {
		if ((string)$collection['id'] === 'studentHomework') {
			return 'submission';
		}

		return (string)($collection['schema'] ?? '');
	}//end formSchema()

	/**
	 * The step blocks of a collection with steps, none for any other.
	 *
	 * @param array<string, mixed> $collection The collection.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function stepBlocks(array $collection): array {
		if (isset($collection['steps']) === false) {
			return [];
		}

		$id = (string)$collection['id'];
		return [
			$this->nextStepBlock(id: $id),
			// Bars across, as the board's "Waar sta je?" (portaliq steps `display: bars`).
			['type' => 'steps', 'collection' => $id, 'label' => (string)($collection['steps']['label'] ?? ''), 'display' => 'bars'],
		];
	}//end stepBlocks()

	/**
	 * "Volgende stap": the current step as a highlight card (portaliq #1409),
	 * its button "Zelfbeoordeling afmaken" opening her self-assessment of this placement.
	 *
	 * @param string $id The collection id.
	 *
	 * @return array<string, mixed>
	 */
	private function nextStepBlock(string $id): array {
		return [
			'type' => 'steps',
			'collection' => $id,
			'display' => 'highlight',
			'eyebrow' => 'Next step',
			'buttonLabel' => 'Finish your self-assessment',
			'page' => StudentSelfAssessment::PAGE,
			'withRecord' => true,
		];
	}//end nextStepBlock()

	/**
	 * A collection page in the menu under its menu label, or kept out of it.
	 *
	 * @param array<string, mixed> $page The page.
	 *
	 * @return array<string, mixed>
	 */
	private function menuPage(array $page): array {
		if (isset(self::MENU_PAGES[$page['id']]) === false) {
			return $page + ['menu' => false];
		}

		return array_merge($page, ['label' => self::MENU_PAGES[$page['id']], 'group' => ParentSitePages::GROUP]);
	}//end menuPage()

	/**
	 * The blocks under a collection page's list: the selected row's detail,
	 * and for the placement first its hours and work processes, the detail
	 * then headed "Agreements" (board Detail; board-data-the-schemas-lacked).
	 *
	 * @param string $id The collection id.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/board-data-the-schemas-lacked/specs/portal-contribution/spec.md#requirement-the-placement-page-shows-the-agreements-and-the-work-processes
	 */
	private function recordTail(string $id): array {
		if ($id !== 'studentBpvPlacements') {
			return [['type' => 'detail', 'collection' => $id]];
		}

		return [
			$this->hoursBar(collection: $id),
			['type' => 'collection', 'label' => 'Work processes', 'collection' => 'studentWorkProcesses', 'recordField' => 'bpvPlacementId'],
			// "Je begeleiders": her trainer at the company and her coach at school.
			self::STUDENT_SUPERVISORS_BLOCK,
			['type' => 'collection', 'collection' => 'studentSchoolCoaches'],
			['type' => 'detail', 'collection' => $id, 'label' => 'Agreements'],
		];
	}//end recordTail()

	/**
	 * The hours bar of the board (school-design esdoornveen, MijnLijst and
	 * MijnOverzicht): approved, waiting and sent-back hours against the hours
	 * agreed for the placement, read from the placement's own totals, which
	 * HourWeekTotalRollup keeps. `display: segmented` is lane L2's contract.
	 *
	 * @param string $collection The placement collection of the audience.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/bpv/spec.md#requirement-the-hours-bar-shows-approved-waiting-and-returned-hours
	 */
	public function hoursBar(string $collection): array {
		return [
			'type' => 'kpi',
			'collection' => $collection,
			'label' => 'My BPV hours',
			'display' => 'segmented',
			'segments' => self::HOURS_BAR_SEGMENTS,
			'totalField' => 'agreedHours',
			'unit' => 'hours',
			'cards' => self::HOURS_BAR_CARDS,
		];
	}//end hoursBar()

	/**
	 * The first create action for a schema, as portaliq picks it.
	 *
	 * @param string                           $schema  The schema slug.
	 * @param array<int, array<string, mixed>> $actions The actions.
	 *
	 * @return string|null The action id.
	 */
	private function firstCreateFor(string $schema, array $actions): ?string {
		foreach ($actions as $action) {
			if (($action['type'] ?? '') === 'create' && ($action['schema'] ?? '') === $schema) {
				return (string)$action['id'];
			}
		}

		return null;
	}//end firstCreateFor()
}//end class
