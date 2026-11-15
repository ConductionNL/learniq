<?php

/**
 * Learniq AssessorSitePages
 *
 * The external assessor's pages on the site, as
 * `site-external-assessor-portal-design` and the mockup `LearniqAssessor.dc.html`
 * describe them, built only from keys portaliq development keeps today: an
 * overview on `/mijn` (`home: true`), a menu group, and `limit`/`sort` on the
 * list blocks.
 *
 * The mockup writes the access window as a sentence. A text block filled from
 * a record (`template`) is not on portaliq yet, and a collection block carries
 * no heading, so the overview lists the shares with the longest access first
 * and each row names the date his access runs to.
 *
 * The exam day, the assessment form, offline tolerance, joint sign-off and the
 * exam documents are not here: no schema links an assessor to an exam. They
 * stay in the change's tasks, with `mbo-practical-exam-assessment` as the
 * proposed follow-up.
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
 * @spec openspec/changes/site-external-assessor-portal-design/specs/portal-contribution/spec.md#requirement-an-external-assessor-lands-on-what-is-shared-with-him-and-until-when
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

/**
 * Builds the assessor's overview and his menu.
 *
 * @spec openspec/changes/site-external-assessor-portal-design/specs/portal-contribution/spec.md#requirement-an-external-assessor-lands-on-what-is-shared-with-him-and-until-when
 */
class AssessorSitePages {

	/**
	 * The OpenRegister register this audience reads.
	 *
	 * @var string
	 */
	private const REGISTER = 'learniq';

	private const EA_OVERVIEW_BLOCKS = [
		// One list, longest access first. Each row names the candidate,
		// the portfolio and the date his access runs to, so the mockup's
		// sentence is read off the rows until portaliq can fill a text
		// block from a record.
		[
			'type' => 'collection',
			// "Gedeeld met u", as the board esdoornveen/Beoordelaar heads the list.
			'label' => 'Shared with you',
			'collection' => 'eaSharedPortfolios',
			'sort' => ['field' => 'expiresAt', 'direction' => 'desc'],
		],
		['type' => 'inbox', 'label' => 'Messages', 'limit' => 2],
	];

	/**
	 * How a list of shared portfolios reads, for the assessor and the trainer alike.
	 */
	public const SHARED_PORTFOLIO_LISTING = [
		'label' => 'Portfolios shared with me',
		'listable' => true,
		'minTrust' => 'low',
		// Only active grants resolve — a revoked share must return no rows.
		'filter' => ['lifecycle' => 'active'],
		'fields' => [
			'portfolioId',
			'entryIds',
			'sharedWithKind',
			'sharedBy',
			'expiresAt',
			'lifecycle',
		],
	];

	/**
	 * The live-share join behind the shared entries: his shares, only while
	 * active and not past `expiresAt` (portaliq REQ-SMO-023), and from each the
	 * entries it selects. A revoked or expired share grants nothing, and a
	 * share's own selection is the only thing it opens.
	 *
	 * A share of a whole portfolio (no `entryIds`) grants no entry here; it
	 * grants through {@see self::WHOLE_PORTFOLIO_VIA}.
	 */
	public const SHARED_ENTRIES_VIA = [
		'register' => self::REGISTER,
		'schema' => 'portfolio-share',
		'scopeField' => 'sharedWithExternalAssessorId',
		'targetField' => 'entryIds',
		'match' => 'id',
		'when' => ['field' => 'lifecycle', 'in' => ['active']],
		'validUntilField' => 'expiresAt',
	];

	/**
	 * The live-share join behind a whole-portfolio share: the same live,
	 * unexpired shares, and from each the portfolio it opens whole. The server
	 * stamps `wholePortfolioId` only on a share without a selection
	 * (ReadableCopies), so a share that selects entries contributes nothing
	 * here and its left-out entries stay closed. The entries are matched on
	 * their `portfolioId`.
	 */
	public const WHOLE_PORTFOLIO_VIA = [
		'register' => self::REGISTER,
		'schema' => 'portfolio-share',
		'scopeField' => 'sharedWithExternalAssessorId',
		'targetField' => 'wholePortfolioId',
		'match' => 'scopeField',
		'when' => ['field' => 'lifecycle', 'in' => ['active']],
		'validUntilField' => 'expiresAt',
	];

