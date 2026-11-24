<?php

/**
 * Learniq HourReturnAction
 *
 * The button under a returned week's notice (board esdoornveen/Berichten,
 * "Uren aanpassen"): a label and a path inside the portal that opens her
 * hours page with the returned week chosen, where she writes the day again.
 * A link, not a form inside the message (D-5). Portaliq draws it from the
 * inbox collection's `actionField` and keeps only a path on its own site.
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
 * @spec openspec/changes/bpv-hours-per-day/specs/bpv/spec.md#requirement-a-returned-week-names-the-day-and-the-question
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

/**
 * Words the link under a returned week's notice.
 *
 * @spec openspec/changes/bpv-hours-per-day/specs/bpv/spec.md#requirement-a-returned-week-names-the-day-and-the-question
 */
final class HourReturnAction {

	/**
	 * The label, in the language of the portal's other server-written notices
	 * (HourReturnSubject).
	 */
	public const LABEL = 'Uren aanpassen';

	/**
	 * The student's hours page: portaliq serves a contribution page at
	 * `/mijn/<app>/<page>/<id>` with that row chosen.
	 */
	public const PAGE_PATH = '/mijn/learniq/studentHourWeeks/';

	/**
	 * The action for a returned week, or null when the week has no usable id.
	 *
	 * @param string $weekId The returned week's uuid.
	 *
	 * @return array{label: string, href: string}|null
	 *
	 * @spec openspec/changes/bpv-hours-per-day/specs/bpv/spec.md#requirement-a-returned-week-names-the-day-and-the-question
	 */
	public static function of(string $weekId): ?array {
		if (preg_match('/^[A-Za-z0-9-]+\z/', $weekId) !== 1) {
			return null;
		}

		return ['label' => self::LABEL, 'href' => self::PAGE_PATH . $weekId];
	}//end of()
}//end class
