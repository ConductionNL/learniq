<?php

/**
 * Learniq ParentDocumentsPage
 *
 * The guardian's "Rapporten en documenten" (wilgenboom/Documenten, MijnMenu
 * "Van school"): a group per child with that child's published reports, then
 * a group per school of her children with the school's papers for parents.
 * Both are portaliq documents blocks for each record: the site lists every
 * group through the per-row route, which proves the child or the school is
 * the guardian's before GuardianDocuments is asked.
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
 * @spec openspec/changes/guardian-reports-and-documents/specs/portal-contribution/spec.md#requirement-a-guardian-reads-her-childrens-published-reports-and-the-schools-papers-on-one-page
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

/**
 * Declares the guardian's documents page and the schools it reads.
 *
 * @spec openspec/changes/guardian-reports-and-documents/specs/portal-contribution/spec.md#requirement-a-guardian-reads-her-childrens-published-reports-and-the-schools-papers-on-one-page
 */
class ParentDocumentsPage {

	/**
	 * The page id; portals place it as `learniq:parentDocuments`.
	 */
	public const PAGE = 'parentDocuments';

	/**
	 * The documents of the guardian's children, per child.
	 */
	public const CHILD_DOCUMENTS = ['label' => 'Reports', 'provider' => 'childDocuments'];

	/**
	 * A group per child: "Vera" with "Groep 7" beside it, then her reports.
	 */
	private const CHILDREN_BLOCK = [
		'type' => 'documents',
		'collection' => 'parentChildren',
		'eachRecord' => true,
		'titleFields' => ['givenName'],
		'subtitleFields' => ['groupLabel'],
		'emptyLabel' => 'There is no report yet. You get a message when one is ready.',
	];

	/**
	 * A group per school: "Van school", "Voor alle ouders", then its papers.
	 */
	private const SCHOOLS_BLOCK = [
		'type' => 'documents',
		'collection' => 'parentSchools',
		'eachRecord' => true,
		'label' => 'From school',
		'subtitle' => 'For all parents',
		'emptyLabel' => 'The school has not shared any papers yet.',
	];

	/**
	 * The schools of the guardian's children, each with its papers for parents.
	 *
	 * @param array<string, mixed> $childJoin The parent audience's reverse join on learner-profile.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/guardian-reports-and-documents/specs/portal-contribution/spec.md#requirement-a-guardian-reads-her-childrens-published-reports-and-the-schools-papers-on-one-page
	 */
	public function schoolsCollection(array $childJoin): array {
		return [
			'id' => 'parentSchools',
			'register' => 'learniq',
			'schema' => 'school',
			'scopeField' => 'id',
			'scopeClaim' => 'guardianRef',
			// The schools whose own id one of her children names in schoolId.
			'via' => array_merge($childJoin, ['targetField' => 'schoolId', 'match' => 'id']),
			'label' => 'School',
			'listable' => false,
			'minTrust' => 'substantial',
			'fields' => ['name'],
			'documents' => ['label' => 'From school', 'provider' => 'schoolDocuments'],
		];
	}//end schoolsCollection()

	/**
	 * The page: an opening line, the children's reports, the school's papers.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/guardian-reports-and-documents/specs/portal-contribution/spec.md#requirement-a-guardian-reads-her-childrens-published-reports-and-the-schools-papers-on-one-page
	 */
	public function page(): array {
		return [
			'id' => self::PAGE,
			'label' => 'Reports and documents',
			'icon' => 'FileDocumentMultipleOutline',
			'group' => ParentSitePages::GROUP,
			'blocks' => [
				['type' => 'richText', 'markdown' => "Your children's reports and the school's papers. You can download and keep them all."],
				self::CHILDREN_BLOCK,
				self::SCHOOLS_BLOCK,
			],
		];
	}//end page()
}//end class
