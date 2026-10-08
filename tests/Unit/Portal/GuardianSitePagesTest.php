<?php

/**
 * The guardian's and the pupil's pages and forms on the site.
 *
 * Asserted on the manifest learniq declares. Portaliq's own normalisers keep
 * every key used here on portaliq development (69de37c) and PR #1139; that was
 * checked by running these manifests through them, and the live check on the
 * site is the proof.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Portal
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
 * @spec openspec/changes/site-guardian-portal-design/specs/portal-contribution/spec.md#requirement-a-guardian-lands-on-an-overview-of-one-child-at-a-time
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Portal;

use OCA\Learniq\Portal\PortalContributionProvider;
use OCA\Learniq\Portal\StudentPortalPages;
use OCA\Learniq\Portal\TrainerSitePages;
use PHPUnit\Framework\TestCase;

/**
 * Pages, menu marks and form declarations of the parent and student audiences.
 */
class GuardianSitePagesTest extends TestCase {

	/**
	 * One audience's manifest.
	 *
	 * @param string $audience The audience.
	 *
	 * @return array<string, mixed>
	 */
	private static function manifest(string $audience): array {
		return (new PortalContributionProvider())->getContribution(['audience' => $audience]);
	}//end manifest()

	/**
	 * One audience's pages by id.
	 *
	 * @param string $audience The audience.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function pages(string $audience): array {
		return array_column(self::manifest(audience: $audience)['pages'], null, 'id');
	}//end pages()

	/**
	 * One audience's actions by id.
	 *
	 * @param string $audience The audience.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function actions(string $audience): array {
		return array_column(self::manifest(audience: $audience)['actions'], null, 'id');
	}//end actions()

	/**
	 * The guardian lands on an overview that switches between her children,
	 * with open tasks first and only blocks whose references exist.
	 *
	 * @return void
	 */
	public function testTheGuardianOverviewIsHomeAndSwitchesChildren(): void {
		$manifest = self::manifest(audience: 'parent');
		$overview = self::pages(audience: 'parent')['parentOverview'];

		self::assertTrue($overview['home']);
		self::assertSame('My space', $overview['group']);
		self::assertSame(['collection' => 'parentChildren', 'titleFields' => ['givenName'], 'subtitleFields' => ['groupLabel']], $overview['records']);
		self::assertSame('parentOverview', $manifest['pages'][0]['id']);
		// The board's order (school-design wilgenboom MijnOverzicht): greeting, the task, the children, news, this month, and nothing else.
		self::assertSame(['greeting', 'tasks', 'collection', 'news', 'calendar'], array_column($overview['blocks'], 'type'));
		// Lane L2's greeting: `label` plus exactly one target.
		self::assertSame(['type' => 'greeting', 'label' => 'Report absent', 'action' => 'createExcuseRequest'], $overview['blocks'][0]);
		self::assertSame(['groupLabel'], $overview['blocks'][2]['subtitleFields']);
		// "Deze maand" is this month only, and a booked conversation reads as words with the teacher under it.
		self::assertSame('month', $overview['blocks'][4]['range']);
		$slots = array_values(array_filter($overview['blocks'][4]['sources'], static fn (array $src): bool => $src['collection'] === 'parentConferenceSlots'))[0];
		self::assertArrayNotHasKey('titleField', $slots);
		self::assertSame(['Parent-teacher conversation', 'teacherName'], [$slots['title'], $slots['metaField']]);
		self::assertSame(['type' => 'tasks', 'label' => 'Still to do', 'display' => 'highlight', 'collection' => 'parentConferenceRounds', 'dueField' => 'bookingClosesAt', 'titleFields' => ['name'], 'buttonLabel' => 'Pick a time'], $overview['blocks'][1]);
		self::assertSame(['parentChildren', 'cards'], [$overview['blocks'][2]['collection'], $overview['blocks'][2]['display']]);
		// The child's chip is derived from the guardian's own reports, every field it reads projected.
		$status = $overview['blocks'][2]['status'];
		self::assertSame(['parentExcuseRequests', 'Reported sick', 'At school'], [$status['collection'], $status['label'], $status['otherLabel']]);
		$reports = array_column($manifest['collections'], null, 'id')['parentExcuseRequests'];
		foreach ([$status['matchField'], $status['fromField'], $status['toField'], $status['only']['field']] as $field) {
			self::assertContains($field, $reports['fields'], $field);
		}
		self::assertSame('tiles', $overview['blocks'][4]['display']);
		$absence = self::pages(audience: 'parent')['parentAbsence'];
		$reports = array_values(array_filter($absence['blocks'], static fn (array $b): bool => ($b['collection'] ?? '') === 'parentExcuseRequests'))[0];
		self::assertSame(['rows', 'dateFrom', 'lifecycle', 'decidedBy'], [$reports['display'], $reports['dateField'], $reports['statusField'], $reports['statusNoteField']]);

		$collections = array_column($manifest['collections'], null, 'id');
		$actionIds = array_column($manifest['actions'], 'id');
		foreach ($overview['blocks'] as $block) {
			if (isset($block['collection']) === true) {
				self::assertArrayHasKey($block['collection'], $collections);
			}

			if (isset($block['action']) === true) {
				self::assertContains($block['action'], $actionIds);
			}
		}

		// The task's due field and title are projected, or portaliq drops them.
		self::assertContains('bookingClosesAt', $collections['parentConferenceRounds']['fields']);
		self::assertContains('name', $collections['parentConferenceRounds']['fields']);
	}//end testTheGuardianOverviewIsHomeAndSwitchesChildren()

