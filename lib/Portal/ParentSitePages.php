<?php

/**
 * Learniq ParentSitePages
 *
 * The guardian's pages on the site, as `site-guardian-portal-design` and the
 * approved mockup `Main.dc.html` describe them, built only from keys portaliq
 * development keeps today: an overview on `/mijn` (`home: true`) that switches
 * between her children (`records`), a menu group for it, and per child the
 * absence page, the conversations page and the record page (`perRecord`).
 * The collection pages portaliq builds by default keep their routes and leave
 * the menu (`menu: false`).
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
 * @spec openspec/changes/site-guardian-portal-design/specs/portal-contribution/spec.md#requirement-a-guardian-lands-on-an-overview-of-one-child-at-a-time
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

/**
 * Builds the guardian's overview, the per-child pages and the menu marks.
 *
 * @spec openspec/changes/site-guardian-portal-design/specs/portal-contribution/spec.md#requirement-a-guardian-lands-on-an-overview-of-one-child-at-a-time
 */
class ParentSitePages {

	/**
	 * The menu group of the guardian's own pages; Dutch "Mijn omgeving".
	 */
	public const GROUP = 'My space';

	/**
	 * The collection the pages switch between: the guardian's children.
	 */
	private const CHILDREN = 'parentChildren';

	/**
	 * The collection of the guardian's tasks: an open round per child.
	 */
	private const INVITATIONS = 'parentConferenceInvitations';

	/**
	 * The overview, in the order of the board (school-design wilgenboom,
	 * MijnOverzicht): the greeting with today's date and the absence action,
	 * what the guardian still has to do, the children as cards, the newest
	 * school news and this month's calendar. Nothing else: the board has no
	 * figures, absence reports, grades or messages here; those live on each
	 * child's own pages (portal proof run 1, defect 11).
	 *
	 * The greeting, the highlight display, the cards keys and the calendar
	 * tiles are the block contract of lane L2 (portaliq `site-school-blocks`);
	 * portaliq drops a key it does not know yet, so the page still renders.
	 * `tasks` lists one row per child per open round, named by the child.
	 *
	 * @param array<int, array<string, mixed>> $sources The child's calendar sources.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/site-guardian-portal-design/specs/portal-contribution/spec.md#requirement-a-guardian-lands-on-an-overview-of-one-child-at-a-time
	 * @spec openspec/changes/site-guardian-portal-design/specs/portal-contribution/spec.md#requirement-the-overview-puts-open-tasks-first
	 * @spec openspec/changes/school-portals-match-their-boards/specs/portal-contribution/spec.md#requirement-the-guardian-overview-holds-only-what-the-board-shows
	 * @spec openspec/changes/guardian-and-participant-pages-follow-the-boards/specs/portal-contribution/spec.md#requirement-the-guardian-overview-and-absence-page-are-about-both-children
	 * @spec openspec/changes/guardian-tasks-per-child-and-self-assessment/specs/portal-contribution/spec.md#requirement-the-guardian-reads-one-task-per-child-with-the-childs-name
	 */
	public function overviewPage(array $sources): array {
		return [
			'id' => 'parentOverview',
			'label' => 'Overview',
			'icon' => 'ViewDashboard',
			'group' => self::GROUP,
			'home' => true,
			// No child switcher: the board's overview is about both children at
			// once, the cards and the calendar included (REPORT-2, item 4).
			'blocks' => [
				// The greeting's one button opens the absence form (lane L2: `label` plus one target).
				['type' => 'greeting', 'label' => 'Report absent', 'action' => 'createExcuseRequest'],
				[
					'type' => 'tasks',
					'label' => 'Still to do',
					'display' => 'highlight',
					// One row per child per open round (ConferenceInvitations): a
					// child who already has a time has no open row, so Vera's
					// booked round asks nothing and Sami's asks for a time.
					'collection' => self::INVITATIONS,
					'dueField' => 'bookingClosesAt',
					'titleFields' => ['roundName'],
					// "Kies een tijd voor het oudergesprek van Sami": the child's
					// first name through a lookup on the row's own learnerRef
					// (portaliq lookup-by-row-field). Without a name the title
					// falls back to the round's name.
					'titleTemplate' => 'Pick a time for the parent-teacher conversation of {childName}',
					'lookups' => [self::childNameLookup()],
					'buttonLabel' => 'Pick a time',
					// "Kiezen kan tot en met vrijdag 16 oktober" in the card's line.
					'dueInLine' => true,
				],
				[
					'type' => 'collection',
					'label' => 'My children',
					'collection' => self::CHILDREN,
					'display' => 'cards',
					'titleFields' => ['givenName'],
					'subtitleFields' => ['groupLabel'],
					'avatar' => true,
					// The chip on each card (board: "Op school", "Ziek gemeld"),
					// derived, never stored: a report of this child that covers
					// today reads "Reported sick", a school day without one
					// "At school", a weekend or holiday neither.
					'status' => self::childStatus(),
				],
				['type' => 'news', 'label' => 'New from school', 'limit' => 3],
				// "Deze maand": this month only (portaliq calendar `range`).
				['type' => 'calendar', 'label' => 'This month', 'display' => 'tiles', 'range' => 'month', 'sources' => $sources],
			],
		];
	}//end overviewPage()