	/**
	 * The entry fields an assessor reads. The submission, assessment, training
	 * record and credential an entry may point at stay out: he has no scope on
	 * those records.
	 */
	private const ENTRY_FIELDS = ['portfolioId', 'title', 'evidenceKind', 'attachmentRef', 'reflectionText'];

	/**
	 * The access sentence on a share's own page (portaliq REQ-SMO-027), with
	 * the words for a share that has no end date.
	 */
	private const ACCESS_SENTENCE = [
		'type' => 'richText',
		'template' => 'Your access runs until {expiresAt}.',
		'whenEmpty' => ['expiresAt' => 'Your access has no end date. The school ends the access.'],
	];

	private const ASSESSOR_COLLECTIONS = [
		[
			'id' => 'eaSharedPortfolios',
			'register' => self::REGISTER,
			'schema' => 'portfolio-share',
			'scopeField' => 'sharedWithExternalAssessorId',
			'scopeClaim' => 'externalAssessorId',
			...self::SHARED_PORTFOLIO_LISTING,
		],
		[
			'id' => 'eaSharedPortfolioEntries',
			'register' => self::REGISTER,
			'schema' => 'portfolio-entry',
			'scopeField' => 'id',
			'scopeClaim' => 'externalAssessorId',
			'label' => 'Shared parts',
			'listable' => true,
			'minTrust' => 'low',
			'via' => self::SHARED_ENTRIES_VIA,
			'fields' => self::ENTRY_FIELDS,
			'columns' => [
				['field' => 'title', 'label' => 'Part'],
			],
		],
		[
			'id' => 'eaWholePortfolioEntries',
			'register' => self::REGISTER,
			'schema' => 'portfolio-entry',
			'scopeField' => 'portfolioId',
			'scopeClaim' => 'externalAssessorId',
			'label' => 'Shared parts',
			'listable' => true,
			'minTrust' => 'low',
			'via' => self::WHOLE_PORTFOLIO_VIA,
			'fields' => self::ENTRY_FIELDS,
			'columns' => [
				['field' => 'title', 'label' => 'Part'],
			],
		],
	];

	/**
	 * The shares granted to this assessor, by whose work and until when.
	 *
	 * `portfolioTitle` and `learnerName` are the readable copies the server
	 * stamps on a share (ReadableCopyStamp), so the assessor reads a candidate
	 * by name without learniq resolving a second object for him.
	 *
	 * @param array<string, mixed> $collection The shipped `eaSharedPortfolios`.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/site-external-assessor-portal-design/specs/portal-contribution/spec.md#requirement-new-a-share-names-its-candidate-and-portfolio
	 */
	public function withReadableNames(array $collection): array {
		$collection['fields'] = array_values(
			array_unique(array_merge(['portfolioTitle', 'learnerName'], $collection['fields']))
		);
		$collection['label'] = 'Shared with me';
		$collection['columns'] = [
			['field' => 'learnerName', 'label' => 'Candidate'],
			['field' => 'portfolioTitle', 'label' => 'Portfolio'],
			['field' => 'expiresAt', 'label' => 'Access until', 'render' => 'date'],
		];

		return $collection;
	}//end withReadableNames()

