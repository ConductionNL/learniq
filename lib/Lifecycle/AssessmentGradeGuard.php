<?php

/**
 * Scholiq Assessment Grade Guard
 *
 * Lifecycle guard for the AssessmentResult schema's `grade` transition. Enforces that
 * every item that requires manual scoring has a non-null manualScore in the result's
 * responses before the AssessmentResult may move from `submitted` to `graded`.
 *
 * Auto-scored-only attempts (no extendedText or null correctResponse items) may be
 * graded immediately because AssessmentScoringHandler sets all autoScores on submit.
 *
 * Legitimate PHP per ADR-031: "Lifecycle guard — business rule that must run before
 * a state transition and cannot be expressed as a schema declaration." Requires a
 * cross-schema query (AssessmentResult → Assessment → Item) to determine which
 * items need manual scoring.
 * Referenced from the AssessmentResult schema's x-openregister-lifecycle.transitions.grade.requires
 * in scholiq_register.json.
 *
 * @category Lifecycle
 * @package  OCA\Learniq\Lifecycle
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-7
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle;

use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * Guards the AssessmentResult `grade` transition.
 *
 * Only allows `submitted → graded` once every item flagged `needsManualScoring`
 * has a non-null `manualScore` in the result's responses. Attempts consisting
 * entirely of auto-scored items pass immediately (AssessmentScoringHandler already
 * set all autoScores on the submit transition).
 */
class AssessmentGradeGuard {

	/**
	 * OR register slug for Scholiq objects.
	 */
	private const SCHOLIQ_REGISTER = 'learniq';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR object service for Assessment and Item lookups.
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
	 * OR lifecycle guard entry-point.
	 *
	 * Called by OpenRegister's lifecycle engine before executing the `grade`
	 * transition on an AssessmentResult object.
	 *
	 * @param array<string,mixed> $transitionContext Context provided by OR's lifecycle engine:
	 *                                               - 'object'     : the AssessmentResult data array
	 *                                               - 'transition' : 'grade'
	 *                                               - 'from'       : 'submitted'
	 *                                               - 'to'         : 'graded'
	 *
	 * @return bool True if all manual-scoring items have scores; false blocks the transition.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-7
	 */
	public function check(array &$transitionContext): bool {
		$result = $transitionContext['object'] ?? [];
		$assessmentId = $result['assessmentId'] ?? null;
		$responses = $result['responses'] ?? [];
		$tenantId = $result['tenant_id'] ?? '';

		if ($assessmentId === null) {
			$this->logger->info(
				'[AssessmentGradeGuard] AssessmentResult has no assessmentId; blocking grade.'
			);
			return false;
		}

		$assessments = $this->objectService->findAll(
			[
				'register' => self::SCHOLIQ_REGISTER,
				'schema' => 'assessment',
				// H1: scope Assessment lookup to the same tenant.
				'filters' => $this->tenantScoped(filters: ['uuid' => $assessmentId], tenantId: $tenantId),
				'limit' => 1,
			]
		);

		if (empty($assessments) === true) {
			$this->logger->info(
				'[AssessmentGradeGuard] Assessment {id} not found; blocking grade.',
				['id' => $assessmentId]
			);
			return false;
		}

		$assessment = $assessments[0];
		$itemRefs = $assessment['itemRefs'] ?? [];

		// #196: collect all item IDs in one pass, then bulk-fetch in a single OR query
		// rather than issuing one query per item (which causes N+1 under load).
		$allItemIds = $this->collectItemIds(itemRefs: $itemRefs);
		if (empty($allItemIds) === true) {
			return true;
		}

		$unscored = $this->findUnscoredItem(
			itemRefs: $itemRefs,
			itemByUuid: $this->fetchItemsByUuid(itemIds: $allItemIds, tenantId: $tenantId),
			responseByItemId: $this->indexResponsesByItemId(responses: $responses),
		);

		if ($unscored !== null) {
			$this->logger->info(
				'[AssessmentGradeGuard] Item {itemId} (interactionType={type}) needs manual scoring but '
				. 'neither manualScore nor autoScore is set; blocking grade.',
				['itemId' => $unscored['itemId'], 'type' => $unscored['interactionType']]
			);
			return false;
		}

		return true;
	}//end check()

	/**
	 * Add the tenant filter to a filter set when a tenant scope is known.
	 *
	 * H1: every lookup this guard makes must be scoped to the AssessmentResult's
	 * own tenant, so neither the Assessment nor its Items can be read across a
	 * tenant boundary.
	 *
	 * @param array<string,mixed> $filters The filters built so far.
	 * @param string $tenantId Tenant UUID, or '' when unknown.
	 *
	 * @return array<string,mixed> The filters, tenant-scoped when possible.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-7
	 */
	private function tenantScoped(array $filters, string $tenantId): array {
		if ($tenantId !== '') {
			$filters['tenant_id'] = $tenantId;
		}

		return $filters;
	}//end tenantScoped()

