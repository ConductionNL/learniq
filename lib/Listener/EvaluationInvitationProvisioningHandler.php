<?php

/**
 * Scholiq Evaluation Invitation Provisioning Handler
 *
 * Listens for OpenRegister's ObjectTransitionedEvent, filtered to the
 * EvaluationCampaign schema's `open` transition. Resolves every learner in
 * the campaign's scope (courseIds directly, plus cohortIds — both resolved
 * via the referenced Cohort.learnerIds) and creates one EvaluationInvitation
 * per (campaignId, learnerId) pair, stamping courseId/cohortId/
 * academicYear/period/campaignClosesAt onto each row.
 *
 * ADR-031 legitimate exception: "Lifecycle handler — event-to-object-write
 * bridge that cannot be expressed as a schema declaration." Idempotency-
 * keyed: queries existing EvaluationInvitations for the campaign first and
 * never creates a second row for a learner who already has one, so a
 * duplicate/replayed `open` event (or a learner appearing in more than one
 * qualifying Cohort) cannot create duplicate invitations.
 *
 * @category Listener
 * @package  OCA\Learniq\Listener
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
 * @spec openspec/changes/course-evaluation/specs/course-evaluation/spec.md#requirement-persist-course-evaluation-domain-objects-in-openregister
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Bridges EvaluationCampaign `open` → one EvaluationInvitation per learner in scope.
 *
 * @implements IEventListener<Event>
 */
class EvaluationInvitationProvisioningHandler implements IEventListener {

