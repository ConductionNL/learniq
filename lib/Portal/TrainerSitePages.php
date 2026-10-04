<?php

/**
 * Learniq TrainerSitePages
 *
 * The workplace trainer's pages on the site, as
 * `site-workplace-trainer-portal-design` and the mockup `LearniqTrainer.dc.html`
 * describe them, built only from keys portaliq development keeps today: an
 * overview on `/mijn` (`home: true`), a menu group, a page per section, and
 * `limit`/`sort` on the list blocks.
 *
 * Also declares `poWerkprocesAssessments`: the assessments this trainer wrote,
 * matched directly on `assessorId`, so she reads back what she submitted.
 *
 * The mockup's hours approval, the derived "volgende stap" and the school
 * contact are not here: learniq holds no BPV hours record, a card cannot carry
 * a derived value, and the school coach is kept out of the trainer's
 * projection on purpose. They stay in the change's tasks.
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
 * @spec openspec/changes/site-workplace-trainer-portal-design/specs/portal-contribution/spec.md#requirement-a-workplace-trainer-lands-on-what-is-waiting-for-her
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

/**
 * Builds the trainer's own-assessment collection, her overview and her menu.
 *
 * @spec openspec/changes/site-workplace-trainer-portal-design/specs/portal-contribution/spec.md#requirement-a-workplace-trainer-lands-on-what-is-waiting-for-her
 */
class TrainerSitePages {

	private const REGISTER = 'learniq';

	/**
	 * The assessments this trainer wrote.
	 *
	 * Matched directly on `assessorId`, the same claim the create action
	 * stamps, so she never reads another assessor's judgement. `minTrust` is
	 * `low`, like her other reads: a trainer signs in from her company, and
	 * the rows are already narrowed to her own work.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/site-workplace-trainer-portal-design/specs/portal-contribution/spec.md#requirement-new-a-trainer-reads-the-assessments-she-wrote
	 */
	public function assessmentsCollection(): array {
		return [
			'id' => 'poWerkprocesAssessments',
			'register' => self::REGISTER,
			'schema' => 'werkproces-assessment',
			'scopeField' => 'assessorId',
			'scopeClaim' => 'practicalTrainerId',
			'label' => 'Assessments I wrote',
			'listable' => true,
			'minTrust' => 'low',
			'fields' => [
				'bpvPlacementId',
				'werkprocesCode',
				'werkprocesLabel',
				'assessment',
				'notes',
				'assessedAt',
				'lifecycle',
			],
			'columns' => [
				['field' => 'werkprocesLabel', 'label' => 'Work process'],
				['field' => 'assessment', 'label' => 'Judgement', 'valueLabels' => PortalValueLabels::WERKPROCES_ASSESSMENT],
				['field' => 'assessedAt', 'label' => 'Assessed on', 'render' => 'date'],
			],
		];
	}//end assessmentsCollection()

	/**
	 * The weeks of hours waiting for her.
	 *
	 * Matched through the placement she supervises, so she reads the weeks of
	 * her own students and nobody else's. Only weeks that are still
	 * `submitted` are offered: a week she has decided is history, and lives on
	 * the student's page and the school's.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/internship-hours/specs/bpv/spec.md#requirement-a-week-of-bpv-hours-is-a-record-of-its-own
	 */
	public function hourWeeksCollection(): array {
		return [
			'id' => 'poHourWeeks',
			'register' => self::REGISTER,
			'schema' => 'bpv-hour-week',
			'scopeField' => 'bpvPlacementId',
			'scopeClaim' => 'practicalTrainerId',
			// The reverse one-hop join the guardian's children already use, read
			// the other way round: portaliq reads the placements whose
			// `practicalTrainerId` is her claim, takes each row's own `id` as a
			// target, and keeps only the weeks whose `bpvPlacementId` is in that
			// set (`match: scopeField`). `targetField` names a property of the
			// JOIN row, so it is the placement's identity token and NOT
			// `bpvPlacementId`, which bpv-placement does not have.
			'via' => [
				'register' => self::REGISTER,
				'schema' => 'bpv-placement',
				'scopeField' => 'practicalTrainerId',
				'targetField' => 'id',
				'match' => 'scopeField',
			],
			'label' => 'Hours to approve',
			'listable' => true,
			'minTrust' => 'low',
			'filter' => ['lifecycle' => 'submitted'],
			'fields' => [
				'bpvPlacementId',
				'learnerRef',
				'isoWeek',
				'hoursSubmitted',
				'submittedAt',
				'lifecycle',
			],
			'columns' => [
				['field' => 'isoWeek', 'label' => 'Week'],
				['field' => 'hoursSubmitted', 'label' => 'Hours'],
			],
		];
	}//end hourWeeksCollection()

