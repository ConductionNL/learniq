<?php

/**
 * Learniq Portal Answer Shape
 *
 * Whether a pupil's answer fits a presented item: an option id for a choice,
 * a list of distinct option ids for an order, a map from source ids to target
 * ids for a match, and text of at most 20,000 characters for anything else.
 * Pure.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Portal
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
 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Portal;

/**
 * Answer shape checks for a presented item.
 *
 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
 */
class PortalAnswerShape {

	/**
	 * The longest text answer accepted.
	 */
	public const MAX_TEXT_LENGTH = 20000;

	/**
	 * Whether an answer fits a presented item's type and options.
	 *
	 * @param array<string, mixed> $presented The item as present() returned it.
	 * @param mixed $response The pupil's answer.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/assessment/spec.md#requirement-a-portal-attempt-follows-every-test-rule-inside-the-endpoints
	 */
	public function acceptsResponse(array $presented, mixed $response): bool {
		return match ((string)($presented['type'] ?? '')) {
			'choice', 'inlineChoice' => is_string($response) === true
				&& $this->isOneOf(value: $response, options: ($presented['choices'] ?? [])),
			'order' => $this->isOrder(response: $response, options: ($presented['choices'] ?? [])),
			'match' => $this->isMatch(response: $response, sources: ($presented['sources'] ?? []), targets: ($presented['targets'] ?? [])),
			default => is_string($response) === true && mb_strlen($response) <= self::MAX_TEXT_LENGTH,
		};
	}//end acceptsResponse()

	/**
	 * Whether a value is one of the options' ids (any value when there are none).
	 *
	 * @param string $value The value.
	 * @param mixed $options The options.
	 *
	 * @return bool
	 */
	private function isOneOf(string $value, mixed $options): bool {
		$ids = $this->ids(options: $options);

		return $ids === [] || in_array($value, $ids, true) === true;
	}//end isOneOf()

	/**
	 * Whether a response is a list of distinct option ids.
	 *
	 * @param mixed $response The answer.
	 * @param mixed $options The options.
	 *
	 * @return bool
	 */
	private function isOrder(mixed $response, mixed $options): bool {
		if (is_array($response) === false || array_is_list($response) === false
			|| count($response) !== count(array_unique($response, SORT_REGULAR))
		) {
			return false;
		}

		foreach ($response as $id) {
			if (is_string($id) === false || $this->isOneOf(value: $id, options: $options) === false) {
				return false;
			}
		}

		return true;
	}//end isOrder()

	/**
	 * Whether a response maps source ids to target ids.
	 *
	 * @param mixed $response The answer.
	 * @param mixed $sources The source options.
	 * @param mixed $targets The target options.
	 *
	 * @return bool
	 */
	private function isMatch(mixed $response, mixed $sources, mixed $targets): bool {
		if (is_array($response) === false) {
			return false;
		}

		foreach ($response as $source => $target) {
			if (is_string($source) === false || is_string($target) === false
				|| $this->isOneOf(value: $source, options: $sources) === false
				|| $this->isOneOf(value: $target, options: $targets) === false
			) {
				return false;
			}
		}

		return true;
	}//end isMatch()

	/**
	 * The ids of a list of options.
	 *
	 * @param mixed $options The options.
	 *
	 * @return array<int, string>
	 */
	private function ids(mixed $options): array {
		if (is_array($options) === false) {
			return [];
		}

		return array_values(
			array_filter(
				array_map(static fn (mixed $option): mixed => ((array)$option)['id'] ?? null, $options),
				'is_string'
			)
		);
	}//end ids()
}//end class