	private const SCHOLIQ_REGISTER = 'scholiq';
	private const EVALUATION_CAMPAIGN_SCHEMA = 'evaluation-campaign';
	private const EVALUATION_INVITATION_SCHEMA = 'evaluation-invitation';
	private const COHORT_SCHEMA = 'cohort';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object access.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an ObjectTransitionedEvent.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/course-evaluation/specs/course-evaluation/spec.md#requirement-persist-course-evaluation-domain-objects-in-openregister
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectTransitionedEvent) === false) {
			return;
		}

		if ($this->isCampaignOpening(event: $event) === false) {
			return;
		}

		$campaign = $event->getObject()->jsonSerialize();
		$campaignId = $campaign['id'] ?? ($campaign['uuid'] ?? null);

		if ($campaignId === null) {
			$this->logger->warning(
				'[EvaluationInvitationProvisioningHandler] EvaluationCampaign has no id; cannot provision invitations.'
			);
			return;
		}

		$scopedCohorts = $this->resolveScopedCohorts(campaign: $campaign);

		if (empty($scopedCohorts) === true) {
			$this->logger->info(
				'[EvaluationInvitationProvisioningHandler] EvaluationCampaign {campaignId} opened with no '
				. 'resolvable Cohort scope (courseIds matched no Cohort, cohortIds empty/unresolvable); '
				. 'no invitations provisioned.',
				['campaignId' => $campaignId]
			);
			return;
		}

		$this->provisionInvitations(
			campaign: $campaign,
			campaignId: $campaignId,
			scopedCohorts: $scopedCohorts
		);

	}//end handle()

	/**
	 * Whether the event is an EvaluationCampaign entering the `open` state.
	 *
	 * @param ObjectTransitionedEvent $event The dispatched transition event.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/course-evaluation/specs/course-evaluation/spec.md#requirement-persist-course-evaluation-domain-objects-in-openregister
	 */
	private function isCampaignOpening(ObjectTransitionedEvent $event): bool {
		return $event->getRegister() === self::SCHOLIQ_REGISTER
			&& $event->getSchema() === self::EVALUATION_CAMPAIGN_SCHEMA
			&& $event->getTo() === 'open';

	}//end isCampaignOpening()

	/**
	 * Create one EvaluationInvitation per not-yet-invited learner across the
	 * campaign's resolved Cohort scope.
	 *
	 * @param array $campaign The EvaluationCampaign data.
	 * @param string $campaignId UUID of the EvaluationCampaign.
	 * @param array<int, array> $scopedCohorts Cohorts resolved to be in scope.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/course-evaluation/specs/course-evaluation/spec.md#requirement-persist-course-evaluation-domain-objects-in-openregister
	 */
	private function provisionInvitations(array $campaign, string $campaignId, array $scopedCohorts): void {
		$stamp = [
			'campaignId' => $campaignId,
			'campaignClosesAt' => $campaign['closesAt'] ?? null,
			'academicYear' => $campaign['academicYear'] ?? '',
			'period' => $campaign['period'] ?? '',
			'tenant_id' => $campaign['tenant_id'] ?? '',
		];

		$provisioned = $this->fetchExistingInvitedLearnerIds(campaignId: $campaignId);

		foreach ($scopedCohorts as $cohort) {
			if (empty($cohort['courseId'] ?? null) === true) {
				// A cohort with no courseId cannot back a course-scoped invitation
				// (design.md: course-evaluation is keyed to a Course). Skip, log,
				// fail soft — matches GradeRollupHandler's "insufficient data, skip"
				// shape.
				$this->logger->warning(
					'[EvaluationInvitationProvisioningHandler] Cohort {cohortId} in scope for campaign '
					. '{campaignId} has no courseId; skipping its learners.',
					['cohortId' => $cohort['id'] ?? '', 'campaignId' => $campaignId]
				);
				continue;
			}

			$this->inviteCohortLearners(cohort: $cohort, stamp: $stamp, provisioned: $provisioned);
		}//end foreach

	}//end provisionInvitations()

	/**
	 * Create an EvaluationInvitation for every learner of one Cohort that has
	 * not been invited yet, marking each as provisioned so a learner appearing
	 * in more than one in-scope Cohort is still invited only once.
	 *
	 * @param array $cohort One in-scope Cohort's data (courseId already validated).
	 * @param array<string, mixed> $stamp Campaign-level fields stamped onto every invitation.
	 * @param array<string, bool> $provisioned Learner ids already invited, updated in place.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/course-evaluation/specs/course-evaluation/spec.md#requirement-persist-course-evaluation-domain-objects-in-openregister
	 */
	private function inviteCohortLearners(array $cohort, array $stamp, array &$provisioned): void {
		$cohortId = $cohort['id'] ?? ($cohort['uuid'] ?? null);
		$learnerIds = $cohort['learnerIds'] ?? [];

		foreach ($learnerIds as $learnerId) {
			if (empty($learnerId) === true || isset($provisioned[$learnerId]) === true) {
				continue;
			}

			$provisioned[$learnerId] = true;

			$this->objectService->saveObject(
				register: self::SCHOLIQ_REGISTER,
				schema: self::EVALUATION_INVITATION_SCHEMA,
				object: [
					'campaignId' => $stamp['campaignId'],
					'courseId' => $cohort['courseId'],
					'cohortId' => $cohortId,
					'learnerId' => $learnerId,
					'hasResponded' => false,
					'respondedAt' => null,
					'campaignClosesAt' => $stamp['campaignClosesAt'],
					'academicYear' => $stamp['academicYear'],
					'period' => $stamp['period'],
					'tenant_id' => $stamp['tenant_id'],
				]
			);
		}//end foreach

	}//end inviteCohortLearners()

	/**
	 * Resolve every Cohort in the campaign's scope: Cohorts referenced directly via
	 * cohortIds, plus every Cohort whose courseId is in courseIds.
	 *
	 * @param array $campaign The EvaluationCampaign data.
	 *
	 * @return array<int, array> Cohort data arrays, de-duplicated by id.
	 *
	 * @spec openspec/changes/course-evaluation/specs/course-evaluation/spec.md#requirement-persist-course-evaluation-domain-objects-in-openregister
	 */
	private function resolveScopedCohorts(array $campaign): array {
		$byId = [];

		$this->collectCohortsById(campaign: $campaign, byId: $byId);
		$this->collectCohortsByCourse(campaign: $campaign, byId: $byId);

		return array_values($byId);
	}//end resolveScopedCohorts()

	/**
	 * Add every Cohort the campaign references directly via cohortIds.
	 *
	 * @param array $campaign The EvaluationCampaign data.
	 * @param array<string, array> $byId Accumulator keyed by cohort id, updated in place.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/course-evaluation/specs/course-evaluation/spec.md#requirement-persist-course-evaluation-domain-objects-in-openregister
	 */
	private function collectCohortsById(array $campaign, array &$byId): void {
		foreach (($campaign['cohortIds'] ?? []) as $cohortId) {
			if (empty($cohortId) === true || isset($byId[$cohortId]) === true) {
				continue;
			}

			$cohort = $this->objectService->find(
				id: $cohortId,
				register: self::SCHOLIQ_REGISTER,
				schema: self::COHORT_SCHEMA
			);

			if ($cohort === null) {
				continue;
			}

			$byId[$cohortId] = $cohort->jsonSerialize();
		}//end foreach

	}//end collectCohortsById()

	/**
	 * Add every Cohort whose courseId is one of the campaign's courseIds.
	 *
	 * @param array $campaign The EvaluationCampaign data.
	 * @param array<string, array> $byId Accumulator keyed by cohort id, updated in place.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/course-evaluation/specs/course-evaluation/spec.md#requirement-persist-course-evaluation-domain-objects-in-openregister
	 */
	private function collectCohortsByCourse(array $campaign, array &$byId): void {
		foreach (($campaign['courseIds'] ?? []) as $courseId) {
			if (empty($courseId) === true) {
				continue;
			}

			$matches = $this->objectService->findAll(
				[
					'register' => self::SCHOLIQ_REGISTER,
					'schema' => self::COHORT_SCHEMA,
					'filters' => ['courseId' => $courseId],
				]
			);

			foreach ($matches as $match) {
				$matchData = $match;
				if (is_array($match) === false) {
					$matchData = $match->jsonSerialize();
				}

				$cohortId = $matchData['id'] ?? ($matchData['uuid'] ?? null);
				if ($cohortId === null || isset($byId[$cohortId]) === true) {
					continue;
				}

				$byId[$cohortId] = $matchData;
			}
		}//end foreach

	}//end collectCohortsByCourse()

	/**
	 * Fetch the set of learnerIds that already have an EvaluationInvitation for
	 * this campaign, keyed by learnerId for O(1) lookup — the idempotency guard
	 * against duplicate provisioning on a replayed/duplicate `open` event.
	 *
	 * @param string $campaignId UUID of the EvaluationCampaign.
	 *
	 * @return array<string, bool>
	 *
	 * @spec openspec/changes/course-evaluation/specs/course-evaluation/spec.md#requirement-persist-course-evaluation-domain-objects-in-openregister
	 */
	private function fetchExistingInvitedLearnerIds(string $campaignId): array {
		$existing = $this->objectService->findAll(
			[
				'register' => self::SCHOLIQ_REGISTER,
				'schema' => self::EVALUATION_INVITATION_SCHEMA,
				'filters' => ['campaignId' => $campaignId],
			]
		);

		$learnerIds = [];
		foreach ($existing as $invitation) {
			$data = $invitation;
			if (is_array($invitation) === false) {
				$data = $invitation->jsonSerialize();
			}

			$learnerId = $data['learnerId'] ?? null;
			if ($learnerId !== null) {
				$learnerIds[$learnerId] = true;
			}
		}

		return $learnerIds;
	}//end fetchExistingInvitedLearnerIds()
}//end class