	/**
	 * The trainer's pages: the overview, then one page per section.
	 *
	 * @param array<int, array<string, mixed>> $collections Every trainer collection.
	 * @param array<int, array<string, mixed>> $actions     Every trainer action.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/site-workplace-trainer-portal-design/specs/portal-contribution/spec.md#requirement-a-workplace-trainer-lands-on-what-is-waiting-for-her
	 */
	public function pages(array $collections, array $actions): array {
		$pages = [$this->overviewPage()];
		foreach ($collections as $collection) {
			$pages[] = $this->collectionPage(collection: $collection, actions: $actions);
		}

		return $pages;
	}//end pages()

	/**
	 * The overview: the weeks of hours waiting for her, her students'
	 * placements with their hours, the assessments she wrote last, the three
	 * things she can do, and her messages.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/site-workplace-trainer-portal-design/specs/portal-contribution/spec.md#requirement-a-workplace-trainer-lands-on-what-is-waiting-for-her
	 */
	private function overviewPage(): array {
		return [
			'id' => 'poOverview',
			'label' => 'Overview',
			'icon' => 'ViewDashboard',
			'group' => ParentSitePages::GROUP,
			'home' => true,
			'blocks' => [
				// What is waiting for her comes first: the weeks of hours her
				// students entered and nobody has decided yet.
				['type' => 'tasks', 'label' => 'Hours to approve', 'collection' => 'poHourWeeks', 'dueField' => 'submittedAt', 'titleFields' => ['isoWeek']],
				// A collection block carries no heading of its own on portaliq
				// today, so the two lists stand on their columns: the placements
				// first, then the three assessments she wrote last.
				[
					'type' => 'collection',
					'collection' => 'poBpvPlacements',
					// The progress card portaliq ships, but only where the
					// placement really agreed a total: a placement without
					// `agreedHours` shows its hours and no bar
					// (internship-hours).
					'display' => 'cards',
					'progress' => [
						'valueField' => 'hoursApprovedTotal',
						'totalField' => 'agreedHours',
						'label' => 'Hours done',
					],
				],
				[
					'type' => 'collection',
					'collection' => 'poWerkprocesAssessments',
					'limit' => 3,
					'sort' => ['field' => 'assessedAt', 'direction' => 'desc'],
				],
				['type' => 'cta', 'action' => 'approveHourWeek', 'label' => 'Approve hours'],
				['type' => 'cta', 'action' => 'createWerkprocesAssessment', 'label' => 'Fill in an assessment'],
				['type' => 'cta', 'action' => 'signPraktijkovereenkomst', 'label' => 'Sign the placement agreement'],
				['type' => 'inbox', 'label' => 'Messages', 'limit' => 2],
			],
		];
	}//end overviewPage()