	/**
	 * Index an AssessmentResult's responses by the item they answer.
	 *
	 * @param array<int,array<string,mixed>> $responses The result's responses.
	 *
	 * @return array<string,array<string,mixed>> Map of itemId => response.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-7
	 */
	private function indexResponsesByItemId(array $responses): array {
		$responseByItemId = [];
		foreach ($responses as $response) {
			$itemId = $response['itemId'] ?? null;
			if ($itemId !== null) {
				$responseByItemId[$itemId] = $response;
			}
		}

		return $responseByItemId;
	}//end indexResponsesByItemId()

	/**
	 * Collect the item UUIDs an Assessment's itemRefs point at.
	 *
	 * @param array<int,array<string,mixed>> $itemRefs The Assessment's itemRefs.
	 *
	 * @return array<int,string> Referenced item UUIDs, in reference order.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-7
	 */
	private function collectItemIds(array $itemRefs): array {
		$allItemIds = [];
		foreach ($itemRefs as $itemRef) {
			$itemId = $itemRef['itemId'] ?? null;
			if ($itemId !== null) {
				$allItemIds[] = $itemId;
			}
		}

		return $allItemIds;
	}//end collectItemIds()

	/**
	 * Bulk-fetch the referenced Items in one tenant-scoped query and index them
	 * by UUID (#196 — one query, not one per item).
	 *
	 * @param array<int,string> $itemIds Referenced item UUIDs.
	 * @param string $tenantId Tenant UUID, or '' when unknown.
	 *
	 * @return array<string,array<string,mixed>> Map of item UUID => item data.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-7
	 */
	private function fetchItemsByUuid(array $itemIds, string $tenantId): array {
		$fetchedItems = $this->objectService->findAll(
			[
				'register' => self::SCHOLIQ_REGISTER,
				'schema' => 'item',
				// H1: scope the Item lookup to the same tenant.
				'filters' => $this->tenantScoped(filters: ['uuid' => $itemIds], tenantId: $tenantId),
				'limit' => (count($itemIds) + 1),
			]
		);

		$itemByUuid = [];
		foreach ($fetchedItems as $rawItem) {
			$itemArr = $rawItem;
			if (is_array($rawItem) === false) {
				$itemArr = $rawItem->jsonSerialize();
			}

			$uuid = $itemArr['uuid'] ?? ($itemArr['id'] ?? null);
			if ($uuid !== null) {
				$itemByUuid[$uuid] = $itemArr;
			}
		}

		return $itemByUuid;
	}//end fetchItemsByUuid()

	/**
	 * Find the first referenced Item that needs manual scoring but carries no
	 * score at all, which is what blocks the grade transition.
	 *
	 * #199: an item without a correctResponse but with a non-null autoScore (a
	 * teacher corrected it through the scoring interface without using the
	 * manualScore field name) must not silently block grading, so autoScore is
	 * an acceptable fallback.
	 *
	 * @param array<int,array<string,mixed>> $itemRefs The Assessment's itemRefs.
	 * @param array<string,array<string,mixed>> $itemByUuid Bulk-fetched Items, indexed by UUID.
	 * @param array<string,array<string,mixed>> $responseByItemId The result's responses, indexed by itemId.
	 *
	 * @return array{itemId: string, interactionType: string}|null The blocking item, or null when all are scored.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-7
	 */
	private function findUnscoredItem(array $itemRefs, array $itemByUuid, array $responseByItemId): ?array {
		foreach ($itemRefs as $itemRef) {
			$itemId = $itemRef['itemId'] ?? null;
			if ($itemId === null) {
				continue;
			}

			// Bulk-fetched above (#196): O(1) lookup, tenant-scoped.
			$item = ($itemByUuid[$itemId] ?? null);
			if ($item === null) {
				continue;
			}

			if ($this->needsManualScoring(item: $item) === false) {
				continue;
			}

			$response = ($responseByItemId[$itemId] ?? null);
			if (($response['manualScore'] ?? null) === null && ($response['autoScore'] ?? null) === null) {
				return [
					'itemId' => (string)$itemId,
					'interactionType' => (string)($item['interactionType'] ?? ''),
				];
			}
		}//end foreach

		return null;
	}//end findUnscoredItem()

	/**
	 * Whether an Item can only be scored by a human.
	 *
	 * An extendedText interaction always is; so is any item whose
	 * `correctResponse` is null, because `AssessmentScoringHandler` had nothing
	 * to auto-score it against.
	 *
	 * @param array<string,mixed> $item The Item data.
	 *
	 * @return bool True when the item requires a manual score.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-7
	 */
	private function needsManualScoring(array $item): bool {
		if (($item['interactionType'] ?? '') === 'extendedText') {
			return true;
		}

		return (($item['correctResponse'] ?? null) === null);
	}//end needsManualScoring()
}//end class
