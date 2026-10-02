<?php

/**
 * Learniq parent record page
 *
 * What a guardian sees when she opens one child in the portal
 * (portal-parent-child-record): the child's attendance figures, report
 * cards, report card grades, homework, attendance list and coming calendar
 * items, and the school news. Also the guardian's own calendar page, and the
 * pages of every other parent collection.
 *
 * Every collection here reads through the reverse join on the guardian's own
 * children, like every parent read. The school events and the report periods
 * that hold the holidays are joined on the child's school (`targetField:
 * schoolId`), homework on the pupils of the assignment's group
 * (`learnerRefs`, server stamped and never projected). Like the provider,
 * this class is plain: no portaliq imports, no I/O.
 *
 * @category Portal
 * @package  OCA\Learniq\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-parent-child-record/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

/**
 * The parent record page, the calendar page and their collections.
 *
 * @spec openspec/changes/portal-parent-child-record/specs/portal-contribution/spec.md
 */
class ParentRecordPage {

	private const REGISTER = 'learniq';

	/**
	 * Slot states that are a planned or held conversation, not history
	 * (lq-conference FIELDS.md).
	 */
	private const SLOT_PLANNED = ['booked', 'acknowledged', 'proposed', 'confirmed', 'completed'];

	/**
	 * The collections the record page reads beside the existing parent ones.
	 *
	 * @param array<string, mixed> $childJoin The shared reverse `via` join descriptor.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/portal-parent-child-record/specs/portal-contribution/spec.md#requirement-a-guardian-opens-one-child-and-sees-everything-about-them
	 */
	public function collections(array $childJoin): array {
		$schoolJoin = array_merge($childJoin, ['targetField' => 'schoolId']);

		return [
			$this->hidden(
				id: 'parentAttendanceSummary',
				schema: 'attendance-summary',
				scopeField: 'learnerRef',
				via: $childJoin,
				label: 'Attendance',
				fields: ['learnerRef', 'schoolYear', 'absentDays', 'absentAuthorisedDays', 'absentUnauthorisedDays', 'lateCount', 'lateMinutes']
			),
			array_merge(
				$this->hidden(
					id: 'parentHomework',
					schema: 'assignment',
					scopeField: 'learnerRefs',
					via: $childJoin,
					label: 'Homework',
					fields: ['title', 'dueAt', 'cohortId', 'lifecycle']
				),
				[
					'filter' => ['lifecycle' => 'published'],
					'columns' => [
						['field' => 'title', 'label' => 'To do'],
						['field' => 'dueAt', 'label' => 'Hand in by', 'render' => 'date'],
						['field' => 'status', 'label' => 'Status', 'render' => 'badge'],
					],
				]
			),
			$this->hidden(
				id: 'parentSubmissions',
				schema: 'submission',
				scopeField: 'learnerRef',
				via: $childJoin,
				label: 'Handed in',
				fields: ['assignmentId', 'learnerRef', 'lifecycle', 'submittedAt']
			),
			$this->hidden(
				id: 'parentSchoolEvents',
				schema: 'school-event',
				scopeField: 'schoolId',
				via: $schoolJoin,
				label: 'School events',
				fields: ['title', 'description', 'startsAt', 'endsAt', 'kind', 'audience', 'schoolId', 'cohortIds']
			),
			$this->hidden(
				id: 'parentSchoolCalendar',
				schema: 'report-period',
				scopeField: 'schoolId',
				via: $schoolJoin,
				label: 'Holidays',
				fields: ['name', 'academicYear', 'schoolId', 'holidays', 'studyDays']
			),
		];

	}//end collections()

	/**
	 * The parent pages: one page per child first, the calendar, then the page
	 * of every other listable collection as ParentPortalCollections::pages()
	 * builds it (each with its own form; page ids are the collection ids).
	 *
	 * @param array<int, array<string, mixed>> $collections Every parent collection.
	 * @param array<int, array<string, mixed>> $actions Every parent action.
	 * @param ParentPortalCollections $sections Builds the other sections' pages.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/portal-parent-child-record/specs/portal-contribution/spec.md#requirement-a-guardian-opens-one-child-and-sees-everything-about-them
	 */
	public function pages(array $collections, array $actions, ParentPortalCollections $sections): array {
		$pages = [$this->recordPage(), $this->calendarPage()];
		foreach ($sections->pages(collections: $collections, actions: $actions) as $page) {
			if (($page['id'] ?? '') !== 'parentChildren') {
				$pages[] = $page;
			}
		}

		return $pages;

	}//end pages()

	/**
	 * "My children": the list, and once a child is open the figures, report
	 * cards, grades, homework, attendance, calendar and news of that child.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/portal-parent-child-record/specs/portal-contribution/spec.md#requirement-a-guardian-opens-one-child-and-sees-everything-about-them
	 */
	private function recordPage(): array {
		return [
			'id' => 'parentChildren',
			'label' => 'My children',
			'icon' => 'AccountChild',
			'record' => ['collection' => 'parentChildren', 'titleFields' => ['givenName', 'familyName']],
			'blocks' => [
				['type' => 'collection', 'collection' => 'parentChildren'],
				$this->attendanceFigures(),
				['type' => 'collection', 'collection' => 'parentReportCards', 'recordField' => 'learnerRef'],
				['type' => 'collection', 'collection' => 'parentReportCardGrades', 'recordField' => 'learnerRef'],
				[
					'type' => 'collection',
					'collection' => 'parentHomework',
					'recordGroupsField' => 'cohortId',
					'lookups' => [
						[
							'as' => 'status',
							'collection' => 'parentSubmissions',
							'matchField' => 'assignmentId',
							'valueField' => 'lifecycle',
							'recordField' => 'learnerRef',
							'values' => [
								'submitted' => 'Handed in',
								'late' => 'Handed in late',
								'returned' => 'Marked',
								'draft' => 'Open',
							],
							'fallback' => 'Open',
						],
					],
				],
				['type' => 'collection', 'collection' => 'parentAttendance', 'recordField' => 'learnerRef'],
				['type' => 'calendar', 'label' => 'Coming up', 'sources' => $this->childSources()],
				['type' => 'news', 'label' => 'News from school', 'limit' => 3],
			],
		];

	}//end recordPage()