	/**
	 * One collection's page, built the way portaliq builds a default page.
	 *
	 * @param array<string, mixed>             $collection The collection.
	 * @param array<int, array<string, mixed>> $actions    Every trainer action.
	 *
	 * @return array<string, mixed>
	 */
	private function collectionPage(array $collection, array $actions): array {
		$id = (string)$collection['id'];
		$blocks = [];
		// The assessment form posts to learniq's endpoint, so it carries no
		// schema to match a page on; it belongs to the assessments page.
		$forms = ['poWerkprocesAssessments' => 'createWerkprocesAssessment'];
		$form = ($forms[$id] ?? null);
		foreach ($actions as $action) {
			if ($form === null && ($action['type'] ?? '') === 'create' && ($action['schema'] ?? '') === ($collection['schema'] ?? '')) {
				$form = (string)$action['id'];
				break;
			}
		}

		if ($form !== null) {
			$blocks[] = ['type' => 'action', 'action' => $form];
		}

		$blocks[] = ['type' => 'collection', 'collection' => $id];
		$blocks[] = ['type' => 'detail', 'collection' => $id];

		return [
			'id' => $id,
			'label' => (string)($collection['label'] ?? $id),
			'group' => ParentSitePages::GROUP,
			'blocks' => $blocks,
		];
	}//end collectionPage()
	/**
	 * Manifest for the `praktijkopleider` audience (the workplace supervisor conducting BPV).
	 *
	 * `subject.subjectRef` is the praktijkopleider's own `Praktijkopleider` object UUID — a
	 * DIRECT scope key on `BpvPlacement` (`praktijkopleiderId == subject.subjectRef`), unlike
	 * `parent`'s reverse one-hop join, because the placement literally belongs to that
	 * praktijkopleider (no join required). This follows the `student` shape (direct match,
	 * safe to ship create-actions), not the `parent` shape (no create yet, pending a
	 * cross-ref-validating writer). Both create-actions are `minTrust: substantial` — an
	 * official werkproces assessment and a POK signature both feed diploma-track evidence,
	 * the same trust floor `portal-parent` set for guardian actions over minor data.
	 *
	 * Field projection: the read collection excludes `schoolCoachId` (internal staff
	 * identity) and `leerbedrijfVerification.raw` (the SBB provider's raw payload may carry
	 * more than the erkenning status) — mirrors the staff-only-column drop table in
	 * portal-contribution/design.md.
	 *
	 * eportfolio: gains one new direct-matched collection, `poSharedPortfolios`, over
	 * `portfolio-share` (NOT a `via` join — `PortfolioShare` itself carries
	 * `sharedWithPraktijkopleiderId`, so no cross-object resolution is needed, exactly the
	 * same direct-scope shape `poBpvPlacements` above already uses). `filter: {lifecycle:
	 * active}` is applied BEFORE the scope filter (mirrors `parentReportCards`'s own
	 * `filter` usage) so a `revoked` share resolves no rows. The collection exposes the
	 * grant's own `portfolioId`/`entryIds` pointer fields — resolving those into the
	 * referenced `Portfolio`/`PortfolioEntry` content is downstream of this manifest (this
	 * class stays a pure, I/O-free declaration per its own class docblock); it does not
	 * declare a second `via`-joined collection here because the documented `via` contract
	 * (`openspec/changes/archive/2026-09-28-portal-parent/design.md`'s `isValidVia()` key set — exactly
	 * `{register, schema, scopeField, targetField, match}`) has no hook to filter the
	 * *joined* schema by its own lifecycle, so a `via`-based `portfolio`/`portfolio-entry`
	 * collection could not honour "a revoked share resolves no rows". Resolving
	 * `portfolioId`/`entryIds` into the referenced `Portfolio`/`PortfolioEntry` content is
	 * therefore left to the portal client reading those objects directly, out of this
	 * manifest's declarative scope — flagged as a follow-up once portaliq's `via` contract
	 * grows a joined-schema filter hook.
	 *
	 * @return array<string, mixed> The praktijkopleider manifest.
	 *
	 * @spec openspec/specs/bpv/spec.md#requirement-praktijkopleider-portal-access-is-a-direct-scope-portalcontributionprovider-audience
	 * @spec openspec/specs/bpv/spec.md#requirement-praktijkopleider-portal-actions-never-trust-client-supplied-identity
	 * @spec openspec/specs/eportfolio/spec.md#requirement-bpv-praktijkopleider-and-external-assessor-sharing-reuse-the-adr-046-portal-audience-mechanism
	 */
	public function contribution(): array {
		$contribution = [
			'label' => 'Learniq',
			'collections' => [
				[
					'id' => 'poBpvPlacements',
					'register' => self::REGISTER,
					'schema' => 'bpv-placement',
					'scopeField' => 'practicalTrainerId',
					'scopeClaim' => 'practicalTrainerId',
					'label' => 'My BPV placements',
					'listable' => true,
					'minTrust' => 'low',
					'fields' => [
						'practicalTrainerId',
						'learnerRef',
						'curriculumPlanId',
						'trainingCompanyName',
						'periodFrom',
						'periodTo',
						// The two numbers the progress card reads. Portaliq drops
						// a `progress` whose fields the collection does not
						// project, so leaving these out would have drawn cards
						// with no bar (internship-hours).
						'agreedHours',
						'hoursApprovedTotal',
						'lifecycle',
					],
				],
				[
					'id' => 'poSharedPortfolios',
					'register' => self::REGISTER,
					'schema' => 'portfolio-share',
					'scopeField' => 'sharedWithPracticalTrainerId',
					'scopeClaim' => 'practicalTrainerId',
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
				],
			],
			'actions' => $this->actions(),
			'notifications' => [],
		];
		$contribution['collections'][] = $this->assessmentsCollection();
		$contribution['collections'][] = $this->hourWeeksCollection();
		// The overview and a page per section (site-workplace-trainer-portal-design).
		$contribution['pages'] = $this->pages(collections: $contribution['collections'], actions: $contribution['actions']);

		return $contribution;

	}//end contribution()

