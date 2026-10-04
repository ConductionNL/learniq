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
		self::assertSame(['collection' => 'parentChildren', 'titleFields' => ['givenName']], $overview['records']);
		self::assertSame('parentOverview', $manifest['pages'][0]['id']);
		self::assertSame(['tasks', 'cta', 'cta', 'calendar', 'kpi', 'collection', 'collection', 'inbox'], array_column($overview['blocks'], 'type'));
		self::assertSame(['type' => 'tasks', 'label' => 'Still to do', 'collection' => 'parentConferenceRounds', 'dueField' => 'bookingClosesAt', 'titleFields' => ['name']], $overview['blocks'][0]);

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
	 * The pupil lands on an overview with the work to hand in first, and the
	 * menu holds only Inleveren, Cijfers, Toetsen and Afwezig melden.
	 *
	 * @return void
	 */
	public function testThePupilOverviewAndShortMenu(): void {
		$pages = self::pages(audience: 'student');
		$overview = $pages['studentOverview'];

		self::assertTrue($overview['home']);
		self::assertSame(['tasks', 'cta', 'cta', 'collection', 'inbox'], array_column($overview['blocks'], 'type'));
		self::assertSame('studentHomework', $overview['blocks'][0]['collection']);
		self::assertSame('dueAt', $overview['blocks'][0]['dueField']);

		$inMenu = [];
		foreach ($pages as $id => $page) {
			if (($page['menu'] ?? true) === true && $id !== 'studentOverview') {
				$inMenu[$id] = $page['label'];
			}
		}

		self::assertSame(
			['studentGrades' => 'Grades', 'studentExcuseRequests' => 'Report an absence', 'studentTests' => 'Tests', 'studentHomework' => 'Hand in'],
			$inMenu
		);

		// The hand-in form sits on the homework page; the absence form on its own page.
		self::assertSame(['type' => 'action', 'action' => 'createSubmission'], $pages['studentHomework']['blocks'][0]);
		self::assertSame(['type' => 'action', 'action' => 'createExcuseRequest'], $pages['studentExcuseRequests']['blocks'][0]);
		self::assertFalse($pages['studentEnrolments']['menu']);
	}//end testThePupilOverviewAndShortMenu()

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
	 * The trainer lands on an overview with her placements, her last
	 * assessments and the two things she may do; every section keeps a page.
	 *
	 * @return void
	 */
	public function testTheTrainerOverviewAndMenu(): void {
		$manifest = self::manifest(audience: 'praktijkopleider');
		$pages = array_column($manifest['pages'], null, 'id');
		$overview = $pages['poOverview'];

		self::assertTrue($overview['home']);
		self::assertSame('My space', $overview['group']);
		self::assertSame(['collection', 'collection', 'cta', 'cta', 'inbox'], array_column($overview['blocks'], 'type'));
		self::assertSame(['field' => 'assessedAt', 'direction' => 'desc'], $overview['blocks'][1]['sort']);
		self::assertSame(3, $overview['blocks'][1]['limit']);

		// A collection block carries no heading on portaliq, so none is declared.
		foreach ($overview['blocks'] as $block) {
			if ($block['type'] === 'collection') {
				self::assertArrayNotHasKey('label', $block);
			}
		}

		self::assertSame(
			['poOverview', 'poBpvPlacements', 'poSharedPortfolios', 'poWerkprocesAssessments'],
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
}//end class