	/**
	 * Absence, conversations and grades are listed once per child; every
	 * collection page keeps its route and leaves the menu.
	 *
	 * @return void
	 */
	public function testTheGuardianMenuIsGroupedPerChild(): void {
		$pages = self::pages(audience: 'parent');
		foreach (['parentChildren', 'parentAbsence', 'parentConferences'] as $id) {
			self::assertSame('parentChildren', $pages[$id]['perRecord'], $id);
			self::assertSame('parentChildren', $pages[$id]['record']['collection'], $id);
		}

		self::assertSame('My space', $pages['parentCalendar']['group']);
		// The child's own page sits in "Mijn kinderen" with the group line under the name (lane L1).
		self::assertSame('My children', $pages['parentChildren']['group']);
		self::assertSame(['groupLabel'], $pages['parentChildren']['records']['subtitleFields']);
		// The conversations page counts the rounds still open (lane L1 badge).
		self::assertSame('parentConferenceRounds', $pages['parentConferences']['badge']['collection']);
		foreach (['parentExcuseRequests', 'parentConferenceFreeSlots', 'parentConferenceSignups', 'parentGrades'] as $id) {
			self::assertFalse($pages[$id]['menu'], $id);
		}

		$conferences = array_column($pages['parentConferences']['blocks'], 'action');
		self::assertSame(['bookConferenceSlot', 'createConferenceSignup'], array_values(array_filter($conferences)));
	}//end testTheGuardianMenuIsGroupedPerChild()

	/**
	 * "Kies een tijd" requires the time, "Stuur uw voorkeur" the round, and
	 * the preference form asks for no time at all.
	 *
	 * @return void
	 */
	public function testTheTwoBookingFormsNameTheirRequiredFields(): void {
		$actions = self::actions(audience: 'parent');

		self::assertSame('Choose a time', $actions['bookConferenceSlot']['label']);
		self::assertSame(['learnerRef', 'slotId'], $actions['bookConferenceSlot']['requiredFields']);
		self::assertSame('Send your preference', $actions['createConferenceSignup']['label']);
		self::assertSame(['conferenceRoundId', 'learnerRef'], $actions['createConferenceSignup']['requiredFields']);
		self::assertNotContains('slotId', $actions['createConferenceSignup']['fields']);

		// portaliq keeps requiredFields only among the action's own fields.
		foreach (['bookConferenceSlot', 'createConferenceSignup'] as $id) {
			self::assertSame([], array_values(array_diff($actions[$id]['requiredFields'], $actions[$id]['fields'])), $id);
		}
	}//end testTheTwoBookingFormsNameTheirRequiredFields()