	/**
	 * What the trainer may do: submit a werkproces assessment through learniq's
	 * own endpoint, approve or correct a week of hours through the same kind of
	 * endpoint, and sign a praktijkovereenkomst.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/an-invited-trainer-may-assess/specs/bpv/spec.md#requirement-an-invited-trainer-may-submit-a-werkproces-assessment
	 * @spec openspec/changes/internship-hours/specs/bpv/spec.md#requirement-a-week-of-bpv-hours-is-a-record-of-its-own
	 */
	private function actions(): array {
		return [
			[
				'id' => 'createWerkprocesAssessment',
				// Through learniq's own endpoint: a portal create carries no
				// assertion, and the assertion is where the sign-in level is
				// (an-invited-trainer-may-assess).
				'type' => 'endpoint-forward',
				'label' => 'Submit a werkproces assessment',
				'endpoint' => '/apps/learniq/api/portal/werkproces-assessments',
				'method' => 'POST',
				// An invited trainer may assess (Ruben, 4 October 2026). What
				// she signs in with is recorded on the assessment, and a school
				// may demand more through `bpv_assessment_min_assurance`.
				'minTrust' => 'low',
				'subjectField' => 'practicalTrainerId',
				'scopeClaim' => 'practicalTrainerId',
				'fields' => [
					'bpvPlacementId',
					'curriculumPlanId',
					'componentId',
					'kwalificatiedossierCode',
					'coreTaskCode',
					'werkprocesCode',
					'werkprocesLabel',
					'competencyId',
					'assessment',
					'notes',
				],
			],
			[
				'id' => 'approveHourWeek',
				// Through learniq's own endpoint, for the reason the assessment
				// moved there: a portal write carries no assertion, and the
				// assertion is where her sign-in level is.
				'type' => 'endpoint-forward',
				'label' => 'Approve the hours of a week',
				'endpoint' => '/apps/learniq/api/portal/hour-weeks/approve',
				'method' => 'POST',
				'minTrust' => 'low',
				'subjectField' => 'practicalTrainerId',
				'scopeClaim' => 'practicalTrainerId',
				'fields' => ['hourWeekId', 'hoursApproved', 'note'],
				// She picks the week from the weeks waiting for her instead of
				// typing a uuid. Portaliq fills a `collection` provider from the
				// SUBJECT-SCOPED collection over that schema, which for her is
				// `poHourWeeks` and so already filtered to `lifecycle:
				// submitted`: the list offers the weeks she may decide and
				// nothing else.
				'optionsProviders' => [
					'hourWeekId' => [
						'type' => 'collection',
						'register' => self::REGISTER,
						'schema' => 'bpv-hour-week',
						'labelField' => 'isoWeek',
						'valueField' => 'id',
					],
				],
				'fieldConfigs' => [
					'hourWeekId' => ['label' => 'The week you are approving', 'required' => true],
					'hoursApproved' => ['label' => 'Hours you approve', 'required' => true],
					'note' => ['label' => 'Why you approve another number'],
				],
				'submitLabel' => 'Approve these hours',
				'successMessage' => 'The hours are approved. Your student sees your decision and your note.',
			],
			[
				'id' => 'signPraktijkovereenkomst',
				'type' => 'create',
				'label' => 'Sign the praktijkovereenkomst',
				'register' => self::REGISTER,
				'schema' => 'pok-signature',
				'scopeField' => 'signerId',
				'scopeClaim' => 'practicalTrainerId',
				'minTrust' => 'substantial',
				'fields' => [
					'subjectId',
					'subjectVersion',
					'assuranceLevel',
					'method',
					'evidenceRef',
				],
			],
		];
	}//end actions()
}//end class
