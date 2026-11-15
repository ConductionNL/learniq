<?php

/**
 * Register test for the bpv-visit schema: its lifecycle and the visit report's link to it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/bpv-coach-today-and-placement-board/tasks.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Register;

use PHPUnit\Framework\TestCase;

/**
 * A visit is proposed by the coach and accepted or answered by the company,
 * and a visit report can say which visit it records.
 */
final class BpvVisitSchemaTest extends TestCase {

	/**
	 * The register's schemas.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function schemas(): array {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'), true);

		return $register['components']['schemas'];
	}

	public function testAProposalIsAcceptedOrAnsweredBackToUnplanned(): void {
		$lifecycle = self::schemas()['BpvVisit']['x-openregister-lifecycle'];

		$this->assertSame('unplanned', $lifecycle['initial']);
		$this->assertSame(['from' => ['unplanned', 'proposed'], 'to' => 'proposed'], $lifecycle['transitions']['propose']);
		$this->assertSame(['from' => ['proposed'], 'to' => 'planned'], $lifecycle['transitions']['accept']);
		$this->assertSame(['from' => ['proposed'], 'to' => 'unplanned'], $lifecycle['transitions']['answer']);
		$this->assertSame(['from' => ['planned'], 'to' => 'done'], $lifecycle['transitions']['complete']);
		$this->assertNotContains('done', $lifecycle['transitions']['cancel']['from']);
	}

	public function testEveryLifecycleStateIsAnAllowedValue(): void {
		$schema = self::schemas()['BpvVisit'];
		$states = [$schema['x-openregister-lifecycle']['initial']];
		foreach ($schema['x-openregister-lifecycle']['transitions'] as $transition) {
			$states = array_merge($states, $transition['from'], [$transition['to']]);
		}

		$this->assertSame([], array_values(array_diff(array_unique($states), $schema['properties']['lifecycle']['enum'])));
	}

	public function testAVisitBelongsToAPlacementAndAReportCanNameItsVisit(): void {
		$schemas = self::schemas();

		$this->assertSame('BpvPlacement', $schemas['BpvVisit']['properties']['bpvPlacementId']['$ref']);
		$this->assertContains('bpvPlacementId', $schemas['BpvVisit']['required']);
		$this->assertSame('BpvVisit', $schemas['BpvVisitReport']['properties']['bpvVisitId']['$ref']);
		$this->assertNotContains('bpvVisitId', $schemas['BpvVisitReport']['required']);
	}

	public function testOnlyStaffAndTheVisitsOwnCoachReadIt(): void {
		$read = self::schemas()['BpvVisit']['authorization']['read'];

		$this->assertContains(['group' => 'authenticated', 'match' => ['schoolCoachId' => '$userId']], $read);
		foreach ($read as $rule) {
			$this->assertNotSame('authenticated', is_array($rule) ? ($rule['group'] ?? null) . (empty($rule['match']) ? '' : '+match') : $rule);
		}
	}
}
