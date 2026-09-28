<?php

/**
 * Tests for LegacyExchangeTranslator: the old exchange rows in integriq's
 * request shapes.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Repair
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
 * @spec openspec/specs/data-exchange/spec.md#requirement-existing-exchange-rows-are-moved-to-integriq-or-archived
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Repair;

use OCA\Learniq\Repair\LegacyExchangeTranslator;
use PHPUnit\Framework\TestCase;

/**
 * Slugs, rules, scope and owner reference of the old rows.
 */
class LegacyExchangeTranslatorTest extends TestCase {

	/**
	 * A seeded profile keeps the shared slug; a customised one gets its own.
	 *
	 * @return void
	 */
	public function testSlugs(): void {
		$translator = new LegacyExchangeTranslator();

		$this->assertSame('learniq-oso-export-dossier', $translator->slugOf(['name' => 'OSO transfer dossier']));
		$this->assertSame('learniq-custom-our-hr-sync-2026', $translator->slugOf(['name' => 'Our HR sync (2026)']));
	}//end testSlugs()

	/**
	 * Export rules read target from learniq, import rules the other way, and the BSN never moves.
	 *
	 * @return void
	 */
	public function testRules(): void {
		$translator = new LegacyExchangeTranslator();
		$fields = [
			['scholiqField' => 'familyName', 'targetField' => 'achternaam'],
			['scholiqField' => 'bsnEncrypted', 'targetField' => 'bsn'],
			['scholiqField' => '', 'targetField' => 'leeg'],
		];

		$this->assertSame(['achternaam' => 'familyName'], $translator->rulesOf(['direction' => 'export', 'fieldMappings' => $fields]));
		$this->assertSame(['familyName' => 'achternaam'], $translator->rulesOf(['direction' => 'import', 'fieldMappings' => $fields]));
	}//end testRules()

	/**
	 * The scope carries the tenant and a checked teldatum; the owner is the flag when there was one.
	 *
	 * @return void
	 */
	public function testScopeAndOwner(): void {
		$translator = new LegacyExchangeTranslator();

		$scope = $translator->scopeOf(['scope' => ['schema' => 'enrolment'], 'tenant_id' => 't1', 'requiresTeldatumCheck' => true, 'teldatumCheckDate' => '2026-10-01']);
		$this->assertSame(['schema' => 'enrolment', 'tenantId' => 't1', 'teldatumDate' => '2026-10-01'], $scope);
		$this->assertSame(['tenantId' => ''], $translator->scopeOf(['scope' => 'broken']));

		$this->assertSame('attendance-flag/flag-1', $translator->ownerRefOf(['originFlagId' => 'flag-1'], 'old-1'));
		$this->assertSame('data-exchange-job/old-1', $translator->ownerRefOf([], 'old-1'));
	}//end testScopeAndOwner()
}//end class
