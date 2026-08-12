<?php

/**
 * Scholiq Assessment Scoring Handler
 *
 * Lifecycle guard/handler for the AssessmentResult schema's `submit` transition.
 * On submit, auto-scores each response by comparing it against the parent Item's
 * correctResponse. Items with interactionType `extendedText` or a null
 * correctResponse are left with autoScore null (they require teacher manual scoring).
 *
 * This is a legitimate PHP exception per ADR-031 §"Calculation engine": auto-scoring
 * is a domain algorithm above what schema metadata can express. It runs as a `requires:`
 * guard on the `submit` transition. It returns true when the parent Assessment is
 * accessible and scoring is applied. It returns false (fail-closed) when the parent
 * Assessment cannot be resolved — blocking the transition to prevent client-controlled
 * autoScore values from persisting (wave-12 WF3).
 *
 * Referenced from the AssessmentResult schema's
 * x-openregister-lifecycle.transitions.submit.requires in scholiq_register.json.
 *
 * @category Lifecycle
 * @package  OCA\Scholiq\Lifecycle
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
 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-8
 */

declare(strict_types=1);

namespace OCA\Scholiq\Lifecycle;

use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * Runs auto-scoring on AssessmentResult submit transition.
 *
 * Evaluates each response against the matching Item's correctResponse:
 * - For choice/textEntry/hotspot/order/match/gapMatch/inlineChoice: compares the
 *   response value to correctResponse and awards maxScore (or 0) accordingly.
 * - For extendedText or null correctResponse: leaves autoScore null (needs teacher).
 *
 * Returns true when scoring succeeds or when the Assessment is not yet needed (no responses).
 * Returns false (fail-closed) when the parent Assessment cannot be resolved — this blocks
 * the submit transition to prevent client-controlled autoScore values from persisting.
 */
class AssessmentScoringHandler {

	/**
	 * OR register slug for Scholiq objects.
	 */
	private const SCHOLIQ_REGISTER = 'scholiq';

	/**
	 * Interaction types that can be auto-scored.
	 */
	private const AUTO_SCORABLE = ['choice', 'textEntry', 'hotspot', 'order', 'match', 'gapMatch', 'inlineChoice'];

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
	 * OR lifecycle guard entry-point — always allows the transition, but scores responses first.
	 *
	 * Called by OpenRegister's lifecycle engine on the `submit` transition.
	 * Mutates $transitionContext['object']['responses'] to populate `autoScore` for
	 * each auto-scorable item. Items requiring manual scoring remain with autoScore null.
	 *
	 * @param array<string,mixed> $transitionContext Context provided by OR's lifecycle engine:
	 *                                               - 'object'     : the AssessmentResult data array (mutated)
	 *                                               - 'transition' : 'submit'
	 *                                               - 'from'       : 'in-progress'
	 *                                               - 'to'         : 'submitted'
	 *
	 * @return bool True when scoring succeeds or when there are no responses to score.
	 *              False (fail-closed) when the parent Assessment cannot be resolved —
	 *              this blocks the submit transition to prevent attacker-controlled autoScore.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-8
	 */
	public function check(array &$transitionContext): bool {
		$result = &$transitionContext['object'];
		$assessmentId = $result['assessmentId'] ?? null;
		$responses = $result['responses'] ?? [];

		if ($assessmentId === null || empty($responses) === true) {
			return true;
		}

		$tenantId = $result['tenant_id'] ?? '';

		// Fetch the parent Assessment for itemRefs and their point overrides.
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
			// Fail-CLOSED: if the parent Assessment is unreachable (different tenant,
			// deleted, or attacker-supplied bogus assessmentId), block the submit
			// transition rather than allowing client-controlled autoScore values through.
			// See wave-12 WF3.
			$this->logger->warning(
				'[AssessmentScoringHandler] Assessment {id} not found or out-of-tenant; blocking submit transition (fail-closed).',
				['id' => $assessmentId]
			);
			return false;
		}

		$assessment = $assessments[0];
		$pointsByItemId = $this->pointsByItemId(itemRefs: ($assessment['itemRefs'] ?? []));

