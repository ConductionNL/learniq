<?php

/**
 * Learniq running regulation exemptions.
 *
 * Which mandatory training rules a person is excused from on a given day. Kept
 * out of ComplianceRollupService, which is at phpmd's class complexity limit.
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
 * @spec openspec/changes/compliance-exemption-record/specs/compliance-exemptions/spec.md#requirement-exemptions-in-the-roll-up
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use DateTimeImmutable;

/**
 * The regulation exemptions that run on a given day.
 *
 * @spec openspec/changes/compliance-exemption-record/specs/compliance-exemptions/spec.md#requirement-exemptions-in-the-roll-up
 */
final class RunningExemptions {

	/**
	 * The regulations a learner holds a granted exemption from on this day:
	 * started (no start date, or on or before today) and not ended (its last
	 * day is today or later).
	 *
	 * @param array<int,string>              $keys       The learner's keys.
	 * @param array<int,array<string,mixed>> $exemptions Granted regulation exemptions.
	 * @param DateTimeImmutable              $now        Evaluation instant.
	 *
	 * @return array<string,true> Regulation slugs.
	 *
	 * @spec openspec/changes/compliance-exemption-record/specs/compliance-exemptions/spec.md#scenario-the-exemption-lapses
	 */
	public function regulationsFor(array $keys, array $exemptions, DateTimeImmutable $now): array {
		$today = $now->format('Y-m-d');
		$slugs = [];
		foreach ($exemptions as $exemption) {
			$learner = (string)($exemption['learnerId'] ?? '');
			$from    = substr((string)($exemption['validFrom'] ?? ''), 0, 10);
			$until   = substr((string)($exemption['validUntil'] ?? ''), 0, 10);
			if (($exemption['lifecycle'] ?? '') !== 'granted' || $learner === '' || in_array($learner, $keys, true) === false
				|| $until === '' || $until < $today || ($from !== '' && $from > $today)
			) {
				continue;
			}

			$slugs[(string)($exemption['regulationSlug'] ?? '')] = true;
		}

		return $slugs;
	}//end regulationsFor()
}//end class