	/**
	 * The absence form offers two cards and "Een andere reden", named days
	 * for both dates, and its own words for a missing last day.
	 *
	 * @return void
	 */
	public function testTheAbsenceFormUsesCardsAndNamedDays(): void {
		$configs = self::actions(audience: 'parent')['createExcuseRequest']['fieldConfigs'];

		self::assertSame('choices', $configs['reasonKind']['widget']);
		self::assertSame(['illness', 'medical-appointment'], $configs['reasonKind']['choiceOptions']);
		self::assertSame('Another reason', $configs['reasonKind']['otherLabel']);
		self::assertSame('dateChoices', $configs['dateFrom']['widget']);
		self::assertSame('dateChoices', $configs['dateTo']['widget']);
		self::assertSame('Choose the last day your child is absent.', $configs['dateTo']['requiredMessage']);

		// Every card is a real absence kind, so portaliq keeps it.
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'), true);
		$kinds = $register['components']['schemas']['ExcuseRequest']['properties']['reasonKind']['enum'];
		self::assertSame([], array_values(array_diff($configs['reasonKind']['choiceOptions'], $kinds)));
	}//end testTheAbsenceFormUsesCardsAndNamedDays()

	/**
	 * The child's page draws the latest report as a bar per subject, over
	 * fields the collection projects (portaliq drops a key whose field it does not).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/school-portals-use-the-new-blocks/specs/portal-contribution/spec.md#requirement-the-latest-report-reads-as-one-bar-per-subject
	 */
	public function testTheChildPageDrawsTheLatestReportAsBars(): void {
		$manifest = self::manifest(audience: 'parent');
		$page     = array_column($manifest['pages'], null, 'id')['parentChildren'];
		$bars     = array_values(array_filter($page['blocks'], static fn (array $b): bool => ($b['display'] ?? '') === 'bars'))[0];
		$rows     = array_column($manifest['collections'], null, 'id')['parentReportSubjectGrades'];

		self::assertSame(['parentReportSubjectGrades', 'learnerRef'], [$bars['collection'], $bars['recordField']]);
		foreach (['labelField', 'valueField', 'captionField', 'noteField'] as $key) {
			self::assertContains($bars[$key], $rows['fields'], $key);
		}

		self::assertSame('report-subject-grade', $rows['schema']);
		self::assertSame('learnerRef', $rows['scopeField']);
		self::assertSame('guardianRef', $rows['scopeClaim']);
	}//end testTheChildPageDrawsTheLatestReportAsBars()

	/**
	 * The absence form sums up the answers in one sentence and confirms what
	 * happens next (board MobielDetail: "U meldt: Sami is vandaag ziek.").
	 *
	 * @return void
	 *
	 * @spec openspec/changes/school-portals-use-the-new-blocks/specs/portal-contribution/spec.md#requirement-the-absence-form-says-in-one-sentence-what-the-guardian-reports
	 */
	public function testTheAbsenceFormSumsUpAndConfirms(): void {
		$action = self::actions(audience: 'parent')['createExcuseRequest'];

		preg_match_all('/\{([A-Za-z]+)\}/', $action['summary']['template'], $named);
		self::assertSame([], array_diff($named[1], $action['fields']), 'the sentence names only fields of the form, or portaliq drops it');
		self::assertSame('ill', $action['summary']['phrases']['reasonKind']['illness']);
		self::assertSame([], array_diff(array_keys($action['summary']['phrases']['reasonKind']), ['illness', 'medical-appointment', 'family-circumstance', 'religious-observance', 'bereavement', 'other']));
		self::assertNotSame('', $action['confirmation']['title']);
	}//end testTheAbsenceFormSumsUpAndConfirms()

