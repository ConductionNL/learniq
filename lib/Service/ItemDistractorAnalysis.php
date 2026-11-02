<?php

/**
 * Learniq item distractor analysis.
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
 * @spec openspec/specs/assessment/spec.md#requirement-per-item-statistics-are-computed-from-graded-results-gated-by-a-minimum-sample-size
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

/**
 * Kelley 27%-split distractor analysis of one choice item over its response vectors.
 *
 * @spec openspec/specs/assessment/spec.md#requirement-per-item-statistics-are-computed-from-graded-results-gated-by-a-minimum-sample-size
 */
final class ItemDistractorAnalysis {

	use ParsesQtiBody;

	/**
	 * Kelley's 27% split for distractor-analysis upper/lower scoring groups.
	 */
	private const DISTRACTOR_SPLIT_FRACTION = 0.27;

	/**
	 * Kelley 27%-split distractor analysis of a choice item with an answer key:
	 * for every declared answer option, how many top-27%-by-total-score and
	 * bottom-27%-by-total-score respondents selected it.
	 *
	 * @param array<string,mixed>|null       $item    Item data (qtiBody).
	 * @param array<string,array<int,mixed>> $vectors The item's response vectors (selections and total scores, same order).
	 *
	 * @return array<int,array{optionId:string,selectedByHighGroup:int,selectedByLowGroup:int}>|null Null for other items.
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-per-item-statistics-are-computed-from-graded-results-gated-by-a-minimum-sample-size
	 */
	public function analyse(?array $item, array $vectors): ?array {
		$optionIds = $this->choiceOptions(item: $item);
		if (empty($optionIds) === true || count($vectors['totalScores']) === 0) {
			return null;
		}

		return self::optionCounts(
			optionIds: $optionIds,
			groups: self::kelleyGroups(selections: $vectors['selections'], totalScores: $vectors['totalScores'])
		);
	}//end analyse()

	/**
	 * Per answer option, how many of the high and of the low group selected it.
	 *
	 * @param array<int,string>                                   $optionIds The declared options.
	 * @param array{0:list<string|null>,1:list<string|null>} $groups    The high group's and the low group's selections.
	 *
	 * @return array<int,array{optionId:string,selectedByHighGroup:int,selectedByLowGroup:int}>
	 */
	private static function optionCounts(array $optionIds, array $groups): array {
		$result = [];
		foreach ($optionIds as $optionId) {
			$result[] = [
				'optionId' => $optionId,
				'selectedByHighGroup' => count(array_keys($groups[0], $optionId, true)),
				'selectedByLowGroup' => count(array_keys($groups[1], $optionId, true)),
			];
		}

		return $result;
	}//end optionCounts()

	/**
	 * The declared answer options of a choice item with an answer key; none for other items.
	 *
	 * @param array<string,mixed>|null $item Item data.
	 *
	 * @return array<int,string>
	 */
	private function choiceOptions(?array $item): array {
		if (self::isChoiceWithKey(item: $item) === false) {
			return [];
		}

		return $this->extractChoiceIdentifiers(qtiBody: (string)($item['qtiBody'] ?? ''));
	}//end choiceOptions()

	/**
	 * Whether the item is a choice item with an answer key.
	 *
	 * @param array<string,mixed>|null $item Item data.
	 *
	 * @return bool
	 */
	private static function isChoiceWithKey(?array $item): bool {
		return ($item !== null)
			&& (($item['interactionType'] ?? null) === 'choice')
			&& (($item['correctResponse'] ?? null) !== null);
	}//end isChoiceWithKey()

	/**
	 * The selections of the top and the bottom group by total score.
	 *
	 * @param array<int,string|null> $selections  Per-respondent selected option (or null).
	 * @param array<int,float>       $totalScores Per-respondent total score, same order.
	 *
	 * @return array{0:list<string|null>,1:list<string|null>} The high group's and the low group's selections.
	 */
	private static function kelleyGroups(array $selections, array $totalScores): array {
		$indices = range(0, (count($totalScores) - 1));
		usort($indices, static fn (int $a, int $b) => ($totalScores[$b] <=> $totalScores[$a]));
		$groupSize = self::kelleyGroupSize(respondents: count($totalScores));
		$selectionAt = static fn (int $index): ?string => ($selections[$index] ?? null);

		return [
			array_map($selectionAt, array_slice($indices, 0, $groupSize)),
			array_map($selectionAt, array_slice($indices, -$groupSize)),
		];
	}//end kelleyGroups()

	/**
	 * The size of each Kelley group: 27% of the respondents, at least 1 and at most half.
	 *
	 * @param int $respondents The number of respondents.
	 *
	 * @return int
	 */
	private static function kelleyGroupSize(int $respondents): int {
		$groupSize = (int)round($respondents * self::DISTRACTOR_SPLIT_FRACTION);
		$groupSize = max(1, $groupSize);
		$groupSize = min($groupSize, (int)floor($respondents / 2));
		if ($groupSize < 1) {
			$groupSize = 1;
		}

		return $groupSize;
	}//end kelleyGroupSize()

	/**
	 * Extract QTI simpleChoice `identifier` values, in declared order.
	 *
	 * @param string $qtiBody Raw QTI 2.1 XML body.
	 *
	 * @return array<int,string>
	 */
	private function extractChoiceIdentifiers(string $qtiBody): array {
		if (trim($qtiBody) === '') {
			return [];
		}

		$doc = $this->parseQtiBody(qtiBody: $qtiBody);
		if ($doc === null) {
			return [];
		}

		$identifiers = [];
		foreach ($doc->getElementsByTagName('simpleChoice') as $node) {
			$identifier = $node->getAttribute('identifier');
			if ($identifier !== '') {
				$identifiers[] = $identifier;
			}
		}

		return $identifiers;
	}//end extractChoiceIdentifiers()
}//end class
