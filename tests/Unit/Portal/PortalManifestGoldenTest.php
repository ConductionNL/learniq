<?php

/**
 * Golden test of the portal manifest learniq declares for every audience.
 *
 * Each audience's manifest is compared to a recorded JSON copy, key order
 * included, so a change to any collection, action or page shows up here.
 * Set RECORD_GOLDEN=1 to write the copies.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Portal;

use OCA\Learniq\Portal\PortalContributionProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The manifest of every audience, against its recorded copy.
 */
class PortalManifestGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/golden/PortalManifests.json';

	/**
	 * Every audience the provider serves.
	 *
	 * @return array<string, array{string}>
	 */
	public static function audiences(): array {
		$audiences = [];
		foreach ((new PortalContributionProvider())->getAudiences() as $audience) {
			$audiences[$audience] = [$audience];
		}

		return $audiences;
	}//end audiences()

	/**
	 * The audience's manifest equals its recorded copy, key order included.
	 *
	 * @param string $audience The audience.
	 *
	 * @return void
	 */
	#[DataProvider('audiences')]
	public function testTheManifestIsTheRecordedOne(string $audience): void {
		$manifest = (new PortalContributionProvider())->getContribution(['audience' => $audience]);
		$encoded  = json_encode($manifest, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));

		$golden = (is_file(self::GOLDEN) === true) ? json_decode((string) file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR) : [];
		if (getenv('RECORD_GOLDEN') === '1') {
			$golden[$audience] = $encoded;
			file_put_contents(self::GOLDEN, json_encode($golden, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))."\n");
		}

		$this->assertArrayHasKey($audience, $golden);
		$this->assertSame($golden[$audience], $encoded);
	}//end testTheManifestIsTheRecordedOne()
}//end class