	/**
	 * The child's first name on a row that names the child in `learnerRef`
	 * (portaliq lookup-by-row-field): the guardian's own children only.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/changes/guardian-tasks-per-child-and-self-assessment/specs/portal-contribution/spec.md#requirement-the-guardian-reads-one-task-per-child-with-the-childs-name
	 */
	public static function childNameLookup(): array {
		return [
			'as' => 'childName',
			'collection' => self::CHILDREN,
			'rowField' => 'learnerRef',
			'matchField' => 'id',
			'valueField' => 'givenName',
		];
	}//end childNameLookup()

	/**
	 * The page the overview's task opens: the invitation it was about, then
	 * the two ways to book, as on the conversations page. Out of the menu.
	 * Portaliq opens a task on the page that shows its collection, with the
	 * row selected.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/guardian-tasks-per-child-and-self-assessment/specs/portal-contribution/spec.md#requirement-the-guardian-reads-one-task-per-child-with-the-childs-name
	 */
	public function invitationPage(): array {
		return [
			'id' => 'parentPickATime',
			'label' => 'Pick a time',
			'menu' => false,
			'blocks' => [
				['type' => 'detail', 'collection' => self::INVITATIONS],
				['type' => 'action', 'action' => 'bookConferenceSlot'],
				['type' => 'action', 'action' => 'createConferenceSignup'],
			],
		];
	}//end invitationPage()

	/**
	 * How a child's card says where the child is today, from the guardian's
	 * own absence reports: a report in `submitted` or `approved` whose days
	 * cover today makes "Reported sick"; any other school day "At school".
	 * Portaliq reads it as a lookup per card and keeps a key it does not
	 * render yet out of the page (requested from lane FIX-P, 08 Oct).
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/school-portals-match-their-boards/specs/portal-contribution/spec.md#requirement-a-childs-card-says-where-the-child-is-today
	 */
	public static function childStatus(): array {
		return [
			'collection' => 'parentExcuseRequests',
			'matchField' => 'learnerRef',
			'fromField' => 'dateFrom',
			'toField' => 'dateTo',
			'only' => ['field' => 'lifecycle', 'in' => ['submitted', 'approved']],
			'label' => 'Reported sick',
			'tone' => 'warning',
			'otherLabel' => 'At school',
			'otherTone' => 'positive',
			'schoolDaysOnly' => true,
		];
	}//end childStatus()

