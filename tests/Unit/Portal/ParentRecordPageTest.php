<?php

/**
 * Learniq parent record page tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-parent-child-record/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Portal;

use OCA\Learniq\Portal\PortalContributionProvider;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * What a guardian sees when she opens one child, and the calendar, as
 * learniq declares them to portaliq; and the rows those collections read
 * against the real register.
 *
 * @spec openspec/changes/portal-parent-child-record/specs/portal-contribution/spec.md
 */
class ParentRecordPageTest extends TestCase {

	/**
	 * The parent manifest.
	 *
	 * @var array<string, mixed>
	 */
	private array $manifest;

	/**
	 * The register schemas by component name.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $schemas;

	/**
	 * Build the manifest and read the register.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->manifest = (new PortalContributionProvider())->getContribution(['audience' => 'parent']);
		$this->schemas = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/learniq_register.json'), true)['components']['schemas'];
	}//end setUp()

	/**
	 * A collection of the manifest by id.
	 *
	 * @param string $id The id.
	 *
	 * @return array<string, mixed>
	 */
	private function collection(string $id): array {
		return array_column($this->manifest['collections'], null, 'id')[$id];
	}//end collection()

	/**
	 * "My children" is the record page of the children list, first in the
	 * menu, with the figures, report cards, grades, homework, attendance,
	 * calendar and news of the open child.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-parent-child-record/specs/portal-contribution/spec.md#requirement-a-guardian-opens-one-child-and-sees-everything-about-them
	 */
	public function testMyChildrenIsTheRecordPageOfEachChild(): void {
		$page = array_column($this->manifest['pages'], null, 'id')['parentChildren'];

		self::assertSame('parentChildren', $page['id']);
		self::assertSame(['collection' => 'parentChildren', 'titleFields' => ['givenName', 'familyName']], $page['record']);
		self::assertSame(
			['collection', 'kpi', 'collection', 'collection', 'collection', 'collection', 'collection', 'calendar', 'news'],
			array_column($page['blocks'], 'type')
		);
		self::assertSame(
			['parentChildren', 'parentAttendanceSummary', 'parentReportCards', 'parentReportSubjectGrades', 'parentReportCardGrades', 'parentHomework', 'parentAttendance'],
			array_values(array_filter(array_column($page['blocks'], 'collection')))
		);

		// Every child-bound block narrows to the open child.
		$blocks = array_column(array_slice($page['blocks'], 1), null, 'collection');
		foreach (['parentAttendanceSummary', 'parentReportCards', 'parentReportSubjectGrades', 'parentReportCardGrades', 'parentAttendance'] as $id) {
			self::assertSame('learnerRef', $blocks[$id]['recordField'], $id);
		}

		self::assertSame('cohortId', $blocks['parentHomework']['recordGroupsField']);
	}//end testMyChildrenIsTheRecordPageOfEachChild()

	/**
	 * The three figure cards: absence with and without permission, late
	 * arrivals with minutes, unexcused absence highlighted, from the latest
	 * school year of the attendance summary.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-parent-child-record/specs/portal-contribution/spec.md#requirement-a-guardian-reads-her-childs-attendance-figures
	 */
	public function testTheFigureCardsReadTheAttendanceSummary(): void {
		$kpi = (array_column($this->manifest['pages'], null, 'id')['parentChildren'])['blocks'][1];
		$summary = $this->schemas['AttendanceSummary']['properties'];

		self::assertSame(['field' => 'schoolYear', 'direction' => 'desc'], $kpi['pick']);
		self::assertSame(['absentDays', 'lateCount', 'absentUnauthorisedDays'], array_column($kpi['cards'], 'field'));
		self::assertSame(['absentAuthorisedDays', 'absentUnauthorisedDays'], array_column($kpi['cards'][0]['details'], 'field'));
		self::assertSame(['lateMinutes'], array_column($kpi['cards'][1]['details'], 'field'));
		self::assertTrue($kpi['cards'][2]['highlight']);

		// Every field a card reads exists on the summary and is projected.
		$projected = $this->collection('parentAttendanceSummary')['fields'];
		foreach (['schoolYear', 'absentDays', 'absentAuthorisedDays', 'absentUnauthorisedDays', 'lateCount', 'lateMinutes', 'learnerRef'] as $field) {
			self::assertArrayHasKey($field, $summary, $field);
			self::assertContains($field, $projected, $field);
		}
	}//end testTheFigureCardsReadTheAttendanceSummary()