	/**
	 * The assessor's pages: the overview, then the shares.
	 *
	 * @param array<int, array<string, mixed>> $collections Every assessor collection.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/site-external-assessor-portal-design/specs/portal-contribution/spec.md#requirement-an-external-assessor-lands-on-what-is-shared-with-him-and-until-when
	 */
	public function pages(array $collections): array {
		$pages = [$this->overviewPage()];
		foreach ($collections as $collection) {
			$page = [
				'id' => (string)$collection['id'],
				'label' => (string)($collection['label'] ?? $collection['id']),
				'group' => ParentSitePages::GROUP,
				'blocks' => [
					['type' => 'collection', 'collection' => (string)$collection['id']],
					['type' => 'detail', 'collection' => (string)$collection['id']],
				],
			];
			// The menu is Overzicht and Gedeeld met mij; the entries are read
			// from a share's own page.
			if ($collection['id'] !== 'eaSharedPortfolios') {
				$page['menu'] = false;
			}

			$pages[] = $page;
		}

		$pages[] = $this->sharePage();

		return $pages;
	}//end pages()

	/**
	 * One share's own page: until when his access runs, in a sentence, and the
	 * parts of that portfolio the share opens to him: the selected ones, or
	 * all of them for a whole-portfolio share. Out of the menu.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/site-external-assessor-portal-design/specs/portal-contribution/spec.md#requirement-new-an-external-assessor-opens-the-shared-entries-and-only-those
	 */
	public function sharePage(): array {
		return [
			'id' => 'eaShare',
			'label' => 'Portfolio',
			'menu' => false,
			'record' => ['collection' => 'eaSharedPortfolios', 'titleFields' => ['learnerName']],
			'blocks' => [
				self::ACCESS_SENTENCE,
				[
					'type' => 'collection',
					'collection' => 'eaSharedPortfolioEntries',
					'recordField' => 'portfolioId',
					'recordKey' => 'portfolioId',
				],
				[
					'type' => 'collection',
					'collection' => 'eaWholePortfolioEntries',
					'recordField' => 'portfolioId',
					'recordKey' => 'portfolioId',
				],
			],
		];
	}//end sharePage()

	/**
	 * The overview: how long his access runs, then every candidate shared with
	 * him, then his messages.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/site-external-assessor-portal-design/specs/portal-contribution/spec.md#requirement-an-external-assessor-lands-on-what-is-shared-with-him-and-until-when
	 */
	private function overviewPage(): array {
		return [
			'id' => 'eaOverview',
			'label' => 'Overview',
			'icon' => 'ViewDashboard',
			'group' => ParentSitePages::GROUP,
			'home' => true,
			'blocks' => self::EA_OVERVIEW_BLOCKS,
		];
	}//end overviewPage()
	/**
	 * Manifest for the `external-assessor` audience (a non-BPV external assessor granted
	 * read-only portfolio access, no Nextcloud account — `ExternalAssessor` schema).
	 *
	 * The fourth audience, added following the exact mechanism `bpv-praktijkovereenkomst`
	 * used to add `praktijkopleider` as the third: one more `getAudiences()` value, one
	 * more `getContribution()` branch, and this method. `subject.subjectRef` is the
	 * assessor's own `ExternalAssessor` object UUID — a DIRECT scope key on
	 * `PortfolioShare.sharedWithExternalAssessorId`, the same direct-match shape
	 * `praktijkopleiderContribution()`'s new `poSharedPortfolios` collection uses (see that
	 * method's docblock for why this stays a direct `portfolio-share` read rather than a
	 * `via`-joined `portfolio` one). Zero create-actions — external-assessor access is
	 * read-only per the brief.
	 *
	 * @return array<string, mixed> The external-assessor manifest.
	 *
	 * @spec openspec/specs/eportfolio/spec.md#requirement-bpv-praktijkopleider-and-external-assessor-sharing-reuse-the-adr-046-portal-audience-mechanism
	 */
	public function contribution(): array {
		$contribution = [
			'label' => 'Learniq',
			'collections' => self::ASSESSOR_COLLECTIONS,
			'actions' => [],
			'notifications' => [],
		];
		// The share names its candidate and portfolio, and the assessor gets an
		// overview (site-external-assessor-portal-design).
		$contribution['collections'][0] = $this->withReadableNames(collection: $contribution['collections'][0]);
		$contribution['pages'] = $this->pages(collections: $contribution['collections']);

		return $contribution;

	}//end contribution()
}//end class