	/**
	 * The absence page of one child: the form, that child's figures, then the
	 * reports as rows with a date tile, the reason, the status and who decided
	 * (board MijnLijst; the `rows` display is lane L2's contract).
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/site-guardian-portal-design/specs/portal-contribution/spec.md#requirement-the-absence-page-shows-the-form-and-only-the-latest-reports
	 * @spec openspec/changes/guardian-and-participant-pages-follow-the-boards/specs/portal-contribution/spec.md#requirement-the-guardian-overview-and-absence-page-are-about-both-children
	 */
	public function absencePage(): array {
		return [
			'id' => 'parentAbsence',
			'label' => 'Absence',
			'icon' => 'CalendarRemove',
			// One page for both children, as the board (MijnLijst): the form
			// and every report, newest first (REPORT-2, item 4).
			'blocks' => [
				['type' => 'action', 'action' => 'createExcuseRequest'],
				[
					'type' => 'collection',
					'label' => 'Your reports',
					'collection' => 'parentExcuseRequests',
					'recordField' => 'learnerRef',
					'display' => 'rows',
					'dateField' => 'dateFrom',
					// "Sami · Ziek": the child's first name through a lookup on the
					// report's own learnerRef (portaliq #1408), then the kind.
					'lookups' => [self::childNameLookup()],
					'titleFields' => ['childName', 'reasonKind'],
					'quoteField' => 'reason',
					'statusField' => 'lifecycle',
					'statusTones' => ['submitted' => 'neutral', 'approved' => 'success', 'rejected' => 'error'],
					'statusNoteField' => 'decidedBy',
					'sort' => ['field' => 'dateFrom', 'direction' => 'desc'],
				],
			],
		];
	}//end absencePage()

	/**
	 * The conversations page of one child: two titled forms, "Kies een tijd"
	 * (a free time, in a round with direct booking) and "Stuur uw voorkeur"
	 * (the school plans the time), then that child's times and requests.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/booking-a-time-and-sending-a-preference/specs/parent-conferences/spec.md#requirement-a-guardian-books-a-free-time-or-sends-a-preference-in-two-forms
	 */
	public function conferencesPage(): array {
		return [
			'id' => 'parentConferences',
			'label' => 'Parent-teacher conversations',
			'icon' => 'AccountVoice',
			// The menu's count of rounds still open for booking (board MijnMenu: "Oudergesprekken 1").
			'badge' => ['collection' => 'parentConferenceRounds', 'label' => '{count} to choose'],
			'record' => ['collection' => self::CHILDREN, 'titleFields' => ['givenName', 'familyName']],
			'perRecord' => self::CHILDREN,
			'blocks' => [
				['type' => 'action', 'action' => 'bookConferenceSlot'],
				['type' => 'action', 'action' => 'createConferenceSignup'],
				['type' => 'collection', 'collection' => 'parentConferenceSlots', 'recordField' => 'learnerRef'],
				['type' => 'collection', 'collection' => 'parentConferenceSignups', 'recordField' => 'learnerRef'],
			],
		];
	}//end conferencesPage()

	/**
	 * The record page of one child, listed once per child in the menu.
	 *
	 * @param array<string, mixed> $page The record page.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/site-guardian-portal-design/specs/portal-contribution/spec.md#requirement-the-guardian-menu-is-grouped-per-child
	 */
	public function perChild(array $page): array {
		return array_merge($page, ['perRecord' => self::CHILDREN]);
	}//end perChild()

	/**
	 * A page in the guardian's own menu group.
	 *
	 * @param array<string, mixed> $page The page.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/site-guardian-portal-design/specs/portal-contribution/spec.md#requirement-the-guardian-menu-is-grouped-per-child
	 */
	public function inGroup(array $page): array {
		return array_merge($page, ['group' => self::GROUP]);
	}//end inGroup()

	/**
	 * A page that keeps its route and leaves the menu.
	 *
	 * @param array<string, mixed> $page The page.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/site-guardian-portal-design/specs/portal-contribution/spec.md#requirement-the-guardian-menu-is-grouped-per-child
	 */
	public function offMenu(array $page): array {
		return array_merge($page, ['menu' => false]);
	}//end offMenu()
}//end class
