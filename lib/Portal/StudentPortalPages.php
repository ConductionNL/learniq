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
 * Builds the pupil's homework collection, overview and menu.
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
				['field' => 'title', 'label' => 'Assignment'],
				['field' => 'dueAt', 'label' => 'Hand in by', 'render' => 'date'],
			],
		];
	}//end homeworkCollection()

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
	 * The overview: what to hand in, two quick actions, the newest grades and
	 * the newest messages.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/site-pupil-portal-design/specs/portal-contribution/spec.md#requirement-a-pupil-lands-on-an-overview-of-today
	 */
	private function overviewPage(): array {
		return [
			'id' => 'studentOverview',
			'label' => 'Overview',
			'icon' => 'ViewDashboard',
			'group' => ParentSitePages::GROUP,
			'home' => true,
			'blocks' => [
				['type' => 'tasks', 'label' => 'To hand in', 'collection' => 'studentHomework', 'dueField' => 'dueAt', 'titleFields' => ['title']],
				['type' => 'cta', 'action' => 'createSubmission', 'label' => 'Hand in work'],
				['type' => 'cta', 'action' => 'createExcuseRequest', 'label' => 'Report an absence'],
				['type' => 'collection', 'collection' => 'studentGrades'],
				['type' => 'inbox', 'label' => 'Messages', 'collection' => 'studentInbox', 'limit' => 2],
			],
		];
	}//end overviewPage()

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