	/**
	 * The three figure cards Ruben chose (2026-10-02): absence this school
	 * year with and without permission, late arrivals, and unexcused absence,
	 * highlighted. The latest school year counts (lq-attendance CONTRACT.md).
	 *
	 * @return array<string, mixed>
	 */
	private function attendanceFigures(): array {
		return [
			'type' => 'kpi',
			'collection' => 'parentAttendanceSummary',
			'label' => 'Attendance',
			'recordField' => 'learnerRef',
			'pick' => ['field' => 'schoolYear', 'direction' => 'desc'],
			'caption' => ['field' => 'schoolYear', 'label' => 'School year'],
			'cards' => [
				[
					'field' => 'absentDays',
					'label' => 'Absent',
					'unit' => 'days',
					'details' => [
						['field' => 'absentAuthorisedDays', 'label' => 'with permission'],
						['field' => 'absentUnauthorisedDays', 'label' => 'without permission'],
					],
				],
				[
					'field' => 'lateCount',
					'label' => 'Late',
					'unit' => 'times',
					'details' => [['field' => 'lateMinutes', 'label' => 'minutes in total']],
				],
				[
					'field' => 'absentUnauthorisedDays',
					'label' => 'Unexcused absence',
					'unit' => 'days',
					'highlight' => true,
				],
			],
		];

	}//end attendanceFigures()

	/**
	 * The guardian's calendar of all her children: the child sources plus the
	 * last day to book a parent-teacher conversation.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/portal-parent-child-record/specs/portal-contribution/spec.md#requirement-a-guardian-sees-a-calendar-of-what-is-coming
	 */
	private function calendarPage(): array {
		$sources = $this->childSources();
		$sources[] = [
			'collection' => 'parentConferenceRounds',
			'startField' => 'bookingClosesAt',
			'titleField' => 'name',
			'kind' => 'Last day to book a conversation',
		];

		return [
			'id' => 'parentCalendar',
			'label' => 'Calendar',
			'icon' => 'Calendar',
			'blocks' => [
				['type' => 'calendar', 'label' => 'Coming up', 'sources' => $sources],
				['type' => 'news', 'label' => 'News from school', 'limit' => 5],
			],
		];

	}//end calendarPage()

	/**
	 * The calendar sources about one child: the school's events (for the
	 * whole school or the child's group), its holidays and study days, and
	 * the child's parent-teacher conversations.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function childSources(): array {
		$school = ['recordField' => 'schoolId', 'recordKey' => 'schoolId'];

		return [
			array_merge(
				[
					'collection' => 'parentSchoolEvents',
					'startField' => 'startsAt',
					'endField' => 'endsAt',
					'titleField' => 'title',
					'kind' => 'School event',
					'recordGroupsField' => 'cohortIds',
				],
				$school
			),
			array_merge(
				[
					'collection' => 'parentSchoolCalendar',
					'kind' => 'Holiday',
					'expand' => ['field' => 'holidays', 'startField' => 'startDate', 'endField' => 'endDate', 'titleField' => 'name'],
				],
				$school
			),
			array_merge(
				[
					'collection' => 'parentSchoolCalendar',
					'kind' => 'No school',
					'expand' => ['field' => 'studyDays', 'startField' => 'date', 'titleField' => 'description'],
				],
				$school
			),
			[
				'collection' => 'parentConferenceSlots',
				'startField' => 'startsAt',
				'endField' => 'endsAt',
				'titleField' => 'slotLabel',
				'title' => 'Parent-teacher conversation',
				'kind' => 'Parent evening',
				'recordField' => 'learnerRef',
				'only' => ['field' => 'lifecycle', 'in' => self::SLOT_PLANNED],
			],
		];

	}//end childSources()

	/**
	 * A collection the pages read but the menu does not list.
	 *
	 * @param string $id The collection id.
	 * @param string $schema The schema slug.
	 * @param string $scopeField The row field the join matches.
	 * @param array<string, mixed> $via The reverse join.
	 * @param string $label The heading.
	 * @param array<int, string> $fields The projected fields.
	 *
	 * @return array<string, mixed>
	 */
	private function hidden(string $id, string $schema, string $scopeField, array $via, string $label, array $fields): array {
		return [
			'id' => $id,
			'register' => self::REGISTER,
			'schema' => $schema,
			'scopeField' => $scopeField,
			'scopeClaim' => 'guardianRef',
			'via' => $via,
			'label' => $label,
			'listable' => false,
			'minTrust' => 'substantial',
			'fields' => $fields,
		];

	}//end hidden()
}//end class
