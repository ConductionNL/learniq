<?php

/**
 * Learniq Competency Attainment Roll-up
 *
 * The work CompetencyAttainmentRollupHandler used to do inside the save that
 * triggered it, now run from CompetencyAttainmentRollupJob (gate 61, ADR-078):
 *
 * 1. WerkprocesAssessment created: match its werkprocesCode against a
 *    Competency.code under an sbb-kwalificatiedossier CompetencyFramework and,
 *    on a match, write competencyId back onto the assessment. competencyId is
 *    never client input. A miss leaves it null and blocks nothing.
 * 2. GradeEntry published: GradeEvidenceRollup rolls competency-aligned
 *    evidence (assignment submission or assessment result) into
 *    CompetencyAttainment.
 * 3. WerkprocesAssessment confirmed: upsert the learner's CompetencyAttainment
 *    for the assessment's competency, at the level its beoordeling names.
 *
 * ADR-031 legitimate exception: cross-schema object writes that no schema
 * declaration can express. Never a TimedJob (ADR-022).
 *
 * @category Service
 * @package  OCA\Learniq\Service
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
 * @spec openspec/specs/competency/spec.md#requirement-competencyattainment-is-a-declared-event-driven-per-learner-roll-up-never-a-timedjob
 * @spec openspec/specs/bpv/spec.md#requirement-werkprocesassessment-aligns-to-the-kwalificatiedossier-and-emits-a-gradeentry
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * Resolves werkproces competencies and rolls evidence into CompetencyAttainment.
 *
 * @spec openspec/specs/competency/spec.md#requirement-the-competency-attainment-roll-up-runs-outside-the-save-that-triggers-it
 */
class CompetencyAttainmentRollup {

	/**
	 * A WerkprocesAssessment was created.
	 */
	public const WERKPROCES_CREATED = 'werkproces-created';

	/**
	 * A GradeEntry was published.
	 */
	public const GRADE_ENTRY_PUBLISHED = 'grade-entry-published';

	/**
	 * A WerkprocesAssessment was confirmed.
	 */
	public const WERKPROCES_CONFIRMED = 'werkproces-confirmed';

	private const LEARNIQ_REGISTER = 'learniq';
	private const WERKPROCES_SCHEMA = 'werkproces-assessment';
	private const BPV_PLACEMENT_SCHEMA = 'bpv-placement';
	private const COMPETENCY_SCHEMA = 'competency';
	private const FRAMEWORK_SCHEMA = 'competency-framework';

