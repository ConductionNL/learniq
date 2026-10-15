<?php

/**
 * Pupils and students are addressed with "je".
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
 * @spec openspec/changes/trainer-returns-hours-with-a-question/specs/portal-contribution/spec.md#requirement-a-pupil-is-addressed-with-je
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Portal;

use OCA\Learniq\Portal\PortalContributionProvider;
use PHPUnit\Framework\TestCase;

/**
 * No string of the pupil's manifest reads "u" or "uw" in Dutch.
 *
 * @spec openspec/changes/trainer-returns-hours-with-a-question/specs/portal-contribution/spec.md#requirement-a-pupil-is-addressed-with-je
 */
class PupilWordingTest extends TestCase {

	/**
	 * Every visible string of the pupil's manifest has "je" in Dutch, never
	 * "u" or "uw" (the esdoornveen check found "u" on a student's pages).
	 *
	 * @return void
	 */
	public function testThePupilReadsJe(): void {
		$dutch = json_decode((string)file_get_contents(__DIR__ . '/../../../l10n/nl.json'), true)['translations'];
		$formal = [];
		$walk = static function (mixed $value) use (&$walk, &$formal, $dutch): void {
			if (is_array($value) === true) {
				array_map($walk, $value);
				return;
			}

			if (is_string($value) === true && isset($dutch[$value]) === true && preg_match('/\b(u|uw)\b/iu', $dutch[$value]) === 1) {
				$formal[] = $value;
			}
		};
		$walk((new PortalContributionProvider())->getContribution(['audience' => 'student']));

		self::assertSame([], array_values(array_unique($formal)));
	}//end testThePupilReadsJe()
}//end class
