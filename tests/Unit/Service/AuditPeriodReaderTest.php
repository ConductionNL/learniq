<?php

/**
 * Learniq AuditPeriodReader characterization test.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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

namespace OCA\Learniq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Learniq\Service\AuditPeriodReader;
use OCA\OpenRegister\Db\AuditTrailMapper;
use PHPUnit\Framework\TestCase;

/**
 * Pins how the audit trail of one export period is read, page by page.
 */
class AuditPeriodReaderTest extends TestCase {

	/**
	 * The offsets the mapper was asked for, in order.
	 *
	 * @var array<int, int>
	 */
	private array $offsets = [];

	/**
	 * A reader over a trail served in pages of 500, sorted by `created`.
	 *
	 * @param array<int, array<string, mixed>> $trail The serialised entries.
	 *
	 * @return AuditPeriodReader
	 */
	private function reader(array $trail): AuditPeriodReader {
		$this->offsets = [];
		$mapper = $this->createMock(AuditTrailMapper::class);
		$mapper->method('findAll')->willReturnCallback(
			function (?int $limit = null, ?int $offset = null, ?array $filters = null, ?array $sort = null) use ($trail): array {
				$this->offsets[] = $offset;
				self::assertSame(500, $limit);
				self::assertSame(['created' => 'ASC'], $sort);
				return array_map(
					static fn (array $row): object => new class ($row) {
						public function __construct(private readonly array $row) {
						}

						public function jsonSerialize(): array {
							return $this->row;
						}
					},
					array_slice($trail, (int)$offset, (int)$limit)
				);
			}
		);

		return new AuditPeriodReader(auditTrailMapper: $mapper);
	}//end reader()

	/**
	 * Only entries inside the period come back, oldest first; reading stops at
	 * the first entry after it, and entries without a readable time are skipped.
	 *
	 * @return void
	 */
	public function testOnlyThePeriodIsReadAndReadingStopsAfterIt(): void {
		$trail = [];
		for ($i = 0; $i < 500; $i++) {
			$trail[] = ['id' => 'early-' . $i, 'created' => '2026-09-30T23:59:59+00:00'];
		}

		$trail[] = ['id' => 'no-time'];
		$trail[] = ['id' => 'empty-time', 'created' => ''];
		$trail[] = ['id' => 'bad-time', 'created' => 'yesterday-ish'];
		$trail[] = ['id' => 'not-a-time', 'created' => 42];
		$trail[] = ['id' => 'first', 'created' => '2026-10-01T00:00:00+00:00'];
		$trail[] = ['id' => 'object', 'created' => new DateTimeImmutable('2026-10-02T09:00:00+02:00')];
		$trail[] = ['id' => 'last', 'created' => '2026-10-03T23:59:59+00:00'];
		$trail[] = ['id' => 'after', 'created' => '2026-10-04T00:00:00+00:00'];
		for ($i = 0; $i < 600; $i++) {
			$trail[] = ['id' => 'later-' . $i, 'created' => '2026-10-05T00:00:00+00:00'];
		}

		$rows = $this->reader(trail: $trail)->entries(dateFrom: '2026-10-01', dateTo: '2026-10-03');

		self::assertSame(['first', 'object', 'last'], array_column($rows, 'id'));
		self::assertSame([0, 500], $this->offsets);
	}//end testOnlyThePeriodIsReadAndReadingStopsAfterIt()

	/**
	 * Every page is read while pages come back full; a short page is the last.
	 *
	 * @return void
	 */
	public function testPagesAreReadUntilAShortOne(): void {
		$trail = [];
		for ($i = 0; $i < 1000; $i++) {
			$trail[] = ['id' => 'e-' . $i, 'created' => '2026-10-01T10:00:00+00:00'];
		}

		$rows = $this->reader(trail: $trail)->entries(dateFrom: '2026-10-01T09:00:00Z', dateTo: '2026-10-01T10:00:00Z');
		self::assertCount(1000, $rows);
		self::assertSame([0, 500, 1000], $this->offsets);

		$rows = $this->reader(trail: array_slice($trail, 0, 3))->entries(dateFrom: '2026-10-01T10:00:01Z', dateTo: '2026-10-02');
		self::assertSame([], $rows);
		self::assertSame([0], $this->offsets);
	}//end testPagesAreReadUntilAShortOne()

	/**
	 * A bound that is not a date reads nothing at all.
	 *
	 * @return void
	 */
	public function testABoundThatIsNotADateReadsNothing(): void {
		$reader = $this->reader(trail: [['id' => 'x', 'created' => '2026-10-01T10:00:00+00:00']]);

		self::assertSame([], $reader->entries(dateFrom: 'whenever', dateTo: '2026-10-02'));
		self::assertSame([], $reader->entries(dateFrom: '2026-10-01', dateTo: 'whenever'));
		self::assertSame([], $this->offsets);
	}//end testABoundThatIsNotADateReadsNothing()
}//end class