	/**
	 * A card names its unit singular and plural, so a guardian reads "1 dag"
	 * and "5 dagen", never "1 dagen" (portaliq kpi-unit-singular-and-plural).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parent-figures-singular-and-plural/specs/portal-contribution/spec.md#requirement-the-figure-cards-count-in-singular-and-plural
	 */
	public function testTheFigureCardsCountInSingularAndPlural(): void {
		$cards = (array_column($this->manifest['pages'], null, 'id')['parentChildren'])['blocks'][1]['cards'];

		self::assertSame(['one' => 'day', 'other' => 'days'], $cards[0]['unit']);
		self::assertSame(['one' => 'time', 'other' => 'times'], $cards[1]['unit']);
		self::assertSame(['one' => 'minute in total', 'other' => 'minutes in total'], $cards[1]['details'][0]['label']);
		self::assertSame(['one' => 'day', 'other' => 'days'], $cards[2]['unit']);
	}//end testTheFigureCardsCountInSingularAndPlural()

	/**
	 * Homework is the published assignments of the child's group, scoped by
	 * the server-stamped pupils list, which never leaves for the portal.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-parent-child-record/specs/portal-contribution/spec.md#requirement-a-guardian-reads-the-homework-of-their-childs-group
	 */
	public function testHomeworkIsScopedByThePupilsOfTheGroup(): void {
		$homework = $this->collection('parentHomework');

		self::assertSame('learnerRefs', $homework['scopeField']);
		self::assertSame('scopeField', $homework['via']['match']);
		self::assertSame(['lifecycle' => 'published'], $homework['filter']);
		self::assertNotContains('learnerRefs', $homework['fields'], 'no guardian reads another pupil\'s uuid');
		self::assertTrue($this->schemas['Assignment']['properties']['learnerRefs']['readOnly']);

		$lookup = (array_column($this->manifest['pages'], null, 'id')['parentChildren'])['blocks'][5]['lookups'][0];
		self::assertSame('parentSubmissions', $lookup['collection']);
		self::assertSame('learnerRef', $lookup['recordField']);
		self::assertSame('Open', $lookup['fallback']);
	}//end testHomeworkIsScopedByThePupilsOfTheGroup()

	/**
	 * School events and holidays are joined on the child's school, so a
	 * guardian reads her children's schools only; conversations only in a
	 * planned state.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-parent-child-record/specs/portal-contribution/spec.md#requirement-a-guardian-sees-a-calendar-of-what-is-coming
	 */
	public function testTheCalendarJoinsTheChildsSchool(): void {
		foreach (['parentSchoolEvents', 'parentSchoolCalendar'] as $id) {
			$collection = $this->collection($id);
			self::assertSame('schoolId', $collection['scopeField'], $id);
			self::assertSame(['register' => 'learniq', 'schema' => 'learner-profile', 'scopeField' => 'guardianRefs', 'targetField' => 'schoolId', 'match' => 'scopeField'], $collection['via'], $id);
			self::assertFalse($collection['listable'], $id);
		}

		self::assertContains('schoolId', $this->collection('parentChildren')['fields']);

		$sources = (array_column($this->manifest['pages'], null, 'id')['parentChildren'])['blocks'][7]['sources'];
		self::assertSame(['parentSchoolEvents', 'parentSchoolCalendar', 'parentSchoolCalendar', 'parentConferenceSlots'], array_column($sources, 'collection'));
		self::assertSame('cohortIds', $sources[0]['recordGroupsField']);
		self::assertSame(['field' => 'holidays', 'startField' => 'startDate', 'endField' => 'endDate', 'titleField' => 'name'], $sources[1]['expand']);
		self::assertSame(['booked', 'acknowledged', 'proposed', 'confirmed', 'completed'], $sources[3]['only']['in']);

		// The fields each source reads exist on the real schemas.
		$holiday = $this->schemas['ReportPeriod']['properties']['holidays']['items']['properties'];
		self::assertArrayHasKey('startDate', $holiday);
		self::assertArrayHasKey('studyDays', $this->schemas['ReportPeriod']['properties']);
		foreach (['startsAt', 'endsAt', 'title', 'cohortIds', 'schoolId'] as $field) {
			self::assertArrayHasKey($field, $this->schemas['SchoolEvent']['properties'], $field);
		}

		$calendarPage = array_column($this->manifest['pages'], null, 'id')['parentCalendar'];
		self::assertSame('parentConferenceRounds', end($calendarPage['blocks'][0]['sources'])['collection']);
	}//end testTheCalendarJoinsTheChildsSchool()

