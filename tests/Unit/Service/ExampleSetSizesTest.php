<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\ExampleSetSizes;
use OCA\Learniq\Service\SeedProfileService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Each loaded example set carries how many objects go to the trash when it is removed.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-an-administrator-removes-a-loaded-example-set-on-the-admin-page
 */
class ExampleSetSizesTest extends TestCase {

	/**
	 * A curated set gets its object count; the generated set has none and stays without.
	 *
	 * @return void
	 */
	public function testACuratedSetGetsItsObjectCountTheGeneratedSetNone(): void {
		$seedProfiles = $this->createMock(originalClassName: SeedProfileService::class);
		$seedProfiles->method('uuidsFor')->willReturnCallback(
			static function (string $profileId): array {
				if ($profileId === 'generated') {
					throw new RuntimeException(message: 'The generated set has no fixed uuids.');
				}

				return ['a', 'b', 'c'];
			}
		);

		$sets = (new ExampleSetSizes(seedProfiles: $seedProfiles))->withSizes(
			sets: [['id' => 'corporate', 'label' => 'Company'], ['id' => 'generated', 'label' => 'Generated']]
		);

		self::assertSame(
			expected: [['id' => 'corporate', 'label' => 'Company', 'objects' => 3], ['id' => 'generated', 'label' => 'Generated']],
			actual: $sets
		);
	}//end testACuratedSetGetsItsObjectCountTheGeneratedSetNone()
}//end class
