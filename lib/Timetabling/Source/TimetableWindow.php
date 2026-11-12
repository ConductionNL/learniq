<?php

/**
 * Learniq Timetable Window
 *
 * The time window a planninq timetable query is limited to.
 *
 * @category Timetabling
 * @package  OCA\Learniq\Timetabling\Source
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
 * @spec openspec/specs/timetable-source/spec.md#requirement-the-planninq-source-reads-through-planninqs-query-event-req-002
 */

declare(strict_types=1);

namespace OCA\Learniq\Timetabling\Source;

/**
 * An ISO 8601 window start and end, either of which may be open.
 *
 * @spec openspec/specs/timetable-source/spec.md#requirement-the-planninq-source-reads-through-planninqs-query-event-req-002
 */
final class TimetableWindow {

	/**
	 * Constructor.
	 *
	 * @param string|null $from ISO 8601 window start, or null.
	 * @param string|null $to   ISO 8601 window end, or null.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly ?string $from,
		public readonly ?string $to,
	) {
	}//end __construct()

	/**
	 * The filled bounds as query criteria: `from` and `to`, each left out when null or empty.
	 *
	 * @return array<string,string>
	 *
	 * @spec openspec/specs/timetable-source/spec.md#requirement-the-planninq-source-reads-through-planninqs-query-event-req-002
	 */
	public function bounds(): array {
		return array_filter(['from' => $this->from, 'to' => $this->to], static fn (?string $bound): bool => $bound !== null && $bound !== '');
	}//end bounds()
}//end class
