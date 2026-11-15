<?php

/**
 * Learniq PublicIndexReads tests
 *
 * Pins the shared reads and words of a portal's public index: one read of
 * a schema as the system, entity and array rows alike, other values and
 * rows outside the namespace dropped, a failed read logged and answered
 * empty; a row's id; words through the portal's language or plain; a day
 * as "22 okt" and a month as "Oktober 2026".
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Portal
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
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Portal;

use JsonSerializable;
use OCA\Learniq\Portal\PublicIndexReads;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * PublicIndexReads.
 */
#[CoversClass(PublicIndexReads::class)]
class PublicIndexReadsTest extends TestCase {

	/**
	 * A row that OpenRegister answers as an entity.
	 *
	 * @param array<string, mixed> $data The row.
	 *
	 * @return JsonSerializable
	 */
	private static function entity(array $data): JsonSerializable {
		return new class($data) implements JsonSerializable {

			/**
			 * Constructor.
			 *
			 * @param array<string, mixed> $data The row.
			 */
			public function __construct(private readonly array $data) {
			}//end __construct()

			/**
			 * The row.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return $this->data;
			}//end jsonSerialize()
		};
	}//end entity()

	/**
	 * The rows of a schema in a namespace, read once as the system with the extra filters.
	 *
	 * @return void
	 */
	public function testTheRowsOfASchemaInTheNamespace(): void {
		$reads = [];
		$store = $this->createMock(ObjectService::class);
		$store->method('findAll')->willReturnCallback(
			static function (array $config = [], bool $_rbac = true, bool $_multitenancy = true) use (&$reads): array {
				$reads[] = [$config, $_rbac, $_multitenancy];
				return [
					['id' => 'aaaa-1', 'title' => 'A'],
					self::entity(['uuid' => 'aaaa-2', 'title' => 'B']),
					'not a row',
					['@self' => ['id' => 'aaaa-3'], 'title' => 'C'],
					['id' => 'bbbb-1', 'title' => 'D'],
				];
			}
		);
		$reader = new PublicIndexReads($store, $this->createMock(LoggerInterface::class));

		self::assertSame(['A', 'B', 'C'], array_column($reader->rows(schema: 'course', namespace: 'aaaa', filters: ['public' => true]), 'title'));
		self::assertSame(['A', 'B', 'C', 'D'], array_column($reader->rows(schema: 'course', namespace: ''), 'title'));
		self::assertSame(
			[
				[['filters' => ['register' => 'learniq', 'schema' => 'course', 'public' => true], 'limit' => 1000], false, false],
				[['filters' => ['register' => 'learniq', 'schema' => 'course'], 'limit' => 1000], false, false],
			],
			$reads
		);
	}//end testTheRowsOfASchemaInTheNamespace()

	/**
	 * A read that fails is logged with its schema and answers no rows.
	 *
	 * @return void
	 */
	public function testAFailedReadIsLoggedAndAnswersNothing(): void {
		$store = $this->createMock(ObjectService::class);
		$store->method('findAll')->willThrowException(new RuntimeException('register gone'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with(
			'Learniq: public index read failed',
			['schema' => 'event', 'reason' => 'register gone']
		);

		self::assertSame([], (new PublicIndexReads($store, $logger))->rows(schema: 'event', namespace: ''));
	}//end testAFailedReadIsLoggedAndAnswersNothing()

	/**
	 * A row's id: `id` first, then `uuid`, then the `@self` envelope's, else empty.
	 *
	 * @return void
	 */
	public function testTheIdOfARow(): void {
		$reader = new PublicIndexReads($this->createMock(ObjectService::class), $this->createMock(LoggerInterface::class));

		self::assertSame('i', $reader->idOf(row: ['id' => 'i', 'uuid' => 'u', '@self' => ['id' => 's']]));
		self::assertSame('u', $reader->idOf(row: ['uuid' => 'u', '@self' => ['id' => 's']]));
		self::assertSame('s', $reader->idOf(row: ['@self' => ['id' => 's']]));
		self::assertSame('', $reader->idOf(row: ['title' => 'x']));
	}//end testTheIdOfARow()

	/**
	 * A word goes through the portal's language when there is one, else its placeholders are filled plainly.
	 *
	 * @return void
	 */
	public function testAWordInThePortalsLanguageOrPlain(): void {
		$store  = $this->createMock(ObjectService::class);
		$logger = $this->createMock(LoggerInterface::class);

		self::assertSame('3 places', (new PublicIndexReads($store, $logger))->word(text: '%s places', args: [3]));

		$l10n = $this->createMock(IL10N::class);
		$l10n->expects(self::once())->method('t')->with('%s places', [3])->willReturn('3 plaatsen');
		self::assertSame('3 plaatsen', (new PublicIndexReads($store, $logger, $l10n))->word(text: '%s places', args: [3]));
	}//end testAWordInThePortalsLanguageOrPlain()

	/**
	 * A day reads as "22 okt" and a month as "Oktober 2026", the same on every server.
	 *
	 * @return void
	 */
	public function testADayAndAMonthInDutch(): void {
		$reader = new PublicIndexReads($this->createMock(ObjectService::class), $this->createMock(LoggerInterface::class));

		self::assertSame('22 okt', $reader->shortDay(day: '2026-10-22'));
		self::assertSame('5 jan', $reader->shortDay(day: '2027-01-05'));
		self::assertSame('Oktober 2026', $reader->month(day: '2026-10-22'));
		self::assertSame('December 2026', $reader->month(day: '2026-12-01'));
		self::assertSame(' 2026', $reader->month(day: '2026-13-01'));
	}//end testADayAndAMonthInDutch()
}//end class