	/**
	 * The pupil lands on an overview with the work to hand in first, and the
	 * menu holds only Rooster, Inleveren, Cijfers, Toetsen and Afwezig melden.
	 *
	 * @return void
	 */
	public function testThePupilOverviewAndShortMenu(): void {
		$pages = self::pages(audience: 'student');
		$overview = $pages['studentOverview'];

		self::assertTrue($overview['home']);
		// The board's order (school-design vaartveld MijnOverzicht): greeting, today's timetable with
		// the whole week, homework and tests, grades, absence.
		self::assertSame(['greeting', 'calendar', 'cta', 'tasks', 'collection', 'kpi', 'cta', 'cta', 'inbox'], array_column($overview['blocks'], 'type'));
		self::assertSame(['timetable', 'day'], [$overview['blocks'][1]['display'], $overview['blocks'][1]['range']]);
		self::assertSame('studentSessions', $overview['blocks'][2]['page']);
		self::assertSame('studentHomework', $overview['blocks'][3]['collection']);
		self::assertSame('dueAt', $overview['blocks'][3]['dueField']);
		self::assertSame('highlight', $overview['blocks'][3]['display']);
		self::assertSame(['studentGrades', 3], [$overview['blocks'][4]['collection'], $overview['blocks'][4]['limit']]);
		self::assertSame('studentAttendanceSummary', $overview['blocks'][5]['collection']);
		self::assertSame(['absentDays', 'lateCount', 'absentUnauthorisedDays'], array_column($overview['blocks'][5]['cards'], 'field'));

		$inMenu = [];
		foreach ($pages as $id => $page) {
			if (($page['menu'] ?? true) === true && $id !== 'studentOverview') {
				$inMenu[$id] = $page['label'];
			}
		}

		self::assertSame(
			['studentSessions' => 'Timetable', 'studentGrades' => 'Grades', 'studentExcuseRequests' => 'Report an absence', 'studentTests' => 'Tests', 'studentHomework' => 'Hand in'],
			$inMenu
		);

		// The hand-in form sits on the homework page; the absence form on its own page.
		self::assertSame(['type' => 'action', 'action' => 'createSubmission'], $pages['studentHomework']['blocks'][0]);
		self::assertSame(['type' => 'action', 'action' => 'createExcuseRequest'], $pages['studentExcuseRequests']['blocks'][0]);
		self::assertFalse($pages['studentEnrolments']['menu']);
	}//end testThePupilOverviewAndShortMenu()

	/**
	 * Every field of the pupil's two forms carries a label.
	 *
	 * WHY THIS TEST EXISTS. Measured on a live instance (pupil-flows.spec.ts):
	 * her absence form drew `dateFrom`, `dateTo`, `reason` and `reasonKind` as
	 * their own field names, because portaliq names a field it was given no
	 * label for after the field itself. Her guardian's identical form reads
	 * Dutch sentences. A form a twelve-year-old cannot read is not a form.
	 *
	 * @return void
	 */
	public function testThePupilsFormsLabelEveryFieldTheyAskFor(): void {
		$actions = self::actions(audience: 'student');

		foreach (['createExcuseRequest', 'createSubmission'] as $id) {
			$action = $actions[$id];
			$configs = ($action['fieldConfigs'] ?? []);
			foreach ($action['fields'] as $field) {
				self::assertNotSame(
					'',
					(string)($configs[$field]['label'] ?? ''),
					$id . ' leaves ' . $field . ' without a label, so the form shows the field name'
				);
			}
		}

		// The same widgets her guardian gets, addressed to her.
		$absence = $actions['createExcuseRequest']['fieldConfigs'];
		self::assertSame('dateChoices', $absence['dateFrom']['widget']);
		self::assertSame('choices', $absence['reasonKind']['widget']);
		self::assertSame('Choose the last day you are absent.', $absence['dateTo']['requiredMessage']);
	}//end testThePupilsFormsLabelEveryFieldTheyAskFor()

