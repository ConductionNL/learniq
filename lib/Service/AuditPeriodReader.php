<?php

/**
 * Learniq Audit Period Reader
 *
 * Reads the OpenRegister audit entries written within an export period.
 * AuditTrailMapper::findAll() has no range filter, so the period is applied
 * here over pages of the trail sorted by `created` (#302).
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
 * @spec openspec/specs/compliance-audit/spec.md#requirement-export-audit-ready-zip-per-regulation-and-date-range
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\OpenRegister\Db\AuditTrailMapper;

/**
 * Reads the audit entries of one export period, oldest first.
 *
 * @spec openspec/specs/compliance-audit/spec.md#requirement-export-audit-ready-zip-per-regulation-and-date-range
 */
class AuditPeriodReader {
	/**
	 * Audit entries read from the mapper per page.
	 *
	 * @var int
	 */
	private const PAGE_SIZE = 500;

	/**
	 * Constructor.
	 *
	 * @param AuditTrailMapper $auditTrailMapper OR audit-trail database mapper.
	 */
	public function __construct(
		private readonly AuditTrailMapper $auditTrailMapper,
	) {

	}//end __construct()

	/**
	 * Read the audit entries created within the period, oldest first.
	 *
	 * AuditTrailMapper::findAll() has no range filter: a comma-joined
	 * `created` value becomes `created IN (from, to)`, an exact match on two
	 * timestamps, so the pack came back empty (#302). The trail is read in
	 * pages sorted by `created` ascending (the mapper's only direction) and the
	 * period is applied here, stopping at the first entry after it.
	 *
	 * @param string $dateFrom ISO-8601 lower bound (inclusive); a bare date starts at 00:00.
	 * @param string $dateTo ISO-8601 upper bound (inclusive); a bare date runs to the end of that day.
	 *
	 * @return array<int,array<string,mixed>> Serialised entries within the period; none when a bound is not a date.
	 *
	 * @spec openspec/specs/compliance-audit/spec.md#requirement-export-audit-ready-zip-per-regulation-and-date-range
	 */
	public function entries(string $dateFrom, string $dateTo): array {
		$from = $this->parseBound(value: $dateFrom, endOfDay: false);
		$to   = $this->parseBound(value: $dateTo, endOfDay: true);
		if ($from === null || $to === null) {
			return [];
		}

		return $this->entriesBetween(from: $from, to: $to);
	}//end entries()

	/**
	 * The trail's entries from `$from` to `$to` inclusive, oldest first; stops
	 * reading at the first entry after the period.
	 *
	 * @param DateTimeImmutable $from The lower bound.
	 * @param DateTimeImmutable $to   The upper bound.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function entriesBetween(DateTimeImmutable $from, DateTimeImmutable $to): array {
		$rows = [];
		foreach ($this->trail() as $row) {
			$created = $this->createdAt(row: $row);
			if ($created === null || $created < $from) {
				continue;
			}

			if ($created > $to) {
				return $rows;
			}

			$rows[] = $row;
		}

		return $rows;
	}//end entriesBetween()

	/**
	 * The whole audit trail as serialised entries, oldest first, read one page
	 * at a time as it is consumed; the first short page is the last.
	 *
	 * @return \Generator<int,array<string,mixed>>
	 */
	private function trail(): \Generator {
		$offset = 0;
		do {
			$page    = $this->page(offset: $offset);
			$offset += self::PAGE_SIZE;
			yield from $page;
		} while (count($page) === self::PAGE_SIZE);
	}//end trail()

	/**
	 * One page of the audit trail, sorted by `created` ascending, serialised.
	 *
	 * @param int $offset The first entry of the page.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function page(int $offset): array {
		return array_map(
			static fn ($entry): array => (array)$entry->jsonSerialize(),
			$this->auditTrailMapper->findAll(
				limit: self::PAGE_SIZE,
				offset: $offset,
				sort: ['created' => 'ASC']
			)
		);
	}//end page()

	/**
	 * Parse one bound of the export period.
	 *
	 * The export refuses a bound that does not parse before the pack is
	 * built (AuditPackBuilder::isPeriod()), with this same function.
	 *
	 * @param string $value ISO-8601 date or date-time.
	 * @param bool $endOfDay Whether a bare date means the last second of that day.
	 *
	 * @return DateTimeImmutable|null The bound, or null when the value is not a date.
	 *
	 * @spec openspec/specs/compliance-audit/spec.md#requirement-export-audit-ready-zip-per-regulation-and-date-range
	 */
	public function parseBound(string $value, bool $endOfDay): ?DateTimeImmutable {
		$bound = date_create_immutable($value, new DateTimeZone('UTC'));
		if ($bound === false) {
			return null;
		}

		if ($endOfDay === true && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
			$bound = $bound->setTime(23, 59, 59);
		}

		return $bound;
	}//end parseBound()

	/**
	 * The moment a serialised audit entry was created.
	 *
	 * @param array<string,mixed> $row Serialised audit entry.
	 *
	 * @return DateTimeImmutable|null The creation time, or null when absent or unreadable.
	 *
	 * @spec openspec/specs/compliance-audit/spec.md#requirement-export-audit-ready-zip-per-regulation-and-date-range
	 */
	private function createdAt(array $row): ?DateTimeImmutable {
		$created = self::createdText(value: ($row['created'] ?? null));
		if ($created === '') {
			return null;
		}

		$moment = date_create_immutable($created, new DateTimeZone('UTC'));
		if ($moment === false) {
			return null;
		}

		return $moment;
	}//end createdAt()

	/**
	 * A creation time as text: a date as ISO 8601, a string as it is, anything else as ''.
	 *
	 * @param mixed $value The serialised `created` value.
	 *
	 * @return string
	 */
	private static function createdText(mixed $value): string {
		if ($value instanceof DateTimeInterface) {
			return $value->format(DateTimeInterface::ATOM);
		}

		if (is_string($value) === false) {
			return '';
		}

		return $value;
	}//end createdText()
}//end class
