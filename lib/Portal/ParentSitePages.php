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
	 * The overview: open tasks, quick actions, the coming weeks, the figures,
	 * the latest absence reports, the grades and the messages of the chosen
	 * child. `tasks` is not narrowed to the child: a round names its invited
	 * pupils in a list that is never projected.
	 *
	 * @param array<int, array<string, mixed>> $sources The child's calendar sources.
	 * @param array<string, mixed>             $figures The attendance figure block.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/site-guardian-portal-design/specs/portal-contribution/spec.md#requirement-a-guardian-lands-on-an-overview-of-one-child-at-a-time
	 * @spec openspec/changes/site-guardian-portal-design/specs/portal-contribution/spec.md#requirement-the-overview-puts-open-tasks-first
	 */
	public function overviewPage(array $sources, array $figures): array {
		return [
			'id' => 'parentOverview',
			'label' => 'Overview',
			'icon' => 'ViewDashboard',
			'group' => self::GROUP,
			'home' => true,
			'records' => ['collection' => self::CHILDREN, 'titleFields' => ['givenName']],
			'blocks' => [
				[
					'type' => 'tasks',
					'label' => 'Still to do',
					'collection' => 'parentConferenceRounds',
					'dueField' => 'bookingClosesAt',
					'titleFields' => ['name'],
				],
				['type' => 'cta', 'action' => 'createExcuseRequest', 'label' => 'Report sick or absent'],
				['type' => 'cta', 'action' => 'bookConferenceSlot', 'label' => 'Book a parent-teacher conversation'],
				['type' => 'calendar', 'label' => 'Coming up', 'sources' => $sources],
				$figures,
				['type' => 'collection', 'collection' => 'parentExcuseRequests', 'recordField' => 'learnerRef'],
				['type' => 'collection', 'collection' => 'parentGrades', 'recordField' => 'learnerRef'],
				['type' => 'inbox', 'label' => 'Messages from school', 'limit' => 2],
			],
		];
	}//end overviewPage()

	/**
	 * The absence page of one child: the form, that child's reports and figures.
	 *
	 * @param array<string, mixed> $figures The attendance figure block.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/site-guardian-portal-design/specs/portal-contribution/spec.md#requirement-the-absence-page-shows-the-form-and-only-the-latest-reports
	 */
	public function absencePage(array $figures): array {
		return [
			'id' => 'parentAbsence',
			'label' => 'Absence',
			'icon' => 'CalendarRemove',
			'record' => ['collection' => self::CHILDREN, 'titleFields' => ['givenName', 'familyName']],
			'perRecord' => self::CHILDREN,
			'blocks' => [
				['type' => 'action', 'action' => 'createExcuseRequest'],
				['type' => 'collection', 'collection' => 'parentExcuseRequests', 'recordField' => 'learnerRef'],
				$figures,
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