	/**
	 * A collection that is not listable gets no page, as portaliq builds none.
	 *
	 * @return void
	 */
	public function testAnUnlistedCollectionGetsNoPage(): void {
		$pages = (new StudentPortalPages())->pages(
			collections: [
				['id' => 'studentGrades', 'schema' => 'grade-entry', 'label' => 'My grades'],
				['id' => 'studentHidden', 'schema' => 'grade-entry', 'label' => 'Hidden', 'listable' => false],
			],
			actions: []
		);

		self::assertSame(['studentOverview', 'studentGrades'], array_column($pages, 'id'));
	}//end testAnUnlistedCollectionGetsNoPage()

	/**
	 * The trainer lands on an overview with the weeks of hours waiting for
	 * her, her placements, her last assessments and the three things she may
	 * do; every section keeps a page.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/internship-hours/specs/bpv/spec.md#requirement-hours-are-shown-against-the-hours-that-were-agreed
	 */
	public function testTheTrainerOverviewAndMenu(): void {
		$manifest = self::manifest(audience: 'praktijkopleider');
		$pages = array_column($manifest['pages'], null, 'id');
		$overview = $pages['poOverview'];

		self::assertTrue($overview['home']);
		self::assertSame('My space', $overview['group']);
		self::assertSame(
			['greeting', 'tasks', 'collection', 'collection', 'cta', 'cta', 'cta', 'inbox'],
			array_column($overview['blocks'], 'type')
		);
		self::assertSame(['field' => 'assessedAt', 'direction' => 'desc'], $overview['blocks'][3]['sort']);
		self::assertSame(3, $overview['blocks'][3]['limit']);

		// internship-hours: what is waiting for her comes first, oldest
		// submission at the top, and the week is what she reads on the row.
		$waiting = $overview['blocks'][1];
		self::assertSame('poHourWeeks', $waiting['collection']);
		self::assertSame('highlight', $waiting['display']);
		self::assertSame('submittedAt', $waiting['dueField']);
		self::assertSame(['isoWeek'], $waiting['titleFields']);

		// site-workplace-trainer-portal-design T6b: the placement cards carry their heading (lane L2's
		// contract gives a collection block a label); the assessments still stand on their columns.
		self::assertSame('My BPV placements', $overview['blocks'][2]['label']);
		self::assertArrayNotHasKey('label', $overview['blocks'][3]);

		self::assertSame(
			['poOverview', 'poBpvPlacements', 'poSharedPortfolios', 'poWerkprocesAssessments', 'poHourWeeks'],
			array_keys($pages)
		);

		// Her own assessments, matched on the claim the create action stamps.
		$assessments = array_column($manifest['collections'], null, 'id')['poWerkprocesAssessments'];
		self::assertSame('assessorId', $assessments['scopeField']);
		self::assertSame('practicalTrainerId', $assessments['scopeClaim']);
		self::assertSame('low', $assessments['minTrust']);
		self::assertSame('Assessments I wrote', $assessments['label']);
	}//end testTheTrainerOverviewAndMenu()

	/**
	 * Her hours page opens with the bar of the board: approved, waiting and
	 * sent back against the agreed hours, over fields the placement projects.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/internship-hours/specs/bpv/spec.md#requirement-the-hours-bar-shows-approved-waiting-and-returned-hours
	 */
	public function testTheStudentHoursPageOpensWithTheBar(): void {
		$manifest = self::manifest(audience: 'student');
		$page = array_column($manifest['pages'], null, 'id')['studentHourWeeks'];
		$placements = array_column($manifest['collections'], null, 'id')['studentBpvPlacements'];

		$bar = $page['blocks'][0];
		self::assertSame(['kpi', 'studentBpvPlacements', 'segmented', 'agreedHours'], [$bar['type'], $bar['collection'], $bar['display'], $bar['totalField']]);
		self::assertSame(['hoursApprovedTotal', 'hoursWaitingTotal', 'hoursReturnedTotal'], array_column($bar['segments'], 'field'));
		foreach (array_merge(array_column($bar['segments'], 'field'), [$bar['totalField']]) as $field) {
			self::assertContains($field, $placements['fields'], $field . ' is projected, or portaliq drops the bar');
		}
	}//end testTheStudentHoursPageOpensWithTheBar()