	private const SBB_SOURCE_AUTHORITY = 'sbb-kwalificatiedossier';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object access.
	 * @param LoggerInterface $logger Logger.
	 * @param ObjectRowReader $reader Reads rows as arrays.
	 * @param CompetencyAttainmentWriter $attainment Upserts CompetencyAttainment rows.
	 * @param CompetencyLevelResolver $levelResolver Maps a beoordeling to a level.
	 * @param GradeEvidenceRollup $gradeEvidence Rolls published grades into attainment.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
		private readonly ObjectRowReader $reader,
		private readonly CompetencyAttainmentWriter $attainment,
		private readonly CompetencyLevelResolver $levelResolver,
		private readonly GradeEvidenceRollup $gradeEvidence,
	) {
	}//end __construct()

	/**
	 * Run the roll-up owed for one event.
	 *
	 * @param string $kind One of the kind constants.
	 * @param array<string, mixed> $object The object as it was saved.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/competency/spec.md#requirement-the-competency-attainment-roll-up-runs-outside-the-save-that-triggers-it
	 */
	public function run(string $kind, array $object): void {
		if ($kind === self::WERKPROCES_CREATED) {
			$this->resolveWerkprocesCompetencyId(data: $object);
			return;
		}

		if ($kind === self::GRADE_ENTRY_PUBLISHED) {
			$this->gradeEvidence->rollupPublishedGradeEntry(entry: $object);
			return;
		}

		if ($kind === self::WERKPROCES_CONFIRMED) {
			$this->handleWerkprocesConfirmed(assessment: $object);
		}
	}//end run()

	/**
	 * Resolve and persist WerkprocesAssessment.competencyId at creation time.
	 *
	 * Matches werkprocesCode against Competency.code scoped to an
	 * sbb-kwalificatiedossier CompetencyFramework. A miss leaves competencyId
	 * null and never blocks creation or the existing confirm/GradeEntry flow.
	 *
	 * @param array<string,mixed> $data The newly created WerkprocesAssessment data.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bpv/spec.md#requirement-werkprocesassessment-aligns-to-the-kwalificatiedossier-and-emits-a-gradeentry
	 */
	private function resolveWerkprocesCompetencyId(array $data): void {
		// Defensive no-op: competencyId is never client-settable (not in the
		// portal whitelist), but if it is already set (e.g. a re-fired event
		// on an already-resolved row) there is nothing to do.
		if (empty($data['competencyId']) === false) {
			return;
		}

		$werkprocesCode = $data['werkprocesCode'] ?? '';
		if ($werkprocesCode === '') {
			return;
		}

		$tenantId = $data['tenant_id'] ?? '';

		$competency = $this->findCompetencyByCode(
			code: $werkprocesCode,
			dossierCode: trim((string)($data['kwalificatiedossierCode'] ?? '')),
			tenantId: $tenantId
		);
		if ($competency === null) {
			$this->logger->info(
				'[CompetencyAttainmentRollup] WerkprocesAssessment {id}: werkprocesCode "{code}" has no '
				. 'matching Competency under an sbb-kwalificatiedossier framework — competencyId stays null.',
				['id' => $data['id'] ?? ($data['uuid'] ?? ''), 'code' => $werkprocesCode]
			);
			return;
		}

		$competencyId = $competency['id'] ?? ($competency['uuid'] ?? null);
		if ($competencyId === null) {
			return;
		}

		$this->objectService->saveObject(
			register: self::LEARNIQ_REGISTER,
			schema: self::WERKPROCES_SCHEMA,
			object: array_merge($data, ['competencyId' => $competencyId])
		);

		$this->logger->info(
			'[CompetencyAttainmentRollup] WerkprocesAssessment {id}: resolved competencyId {cid} from '
			. 'werkprocesCode "{code}".',
			['id' => $data['id'] ?? ($data['uuid'] ?? ''), 'cid' => $competencyId, 'code' => $werkprocesCode]
		);

	}//end resolveWerkprocesCompetencyId()

	/**
	 * Find the one Competency whose code matches, inside the assessment's own
	 * SBB kwalificatiedossier.
	 *
	 * SBB repeats werkproces codes (`B1-K1-W1`) in every dossier, so the code
	 * alone does not name a competency. The search runs in the frameworks
	 * whose `sourceRef` is the assessment's `kwalificatiedossierCode`; when no
	 * framework carries that code it runs in every SBB framework of the
	 * tenant. Either way exactly one match resolves: none or several leave
	 * the assessment unresolved, never the first of several.
	 *
	 * @param string $code The werkprocesCode to match.
	 * @param string $dossierCode The assessment's kwalificatiedossierCode, or ''.
	 * @param string $tenantId Tenant UUID scope filter.
	 *
	 * @return array<string,mixed>|null The matching Competency data, or null when none or several match.
	 *
	 * @spec openspec/specs/bpv/spec.md#requirement-werkprocesassessment-aligns-to-the-kwalificatiedossier-and-emits-a-gradeentry
	 * @spec openspec/specs/bpv/spec.md#requirement-a-werkproces-code-resolves-inside-the-assessments-own-kwalificatiedossier
	 */
	private function findCompetencyByCode(string $code, string $dossierCode, string $tenantId): ?array {
		$matches = [];
		foreach ($this->sbbFrameworkIds(dossierCode: $dossierCode, tenantId: $tenantId) as $frameworkId) {
			$competency = $this->competencyInFramework(frameworkId: $frameworkId, code: $code, tenantId: $tenantId);
			if ($competency !== null) {
				$matches[] = $competency;
			}

			if (count($matches) > 1) {
				$this->logger->info(
					'[CompetencyAttainmentRollup] werkprocesCode "{code}" matches a Competency in more than one '
					. 'sbb-kwalificatiedossier framework and none carries dossier code "{dossier}" as sourceRef; '
					. 'competencyId stays null.',
					['code' => $code, 'dossier' => $dossierCode]
				);
				return null;
			}
		}

		return $matches[0] ?? null;
	}//end findCompetencyByCode()

	/**
	 * The ids of the SBB frameworks a werkproces code is searched in.
	 *
	 * @param string $dossierCode The assessment's kwalificatiedossierCode, or ''.
	 * @param string $tenantId Tenant UUID scope filter.
	 *
	 * @return array<int,string> The frameworks whose sourceRef is the dossier code, else every SBB framework.
	 *
	 * @spec openspec/specs/bpv/spec.md#requirement-a-werkproces-code-resolves-inside-the-assessments-own-kwalificatiedossier
	 */
	private function sbbFrameworkIds(string $dossierCode, string $tenantId): array {
		$frameworkFilters = ['sourceAuthority' => self::SBB_SOURCE_AUTHORITY];
		if ($tenantId !== '') {
			$frameworkFilters['tenant_id'] = $tenantId;
		}

		$frameworks = $this->objectService->findAll(
			[
				'filters' => array_merge(
					$frameworkFilters,
					[
						'register' => self::LEARNIQ_REGISTER,
						'schema' => self::FRAMEWORK_SCHEMA,
					]
				),
			]
		);

		$every = [];
		$ownDossier = [];
		foreach ($frameworks as $framework) {
			$frameworkData = $this->reader->toArray(object: $framework);
			$frameworkId = $frameworkData['id'] ?? ($frameworkData['uuid'] ?? null);
			if ($frameworkId === null) {
				continue;
			}

			$every[] = (string)$frameworkId;
			if ($dossierCode !== '' && trim((string)($frameworkData['sourceRef'] ?? '')) === $dossierCode) {
				$ownDossier[] = (string)$frameworkId;
			}
		}

		if ($ownDossier !== []) {
			return $ownDossier;
		}

		return $every;
	}//end sbbFrameworkIds()

	/**
	 * The Competency with this code in one framework, or null.
	 *
	 * @param string $frameworkId The CompetencyFramework id.
	 * @param string $code The werkprocesCode to match.
	 * @param string $tenantId Tenant UUID scope filter.
	 *
	 * @return array<string,mixed>|null
	 *
	 * @spec openspec/specs/bpv/spec.md#requirement-a-werkproces-code-resolves-inside-the-assessments-own-kwalificatiedossier
	 */
	private function competencyInFramework(string $frameworkId, string $code, string $tenantId): ?array {
		$competencyFilters = ['frameworkId' => $frameworkId, 'code' => $code];
		if ($tenantId !== '') {
			$competencyFilters['tenant_id'] = $tenantId;
		}

		$competencies = $this->objectService->findAll(
			[
				'filters' => array_merge(
					$competencyFilters,
					[
						'register' => self::LEARNIQ_REGISTER,
						'schema' => self::COMPETENCY_SCHEMA,
					]
				),
				'limit' => 1,
			]
		);

		if (empty($competencies) === true) {
			return null;
		}

		return $this->reader->toArray(object: $competencies[0]);
	}//end competencyInFramework()

	/**
	 * Roll up a confirmed WerkprocesAssessment with a resolved competencyId.
	 *
	 * Uses the assessment's own generalized competencyId directly — no join
	 * needed. A null competencyId (unresolved kwalificatiedossier code) is a
	 * no-op: confirmation is never blocked by this handler.
	 *
	 * @param array<string,mixed> $assessment The confirmed WerkprocesAssessment data.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bpv/spec.md#requirement-werkprocesassessment-aligns-to-the-kwalificatiedossier-and-emits-a-gradeentry
	 */
	private function handleWerkprocesConfirmed(array $assessment): void {
		$competencyId = $assessment['competencyId'] ?? null;
		if (empty($competencyId) === true) {
			return;
		}

		$bpvPlacementId = $assessment['bpvPlacementId'] ?? '';
		$placement = $this->reader->load(schema: self::BPV_PLACEMENT_SCHEMA, id: (string)$bpvPlacementId);
		if ($placement === null) {
			return;
		}

		$learnerId = $placement['learnerId'] ?? '';
		if ($learnerId === '') {
			return;
		}

		$tenantId = $placement['tenant_id'] ?? ($assessment['tenant_id'] ?? '');

		$beoordeling = $assessment['assessment'] ?? '';
		$levelId = $this->levelResolver->resolveLevelByLabel(competencyId: $competencyId, assessment: $beoordeling);

		$assessmentId = $assessment['id'] ?? ($assessment['uuid'] ?? '');
		$this->attainment->upsertAttainment(
			learnerId: $learnerId,
			competencyId: $competencyId,
			tenantId: $tenantId,
			evidenceAppend: ['werkprocesAssessmentIds' => $assessmentId],
			percent: null,
			levelId: $levelId
		);

	}//end handleWerkprocesConfirmed()
}//end class
