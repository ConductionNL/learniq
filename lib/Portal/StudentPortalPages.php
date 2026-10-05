<?php

/**
 * Learniq StudentPortalPages
 *
 * The pupil's pages on the site, as `site-pupil-portal-design` and the
 * approved mockup `LearniqPupil.dc.html` describe them, built only from keys
 * portaliq development keeps today: an overview on `/mijn` (`home: true`)
 * with the work to hand in first, a short menu (Overzicht, Inleveren, Cijfers,
 * Toetsen, Afwezig melden) and every other collection page kept on its route
 * but out of the menu. The timetable waits for portaliq's `via.when`.
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
	 * label: Inleveren, Cijfers, Toetsen, Afwezig melden.
	 */
	private const MENU_PAGES = [
		'studentHomework'       => 'Hand in',
		'studentGrades'         => 'Grades',
		'studentTests'          => 'Tests',
		'studentExcuseRequests' => 'Report an absence',
	];

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
			'columns' => [
				['field' => 'title', 'label' => 'To do'],
				['field' => 'dueAt', 'label' => 'Hand in by', 'render' => 'date'],
			],
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
	 * @spec openspec/changes/internship-hours/specs/bpv/spec.md#requirement-a-week-of-bpv-hours-is-a-record-of-its-own
	 */
	public function bpvCollections(): array {
		return [
		[
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
			// The school's own people and the SBB payload stay out, the same
			// projection her trainer reads.
			'fields' => [
				'learnerRef',
				'trainingCompanyName',
				'periodFrom',
				'periodTo',
				'agreedHours',
				'hoursApprovedTotal',
				'lifecycle',
			],
			'columns' => [
				['field' => 'trainingCompanyName', 'label' => 'Training company'],
				['field' => 'hoursApprovedTotal', 'label' => 'Hours approved'],
				['field' => 'agreedHours', 'label' => 'Agreed hours'],
			],
		],
		[
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
			'fields' => [
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
			],
			'columns' => [
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
			],
		],
		];

	}//end bpvCollections()

	/**
	 * She enters a week of her own placement's hours.
	 *
	 * Only the placement, the week and the hours: who she is, when she sent it,
	 * which school it belongs to, the hours her trainer approves and the state
	 * are all server-written (HourWeekSubmissionStamp, PortalHourWeekApproval).
	 *
	 * @return array<string, mixed> The create action.
	 *
	 * @spec openspec/changes/internship-hours/specs/bpv/spec.md#requirement-a-week-of-bpv-hours-is-a-record-of-its-own
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
		'crossRefs' => [
			'bpvPlacementId' => [
				'register' => self::REGISTER,
				'schema' => 'bpv-placement',
				'scopeField' => 'learnerRef',
				'scopeClaim' => 'learnerRef',
				'required' => true,
			],
		],
		// And she picks it from her own placements rather than typing a
		// uuid, the way her guardian picks a child.
		'optionsProviders' => [
			'bpvPlacementId' => [
				'type' => 'collection',
				'register' => self::REGISTER,
				'schema' => 'bpv-placement',
				'labelField' => 'trainingCompanyName',
				'valueField' => 'id',
			],
		],
		'fieldConfigs' => [
			'bpvPlacementId' => ['label' => 'Your placement', 'required' => true],
			'isoWeek' => ['label' => 'The week, as 2026-W39', 'required' => true],
			'hoursSubmitted' => ['label' => 'Hours you worked', 'required' => true],
		],
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
		foreach ($collections as $collection) {
			if (($collection['listable'] ?? true) !== true) {
				continue;
			}

			$pages[] = $this->collectionPage(collection: $collection, actions: $actions);
		}

		return $pages;
	}//end pages()

	/**
	 * The overview, in the order of the board (school-design vaartveld,
	 * MijnOverzicht): the greeting with today's date, homework and tests as
	 * the first thing to do, the newest grades, then the absence strip, the
	 * two quick actions and the messages. Today's timetable belongs between
	 * the greeting and the homework; it waits for the pupil's sessions
	 * (site-pupil-portal-design T1, T5b).
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
				[
					'type' => 'tasks',
					'label' => 'Homework and tests',
					'display' => 'highlight',
					'collection' => 'studentHomework',
					'dueField' => 'dueAt',
					'titleFields' => ['title'],
				],
				[
					'type' => 'collection',
					'label' => 'Latest grades',
					'collection' => 'studentGrades',
					'limit' => 3,
					'sort' => ['field' => 'gradedAt', 'direction' => 'desc'],
				],
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
		$schema = (string)($collection['schema'] ?? '');
		if ($id === 'studentHomework') {
			$schema = 'submission';
		}

		$blocks = [];
		$form = $this->firstCreateFor(schema: $schema, actions: $actions);
		if ($form !== null) {
			$blocks[] = ['type' => 'action', 'action' => $form];
		}

		$blocks[] = ['type' => 'collection', 'collection' => $id];
		$blocks[] = ['type' => 'detail', 'collection' => $id];

		$page = ['id' => $id, 'label' => (string)($collection['label'] ?? $id), 'blocks' => $blocks];
		if (isset(self::MENU_PAGES[$id]) === false) {
			return $page + ['menu' => false];
		}

		return array_merge($page, ['label' => self::MENU_PAGES[$id], 'group' => ParentSitePages::GROUP]);
	}//end collectionPage()

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