		// Score each response.
		foreach ($responses as &$response) {
			$itemId = $response['itemId'] ?? null;
			if ($itemId === null) {
				continue;
			}

			$response['autoScore'] = $this->autoScoreFor(
				itemId: $itemId,
				response: $response,
				tenantId: $tenantId,
				pointsByItemId: $pointsByItemId,
			);
		}

		unset($response);
		$result['responses'] = $responses;

		$this->logger->info(
			'[AssessmentScoringHandler] Auto-scored {count} responses for AssessmentResult.',
			['count' => count($responses)]
		);

		return true;
	}//end check()

	/**
	 * Add the tenant filter to a filter set when a tenant scope is known.
	 *
	 * H1: both the Assessment and the Item lookups must be scoped to the
	 * AssessmentResult's own tenant, so neither can be read across a boundary.
	 *
	 * @param array<string,mixed> $filters The filters built so far.
	 * @param string $tenantId Tenant UUID, or '' when unknown.
	 *
	 * @return array<string,mixed> The filters, tenant-scoped when possible.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-8
	 */
	private function tenantScoped(array $filters, string $tenantId): array {
		if ($tenantId !== '') {
			$filters['tenant_id'] = $tenantId;
		}

		return $filters;
	}//end tenantScoped()

	/**
	 * Build the itemId => points-override map from an Assessment's itemRefs.
	 *
	 * @param array<int,array<string,mixed>> $itemRefs The Assessment's itemRefs.
	 *
	 * @return array<string,mixed> Map of itemId => points override (may be null).
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-8
	 */
	private function pointsByItemId(array $itemRefs): array {
		$pointsByItemId = [];
		foreach ($itemRefs as $itemRef) {
			$itemId = $itemRef['itemId'] ?? null;
			if ($itemId !== null) {
				$pointsByItemId[$itemId] = ($itemRef['points'] ?? null);
			}
		}

		return $pointsByItemId;
	}//end pointsByItemId()

	/**
	 * Resolve the autoScore for one response.
	 *
	 * Fails closed in both directions: an unreachable Item scores 0.0 rather
	 * than leaving the client-supplied value intact, and an item that only a
	 * human can score gets null rather than a machine guess.
	 *
	 * @param string $itemId The item the response answers.
	 * @param array<string,mixed> $response The learner's response row.
	 * @param string $tenantId Tenant UUID, or '' when unknown.
	 * @param array<string,mixed> $pointsByItemId Per-item points overrides from the Assessment.
	 *
	 * @return float|null The auto score, or null when the item needs manual scoring.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-8
	 */
	private function autoScoreFor(string $itemId, array $response, string $tenantId, array $pointsByItemId): ?float {
		$items = $this->objectService->findAll(
			[
				'register' => self::SCHOLIQ_REGISTER,
				'schema' => 'item',
				// H1: scope Item lookup to the same tenant.
				'filters' => $this->tenantScoped(filters: ['uuid' => $itemId], tenantId: $tenantId),
				'limit' => 1,
			]
		);

		if (empty($items) === true) {
			// Item unreachable — zero out autoScore rather than leaving the client value
			// intact, so an out-of-tenant item reference cannot carry an attacker-supplied
			// score through.
			return 0.0;
		}

		$item = $items[0];
		$interactionType = ($item['interactionType'] ?? '');
		$correctResponse = ($item['correctResponse'] ?? null);

		// An extendedText interaction always needs a human, and so does any item
		// with nothing to score against.
		if ($interactionType === 'extendedText' || $correctResponse === null) {
			return null;
		}

		if (in_array($interactionType, self::AUTO_SCORABLE, true) === false) {
			return null;
		}

		return $this->scoreResponse(
			interactionType: $interactionType,
			learnerResponse: ($response['response'] ?? null),
			correctResponse: $correctResponse,
			maxScore: (float)($pointsByItemId[$itemId] ?? $item['maxScore'] ?? 0)
		);
	}//end autoScoreFor()

	/**
	 * Score a single response against the item's correctResponse.
	 *
	 * For choice, textEntry, inlineChoice: exact match wins full marks.
	 * For order, match, gapMatch: partial scoring by matched count / total.
	 * For hotspot: treats correctResponse as array of accepted identifiers.
	 * Unknown interactions return 0.
	 *
	 * @param string $interactionType QTI 3.0 interaction type.
	 * @param mixed $learnerResponse Learner's response value.
	 * @param mixed $correctResponse Item's declared correct response.
	 * @param float $maxScore Maximum points for this item (from itemRefs override or item).
	 *
	 * @return float Score in range [0, maxScore].
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-8
	 */
	private function scoreResponse(
		string $interactionType,
		mixed $learnerResponse,
		mixed $correctResponse,
		float $maxScore,
	): float {
		if ($learnerResponse === null || $correctResponse === null) {
			return 0.0;
		}

		return match ($interactionType) {
			'choice', 'textEntry', 'inlineChoice' => $this->scoreExactMatch(
				learnerResponse: $learnerResponse,
				correctResponse: $correctResponse,
				maxScore: $maxScore
			),
			'order', 'match', 'gapMatch' => $this->scorePositionalMatch(
				learnerResponse: $learnerResponse,
				correctResponse: $correctResponse,
				maxScore: $maxScore
			),
			'hotspot' => $this->scoreHotspot(
				learnerResponse: $learnerResponse,
				correctResponse: $correctResponse,
				maxScore: $maxScore
			),
			default => 0.0,
		};
	}//end scoreResponse()

	/**
	 * All-or-nothing scoring: the response matches the declared answer exactly,
	 * compared case- and whitespace-insensitively when both sides are strings.
	 *
	 * @param mixed $learnerResponse Learner's response value.
	 * @param mixed $correctResponse Item's declared correct response.
	 * @param float $maxScore Maximum points for this item.
	 *
	 * @return float Either the full maxScore or 0.0.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-8
	 */
	private function scoreExactMatch(mixed $learnerResponse, mixed $correctResponse, float $maxScore): float {
		$learner = $learnerResponse;
		if (is_string($learnerResponse) === true) {
			$learner = mb_strtolower(trim($learnerResponse));
		}

		$correct = $correctResponse;
		if (is_string($correctResponse) === true) {
			$correct = mb_strtolower(trim($correctResponse));
		}

		if ($learner === $correct) {
			return $maxScore;
		}

		return 0.0;
	}//end scoreExactMatch()

	/**
	 * Partial scoring by position: award marks proportionally for each element
	 * the learner placed where the declared answer expects it.
	 *
	 * @param mixed $learnerResponse Learner's response value.
	 * @param mixed $correctResponse Item's declared correct response.
	 * @param float $maxScore Maximum points for this item.
	 *
	 * @return float Score in range [0, maxScore], rounded to 2 decimals.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-8
	 */
	private function scorePositionalMatch(mixed $learnerResponse, mixed $correctResponse, float $maxScore): float {
		if (is_array($learnerResponse) === false || is_array($correctResponse) === false) {
			return 0.0;
		}

		$totalExpected = count($correctResponse);
		if ($totalExpected === 0) {
			return 0.0;
		}

		$correctCount = 0;
		foreach ($correctResponse as $idx => $expected) {
			if (isset($learnerResponse[$idx]) === true && $learnerResponse[$idx] === $expected) {
				$correctCount++;
			}
		}

		return round((($correctCount / $totalExpected) * $maxScore), 2);
	}//end scorePositionalMatch()

	/**
	 * Partial scoring for hotspot interactions.
	 *
	 * #185: award marks proportionally for the fraction of required hotspots the
	 * learner hit, rather than full marks for any single hit. Wrong hits do not
	 * subtract.
	 *
	 * @param mixed $learnerResponse Learner's response value.
	 * @param mixed $correctResponse Item's declared correct response.
	 * @param float $maxScore Maximum points for this item.
	 *
	 * @return float Score in range [0, maxScore], rounded to 2 decimals.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-8
	 */
	private function scoreHotspot(mixed $learnerResponse, mixed $correctResponse, float $maxScore): float {
		$required = $correctResponse;
		if (is_array($required) === false) {
			$required = [$required];
		}

		$chosen = $learnerResponse;
		if (is_array($chosen) === false) {
			$chosen = [$chosen];
		}

		$totalRequired = count($required);
		if ($totalRequired === 0) {
			return 0.0;
		}

		$hits = count(array_intersect($chosen, $required));

		return round((($hits / $totalRequired) * $maxScore), 2);
	}//end scoreHotspot()
}//end class
