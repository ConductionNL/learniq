<?php

/**
 * Learniq PublicEventIndex
 *
 * The calendar part of a portal's public index (portal-public-index): the
 * school-wide days still to come from `school-event`. A day for some groups
 * only stays out: it names who is in which group.
 *
 * @category Portal
 * @package  OCA\Learniq\Portal
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
 * @spec openspec/changes/portal-public-index/specs/portal-contribution/spec.md#requirement-a-school-offers-its-portal-an-index-of-its-public-courses-programmes-and-school-days
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

/**
 * Builds the calendar items of the public index.
 *
 * @spec openspec/changes/portal-public-index/specs/portal-contribution/spec.md#requirement-a-school-offers-its-portal-an-index-of-its-public-courses-programmes-and-school-days
 */
class PublicEventIndex {

	/**
	 * Constructor.
	 *
	 * @param PublicIndexReads $reads The shared reads and words.
	 */
	public function __construct(
		private readonly PublicIndexReads $reads,
	) {
	}//end __construct()

	/**
	 * The calendar items.
	 *
	 * @param string $namespace The uuid namespace, '' for all.
	 * @param string $today     Today, `YYYY-MM-DD`.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/portal-public-index/specs/portal-contribution/spec.md#requirement-a-school-offers-its-portal-an-index-of-its-public-courses-programmes-and-school-days
	 */
	public function items(string $namespace, string $today): array {
		$out = [];
		foreach ($this->reads->rows(schema: 'school-event', namespace: $namespace) as $event) {
			if ($this->isPublicAndToCome(event: $event, today: $today) === true) {
				$out[] = $this->item(event: $event);
			}
		}

		return $out;
	}//end items()

	/**
	 * Whether a day is for the whole school, titled, and not over.
	 *
	 * @param array<string, mixed> $event The event.
	 * @param string               $today Today.
	 *
	 * @return bool
	 */
	private function isPublicAndToCome(array $event, string $today): bool {
		$start = (string)($event['startsAt'] ?? '');
		$last  = (string)($event['endsAt'] ?? '');
		if ($last === '') {
			$last = $start;
		}

		return ($event['audience'] ?? null) === 'school'
			&& $start !== ''
			&& substr($last, 0, 10) >= $today
			&& trim((string)($event['title'] ?? '')) !== '';
	}//end isPublicAndToCome()

	/**
	 * One calendar item.
	 *
	 * @param array<string, mixed> $event The event.
	 *
	 * @return array<string, mixed>
	 */
	private function item(array $event): array {
		$item = [
			'id'    => 'event:' . $this->reads->idOf(row: $event),
			'type'  => 'event',
			'kind'  => $this->reads->word(text: 'Calendar'),
			'title' => trim((string)$event['title']),
			'date'  => substr((string)$event['startsAt'], 0, 10),
		];

		$end = substr((string)($event['endsAt'] ?? ''), 0, 10);
		if ($end !== '' && $end !== $item['date']) {
			$item['endDate'] = $end;
		}

		$summary = trim((string)($event['description'] ?? ''));
		if ($summary !== '') {
			$item['summary'] = $summary;
		}

		return $item;
	}//end item()
}//end class