	/**
	 * The hours card is declared with the keys portaliq keeps, over fields the
	 * collection really projects.
	 *
	 * PORTALIQ DROPS BOTH HALVES SILENTLY. CollectionListKeys::cards() reads
	 * `valueField` and `totalField`, so a `progress` spelled any other way
	 * leaves the cards with no bar and no error; and it drops a progress whose
	 * fields the collection does not project, so the two numbers must be in
	 * `fields` as well. This test is what the first version of the block
	 * failed: it declared `value`/`total` over unprojected fields.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/internship-hours/specs/bpv/spec.md#requirement-hours-are-shown-against-the-hours-that-were-agreed
	 */
	public function testTheTrainerSeesHoursAgainstTheAgreedTotal(): void {
		$manifest = self::manifest(audience: 'praktijkopleider');
		$overview = array_column($manifest['pages'], null, 'id')['poOverview'];
		$placements = array_column($manifest['collections'], null, 'id')['poBpvPlacements'];

		$cards = $overview['blocks'][2];
		self::assertSame('poBpvPlacements', $cards['collection']);
		self::assertSame('cards', $cards['display']);
		self::assertSame(
			['valueField' => 'hoursApprovedTotal', 'totalField' => 'agreedHours', 'label' => 'Hours done'],
			$cards['progress']
		);

		// Both numbers are projected, or portaliq keeps the cards and throws
		// the progress away.
		foreach (['hoursApprovedTotal', 'agreedHours'] as $field) {
			self::assertContains($field, $placements['fields'], $field);
		}

		// AND THE CARD SAYS WHAT IT IS. Found on a live instance on 4 October
		// 2026: the row was returned and in scope, and the card showed a bar
		// and a number and nothing identifying, because portaliq's renderer
		// falls back to `name`, `title` and `givenName` and bpv-placement has
		// none of the three. Needs ConductionNL/portaliq#1178, which keeps
		// `titleFields` on a cards block; an older portaliq drops the key and
		// the card is nameless again.
		self::assertSame(['trainingCompanyName'], $cards['titleFields']);
		self::assertContains('trainingCompanyName', $placements['fields']);
	}//end testTheTrainerSeesHoursAgainstTheAgreedTotal()

	/**
	 * The pupil reads when she sent a week, and not who sent it.
	 *
	 * WHY THE TWO ARE DIFFERENT. `submittedAt` answers "have I actually handed
	 * in this week?" while it waits for her trainer, so it is projected and
	 * columned. `submittedBy` on her own page is always her own profile uuid,
	 * because the server derives it from the placement, so it is a value that
	 * never varies and tells her nothing; it stays what the school reads
	 * afterwards. pupil-flows asserts it from an admin read, where it is the
	 * evidence that HourWeekSubmissionStamp ran at all.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/internship-hours/specs/bpv/spec.md#requirement-a-week-of-bpv-hours-is-a-record-of-its-own
	 */
	public function testThePupilReadsWhenSheSentAWeekAndNotWhoSentIt(): void {
		$weeks = array_column(self::manifest(audience: 'student')['collections'], null, 'id')['studentHourWeeks'];

		self::assertContains('submittedAt', $weeks['fields']);
		self::assertNotContains('submittedBy', $weeks['fields']);
		self::assertContains('submittedAt', array_column($weeks['columns'], 'field'));

		// A column over a field the collection does not project is a column
		// that can only ever be empty.
		foreach (array_column($weeks['columns'], 'field') as $field) {
			self::assertContains($field, $weeks['fields'], $field);
		}
	}//end testThePupilReadsWhenSheSentAWeekAndNotWhoSentIt()

