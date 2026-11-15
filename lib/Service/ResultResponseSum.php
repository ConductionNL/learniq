<?php

/**
 * Learniq Result Response Sum
 *
 * Sums the per-item scores of an AssessmentResult.
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
 * @spec openspec/specs/course-management/spec.md#requirement-lesson-declares-per-learner-release-conditions
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

/**
 * The total score of an AssessmentResult's responses.
 *
 * @spec openspec/specs/course-management/spec.md#requirement-lesson-declares-per-learner-release-conditions
 */
final class ResultResponseSum {

	/**
	 * Sum an AssessmentResult's per-item scores, preferring `autoScore` and
	 * falling back to `manualScore`; a response with neither contributes 0.
	 *
	 * @param mixed $responses The AssessmentResult's `responses` array.
	 *
	 * @return float
	 *
	 * @spec openspec/specs/course-management/spec.md#requirement-lesson-declares-per-learner-release-conditions
	 */
	public function sumOf($responses): float {
		if (is_array($responses) === false) {
			return 0.0;
		}

		$sum = 0.0;
		foreach ($responses as $response) {
			if (is_array($response) === true) {
				$sum += self::responseScore(response: $response);
			}
		}

		return $sum;
	}//end sumOf()

	/**
	 * One response's score: its numeric `autoScore`, else its numeric `manualScore`, else 0.
	 *
	 * @param array<string,mixed> $response The response.
	 *
	 * @return float
	 */
	private static function responseScore(array $response): float {
		$auto = ($response['autoScore'] ?? null);
		if (is_numeric($auto) === true) {
			return (float)$auto;
		}

		$manual = ($response['manualScore'] ?? null);
		if (is_numeric($manual) === true) {
			return (float)$manual;
		}

		return 0.0;
	}//end responseScore()
}//end class
