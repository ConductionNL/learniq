<?php

/**
 * Learniq School Advice ROD Timing
 *
 * When a SchoolAdvies's voorlopig advice is due for ROD: the advice level,
 * its date and the learner are recorded, the advice is still `voorlopig`, and
 * no voorlopig exchange was requested yet (DUO: within 14 days of giving it).
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
 * @spec openspec/specs/data-exchange/spec.md#requirement-the-voorlopig-school-advice-goes-to-rod-when-it-is-given
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

/**
 * Decides whether a voorlopig advice still has to go to ROD.
 *
 * @spec openspec/specs/data-exchange/spec.md#requirement-the-voorlopig-school-advice-goes-to-rod-when-it-is-given
 */
class SchoolAdviesRodTiming {

	/**
	 * Whether a SchoolAdvies's voorlopig advice is given and not yet sent to ROD.
	 *
	 * @param array<string, mixed> $advies The SchoolAdvies.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/data-exchange/spec.md#requirement-the-voorlopig-school-advice-goes-to-rod-when-it-is-given
	 */
	public function voorlopigDue(array $advies): bool {
		foreach (['voorlopigAdviesLevel', 'voorlopigAdviesDate', 'learnerId'] as $field) {
			if ((string)($advies[$field] ?? '') === '') {
				return false;
			}
		}

		return (string)($advies['lifecycle'] ?? 'voorlopig') === 'voorlopig'
			&& (string)($advies['voorlopigExchangeJobId'] ?? '') === '';
	}//end voorlopigDue()
}//end class