	/**
	 * The week the trainer approves is picked from the weeks waiting for her,
	 * not typed as a uuid, and the pupil picks her own placement the same way
	 * with a cross-reference guard behind it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/internship-hours/specs/bpv/spec.md#requirement-a-week-of-bpv-hours-is-a-record-of-its-own
	 */
	public function testTheHourFormsPickFromACollection(): void {
		$trainer = array_column(self::manifest(audience: 'praktijkopleider')['actions'], null, 'id');
		$approve = $trainer['approveHourWeek'];

		self::assertSame(
			[
				'type' => 'collection',
				'register' => 'learniq',
				'schema' => 'bpv-hour-week',
				'labelField' => 'isoWeek',
				'valueField' => 'id',
			],
			$approve['optionsProviders']['hourWeekId']
		);

		$pupil = array_column(self::manifest(audience: 'student')['actions'], null, 'id');
		$submit = $pupil['submitHourWeek'];
		self::assertSame('bpv-placement', $submit['optionsProviders']['bpvPlacementId']['schema']);
		// The placement must be the pupil's own: portaliq stamps her
		// `learnerRef` but the placement comes from the form, so without this
		// she could file hours against another student's placement.
		self::assertSame(
			[
				'register' => 'learniq',
				'schema' => 'bpv-placement',
				'scopeField' => 'learnerRef',
				'scopeClaim' => 'learnerRef',
				'required' => true,
			],
			$submit['crossRefs']['bpvPlacementId']
		);
	}//end testTheHourFormsPickFromACollection()

	/**
	 * The assessor lands on his shares, longest access first, each naming the
	 * candidate and the portfolio.
	 *
	 * @return void
	 */
	public function testTheAssessorOverviewNamesCandidates(): void {
		$manifest = self::manifest(audience: 'external-assessor');
		$pages = array_column($manifest['pages'], null, 'id');
		$overview = $pages['eaOverview'];

		self::assertTrue($overview['home']);
		self::assertSame(['collection', 'inbox'], array_column($overview['blocks'], 'type'));
		self::assertSame(['field' => 'expiresAt', 'direction' => 'desc'], $overview['blocks'][0]['sort']);
		self::assertSame(['eaOverview', 'eaSharedPortfolios'], array_keys($pages));

		$shares = $manifest['collections'][0];
		foreach (['portfolioTitle', 'learnerName', 'expiresAt'] as $field) {
			self::assertContains($field, $shares['fields'], $field);
		}

		// The sort field is projected, or portaliq drops the sort.
		self::assertSame(['Candidate', 'Portfolio', 'Access until'], array_column($shares['columns'], 'label'));
		// Only an active grant resolves, and the audience stays read-only.
		self::assertSame(['lifecycle' => 'active'], $shares['filter']);
		self::assertSame([], $manifest['actions']);
	}//end testTheAssessorOverviewNamesCandidates()

	/**
	 * A trainer collection whose schema has a create action gets that form on
	 * its page, the way portaliq builds a default page.
	 *
	 * @return void
	 */
	public function testACreateActionLandsOnItsOwnCollectionPage(): void {
		$pages = array_column(
			(new TrainerSitePages())->pages(
				collections: [['id' => 'poPokSignatures', 'schema' => 'pok-signature', 'label' => 'Signatures']],
				actions: [['id' => 'signPraktijkovereenkomst', 'type' => 'create', 'schema' => 'pok-signature']]
			),
			null,
			'id'
		);

		self::assertSame(['type' => 'action', 'action' => 'signPraktijkovereenkomst'], $pages['poPokSignatures']['blocks'][0]);
	}//end testACreateActionLandsOnItsOwnCollectionPage()
}//end class
