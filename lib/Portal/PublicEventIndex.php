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

		$item['category']      = self::categoryOf(kind: (string)($event['kind'] ?? ''));
		$item['categoryLabel'] = $this->categoryWord(category: $item['category']);

		$summary = trim((string)($event['description'] ?? ''));
		if ($summary !== '') {
			$item['summary'] = $summary;
		}

		return $item;
	}//end item()

	/**
	 * The calendar category of a kind of day: what a calendar block on the
	 * website lets its editor pick. A kind the school did not mark as a
	 * holiday, a day off or a parent evening is a school activity.
	 *
	 * @param string $kind The school event's `kind`.
	 *
	 * @return string `holiday`, `day-off`, `parent-evening` or `activity`.
	 *
	 * @spec openspec/changes/portal-public-index/specs/portal-contribution/spec.md#requirement-every-school-day-in-the-index-has-a-category
	 */
	public static function categoryOf(string $kind): string {
		return match ($kind) {
			'holiday' => 'holiday',
			'study-day', 'day-off' => 'day-off',
			'parent-evening' => 'parent-evening',
			default => 'activity',
		};
	}//end categoryOf()

	/**
	 * The category in the portal's language.
	 *
	 * @param string $category The category.
	 *
	 * @return string
	 */
	private function categoryWord(string $category): string {
		return match ($category) {
			'holiday' => $this->reads->word(text: 'Holiday'),
			'day-off' => $this->reads->word(text: 'Study day or day off'),
			'parent-evening' => $this->reads->word(text: 'Parent evening'),
			default => $this->reads->word(text: 'School activity'),
		};
	}//end categoryWord()
}//end class
