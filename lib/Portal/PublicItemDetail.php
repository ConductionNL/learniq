<?php

/**
 * Learniq PublicItemDetail
 *
 * The page of one public course or programme (portaliq
 * public-detail-page-for-a-provider-item): portaliq asks the provider's
 * `getPublicDetail(portal, id)` with the id the item carries in the public
 * index, and shows the answer on the item's own page. The detail is made from
 * the index item itself, so it says nothing the index does not already show a
 * visitor: the title, its kind, its dates, its facets as facts and its
 * description.
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
 * @spec openspec/changes/portal-public-index/specs/portal-contribution/spec.md#requirement-a-public-course-or-programme-has-a-page-of-its-own
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

/**
 * Turns a public index item into the detail portaliq shows on its page.
 *
 * @spec openspec/changes/portal-public-index/specs/portal-contribution/spec.md#requirement-a-public-course-or-programme-has-a-page-of-its-own
 */
class PublicItemDetail {

	/**
	 * The item types that have a page of their own.
	 *
	 * @var array<int, string>
	 */
	public const TYPES = ['course', 'programme'];

	/**
	 * Constructor.
	 *
	 * @param PublicIndexReads $reads The shared words and days.
	 */
	public function __construct(
		private readonly PublicIndexReads $reads,
	) {
	}//end __construct()

	/**
	 * The detail of one index item, or null when its type has no page.
	 *
	 * @param array<string, mixed> $item The public index item.
	 *
	 * @return array<string, mixed>|null `{title, facts, sections, secondaryLink?}`.
	 *
	 * @spec openspec/changes/portal-public-index/specs/portal-contribution/spec.md#requirement-a-public-course-or-programme-has-a-page-of-its-own
	 */
	public function forItem(array $item): ?array {
		if (in_array(($item['type'] ?? null), self::TYPES, true) === false) {
			return null;
		}

		$detail = [
			'title'    => (string)($item['title'] ?? ''),
			'facts'    => $this->facts(item: $item),
			'sections' => [],
		];

		$summary = trim((string)($item['summary'] ?? ''));
		if ($summary !== '') {
			$detail['sections'][] = ['heading' => $this->reads->word(text: 'Description'), 'markdown' => $summary];
		}

		// Back to the list the item came from, when the portal declares one.
		$href = (string)($item['href'] ?? '');
		if ($href !== '') {
			$detail['secondaryLink'] = ['label' => (string)($item['kind'] ?? ''), 'href' => $href];
		}

		return $detail;
	}//end forItem()

	/**
	 * The item's kind, its dates and its facets, as `{label, value}` rows.
	 *
	 * @param array<string, mixed> $item The public index item.
	 *
	 * @return array<int, array{label: string, value: string}>
	 */
	private function facts(array $item): array {
		$facts = [];
		$kind  = (string)($item['kind'] ?? '');
		if ($kind !== '') {
			$facts[] = ['label' => $this->reads->word(text: 'Kind'), 'value' => $kind];
		}

		$dates = $this->dates(item: $item);
		if ($dates !== '') {
			$facts[] = ['label' => $this->reads->word(text: 'Date'), 'value' => $dates];
		}

		foreach ((array)($item['facets'] ?? []) as $label => $values) {
			$value = implode(', ', array_filter((array)$values, static fn ($v): bool => is_string($v) === true && $v !== ''));
			if (is_string($label) === true && $label !== '' && $value !== '') {
				$facts[] = ['label' => $label, 'value' => $value];
			}
		}

		return $facts;
	}//end facts()

	/**
	 * "8 okt", or "3 nov – 10 nov" for a run of several days; '' without a date.
	 *
	 * @param array<string, mixed> $item The public index item.
	 *
	 * @return string
	 */
	private function dates(array $item): string {
		$start = (string)($item['date'] ?? '');
		if ($start === '') {
			return '';
		}

		$end  = (string)($item['endDate'] ?? '');
		$line = $this->reads->shortDay(day: $start);
		if ($end !== '' && $end !== $start) {
			$line .= ' – ' . $this->reads->shortDay(day: $end);
		}

		return $line;
	}//end dates()
}//end class
