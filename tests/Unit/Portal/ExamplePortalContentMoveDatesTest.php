<?php

/**
 * Learniq ExamplePortalContent date-move tests
 *
 * Pins how a reload in another week moves the dates of the pages and news
 * the portal already has: a page found by its declared route on this
 * portal, a news item by its title as the previous load wrote it, a row
 * without an id or without a date that moves left unwritten, and nothing
 * read at all on a first load or a reload in the same week.
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

use OCA\Learniq\Portal\ExamplePortalContent;
use OCA\Learniq\Service\DemoDates;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * ExamplePortalContent::moveDates().
 */
#[CoversClass(ExamplePortalContent::class)]
#[UsesClass(DemoDates::class)]
class ExamplePortalContentMoveDatesTest extends TestCase {

	/**
	 * The declaration as shipped: one page route and one news title.
	 *
	 * @return array<string, mixed>
	 */
	private static function declaration(): array {
		return [
			'portal' => ['slug' => 'wilgenboom'],
			'pages'  => [['route' => '/agenda', 'date' => '2026-10-05']],
			'news'   => [['title' => 'Ouderavond 5 oktober']],
		];
	}//end declaration()

	/**
	 * A reload a week later moves the stored rows of this portal and writes each once.
	 *
	 * @return void
	 */
	public function testAReloadMovesTheStoredPagesAndNewsOfThisPortal(): void {
		$stored = [
			'page'     => [
				['@self' => ['id' => 'pg-1'], 'portal' => 'wilgenboom', 'route' => '/agenda', 'date' => '2026-10-12'],
				['@self' => ['id' => 'pg-2'], 'portal' => 'wilgenboom', 'route' => '/other', 'date' => '2026-10-12'],
				['@self' => ['id' => 'pg-3'], 'portal' => 'elsewhere', 'route' => '/agenda', 'date' => '2026-10-12'],
				['@self' => ['id' => 'pg-4'], 'portal' => 'p-uuid', 'route' => '/agenda', 'title' => 'Agenda'],
				['portal' => 'wilgenboom', 'route' => '/agenda', 'date' => '2026-10-12'],
			],
			'newsItem' => [
				['id' => 'n-1', 'title' => 'Ouderavond 12 oktober', 'publishedAt' => '2026-10-12'],
				['id' => 'n-2', 'title' => 'Ouderavond 5 oktober', 'publishedAt' => '2026-10-05'],
			],
		];

		$saves = [];
		$store = $this->createMock(ObjectService::class);
		$store->method('findAll')->willReturnCallback(
			static fn (array $config): array => ($stored[$config['filters']['schema']] ?? [])
		);
		$store->method('saveObject')->willReturnCallback(
			static function (array $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true) use (&$saves): ObjectEntity {
				$saves[] = [$schema, $uuid, $object, $register, $_rbac, $_multitenancy];
				return OrEntityFactory::make(['id' => $uuid], (string)$schema, 'portaliq', $uuid);
			}
		);

		$written = (new ExamplePortalContent($store))->moveDates(
			declaration: self::declaration(),
			refs: ['wilgenboom', 'p-uuid'],
			previous: 7,
			days: 7
		);

		self::assertSame(2, $written);
		self::assertSame(
			[
				['page', 'pg-1', ['portal' => 'wilgenboom', 'route' => '/agenda', 'date' => '2026-10-19'], 'portaliq', false, false],
				['newsItem', 'n-1', ['id' => 'n-1', 'title' => 'Ouderavond 19 oktober', 'publishedAt' => '2026-10-19'], 'portaliq', false, false],
			],
			$saves
		);
	}//end testAReloadMovesTheStoredPagesAndNewsOfThisPortal()

	/**
	 * A first load (no previous offset) and a reload in the same week read and write nothing.
	 *
	 * @return void
	 */
	public function testAFirstLoadOrTheSameWeekTouchesNothing(): void {
		$store = $this->createMock(ObjectService::class);
		$store->expects(self::never())->method('findAll');
		$store->expects(self::never())->method('saveObject');
		$content = new ExamplePortalContent($store);

		self::assertSame(0, $content->moveDates(declaration: self::declaration(), refs: ['wilgenboom'], previous: null, days: 7));
		self::assertSame(0, $content->moveDates(declaration: self::declaration(), refs: ['wilgenboom'], previous: 7, days: 0));
	}//end testAFirstLoadOrTheSameWeekTouchesNothing()

	/**
	 * A declaration without pages or news finds no row to move.
	 *
	 * @return void
	 */
	public function testADeclarationWithoutPagesOrNewsMovesNothing(): void {
		$store = $this->createMock(ObjectService::class);
		$store->method('findAll')->willReturn(
			[
				['@self' => ['id' => 'pg-1'], 'portal' => 'wilgenboom', 'route' => '/agenda', 'date' => '2026-10-12'],
				['id' => 'n-1', 'title' => 'Ouderavond 12 oktober'],
			]
		);
		$store->expects(self::never())->method('saveObject');

		$written = (new ExamplePortalContent($store))->moveDates(
			declaration: ['portal' => ['slug' => 'wilgenboom']],
			refs: ['wilgenboom'],
			previous: 7,
			days: 7
		);

		self::assertSame(0, $written);
	}//end testADeclarationWithoutPagesOrNewsMovesNothing()
}//end class