	/**
	 * Every other listable collection keeps the page portaliq would have
	 * given it: its create action, its table and its detail.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-parent-child-record/specs/portal-contribution/spec.md#requirement-a-guardian-opens-one-child-and-sees-everything-about-them
	 */
	public function testEveryOtherSectionKeepsItsPage(): void {
		$pages = array_column($this->manifest['pages'], null, 'id');
		foreach ($this->manifest['collections'] as $collection) {
			if (($collection['listable'] ?? true) !== true || $collection['id'] === 'parentChildren') {
				self::assertTrue(isset($pages[$collection['id']]) === false || $collection['id'] === 'parentChildren', $collection['id']);
				continue;
			}

			self::assertArrayHasKey($collection['id'], $pages);
		}

		self::assertSame(
			[['type' => 'action', 'action' => 'createExcuseRequest'], ['type' => 'collection', 'collection' => 'parentExcuseRequests'], ['type' => 'detail', 'collection' => 'parentExcuseRequests']],
			$pages['parentExcuseRequests']['blocks']
		);

		// The conference sections keep their own forms (direct-conference-booking).
		self::assertSame(['type' => 'action', 'action' => 'bookConferenceSlot'], $pages['parentConferenceFreeSlots']['blocks'][0]);
		self::assertSame(['type' => 'action', 'action' => 'createConferenceSignup'], $pages['parentConferenceSignups']['blocks'][0]);
		// site-guardian-portal-design: the overview, the per-child pages and the
		// calendar first; every collection page after them, out of the menu.
		self::assertSame(
			['parentOverview', 'parentChildren', 'parentAbsence', 'parentConferences', 'parentCalendar'],
			array_slice(array_column($this->manifest['pages'], 'id'), 0, 5)
		);
		self::assertCount(1, array_filter($this->manifest['pages'], static fn (array $page): bool => $page['id'] === 'parentChildren'));
		foreach (array_slice($this->manifest['pages'], 5) as $page) {
			self::assertFalse($page['menu'], $page['id'] . ' leaves the menu');
		}
	}//end testEveryOtherSectionKeepsItsPage()

	/**
	 * The rows the portal reads pass the real schemas: a school event as the
	 * staff page saves it, a report period with its school, an assignment
	 * with its stamped pupils.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-parent-child-record/specs/portal-contribution/spec.md#requirement-a-guardian-sees-a-calendar-of-what-is-coming
	 */
	public function testTheRowsPassTheRealSchemas(): void {
		$validator = new Validator();
		$tenant = '00000000-0000-4000-8000-000000000000';
		$school = 'ee010001-0000-4000-8000-000000000001';
		$payloads = [
			'SchoolEvent' => [
				['title' => 'Sportdag', 'startsAt' => '2026-10-14', 'endsAt' => null, 'kind' => 'sports-day', 'audience' => 'school', 'schoolId' => $school, 'cohortIds' => [], 'tenant_id' => $tenant],
				['title' => 'Schoolreis', 'description' => 'Neem een lunchpakket mee.', 'startsAt' => '2026-10-20T08:30:00+02:00', 'endsAt' => '2026-10-20T16:00:00+02:00', 'kind' => 'trip', 'audience' => 'groups', 'schoolId' => $school, 'cohortIds' => ['ee010003-0000-4000-8000-000000000007'], 'tenant_id' => $tenant],
			],
			'ReportPeriod' => [
				['name' => 'Rapport 1', 'academicYear' => '2026-2027', 'periodCode' => '1', 'startDate' => '2026-08-17', 'endDate' => '2027-01-29', 'curriculumPlanIds' => [], 'cohortIds' => [], 'schoolId' => $school, 'holidays' => [['name' => 'Herfstvakantie', 'startDate' => '2026-10-19', 'endDate' => '2026-10-23']], 'tenant_id' => $tenant],
			],
		];

		foreach ($payloads as $schema => $rows) {
			$json = (string)json_encode($this->validatable(schema: $this->schemas[$schema]));
			foreach ($rows as $row) {
				$result = $validator->validate(json_decode((string)json_encode($row)), $json);
				self::assertTrue($result->isValid(), $schema.': '.json_encode($result->error()?->message()));
			}
		}

		$event = $payloads['SchoolEvent'][0];
		unset($event['schoolId']);
		$json = (string)json_encode($this->validatable(schema: $this->schemas['SchoolEvent']));
		self::assertFalse($validator->validate(json_decode((string)json_encode($event)), $json)->isValid(), 'control: an event names its school');
	}//end testTheRowsPassTheRealSchemas()

	/**
	 * The schema without OpenRegister's own keys, which a JSON Schema validator cannot resolve,
	 * with `nullable` written the JSON Schema way.
	 *
	 * @param array<string, mixed> $schema A register schema.
	 *
	 * @return array<string, mixed>
	 */
	private function validatable(array $schema): array {
		$clean = [];
		foreach ($schema as $key => $value) {
			if ($key === '$ref' || $key === 'authorization' || str_starts_with((string)$key, 'x-') === true || in_array($key, ['slug', 'icon', 'version', 'readOnly'], true) === true) {
				continue;
			}

			$clean[$key] = $value;
			if (is_array($value) === true && $key !== 'required' && $key !== 'enum') {
				$clean[$key] = $this->validatable(schema: $value);
			}
		}

		// OpenRegister reads OpenAPI's `nullable`; JSON Schema spells it as a type list.
		if (($clean['nullable'] ?? false) === true && is_string($clean['type'] ?? null) === true) {
			$clean['type'] = [$clean['type'], 'null'];
			if (isset($clean['enum']) === true) {
				$clean['enum'][] = null;
			}
		}

		unset($clean['nullable']);
		return $clean;
	}//end validatable()
}//end class
