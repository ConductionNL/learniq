<?php

/**
 * Learniq ExamplePortalContent read tests
 *
 * Pins how the example content reads portaliq's rows: one read of the
 * schema as the system, entity and array rows alike, empty rows skipped,
 * and only the rows a match accepts.
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
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * The private row read of ExamplePortalContent.
 */
class ExamplePortalContentReadTest extends TestCase {

	/**
	 * The rows the read finds for a schema and a match, with every read recorded.
	 *
	 * @return void
	 */
	public function testTheRowsAReadFinds(): void {
		$reads = [];
		$store = $this->createMock(ObjectService::class);
		$store->method('findAll')->willReturnCallback(
			static function (array $config = [], bool $_rbac = true, bool $_multitenancy = true) use (&$reads): array {
				$reads[] = [$config, $_rbac, $_multitenancy];
				return [
					['title' => 'A', 'portal' => 'p-1'],
					[],
					'not a row',
					OrEntityFactory::make(['title' => 'B', 'portal' => 'p-2'], 'page', 'portaliq', 'pg-2'),
					['title' => 'C', 'portal' => 'p-1'],
				];
			}
		);
		$findAll = new \ReflectionMethod(ExamplePortalContent::class, 'findAll');
		$content = new ExamplePortalContent($store);

		$all = $findAll->invoke($content, 'page', null);
		self::assertSame(['A', 'B', 'C'], array_column($all, 'title'));
		self::assertSame(
			[['filters' => ['register' => 'portaliq', 'schema' => 'page'], 'limit' => 2000], false, false],
			$reads[0]
		);

		$matched = $findAll->invoke($content, 'page', static fn (array $row): bool => $row['portal'] === 'p-1');
		self::assertSame(['A', 'C'], array_column($matched, 'title'));
		self::assertSame([], $findAll->invoke($content, 'page', static fn (array $row): bool => false));
	}//end testTheRowsAReadFinds()
}//end class
