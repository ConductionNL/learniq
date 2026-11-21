<?php

/**
 * A class planned in ability subgroups.
 *
 * Runs the scenario of the requirement "A coordinator plans a class in
 * ability subgroups with learners assigned to each" against the shipped
 * register and manifest: three subgroups of a group plan each validate with
 * their instruction level, goal, approach and learners; a subgroup without
 * its level, goal or approach is refused; the group plan's page lists its
 * own subgroups, and a subgroup's page shows its fields.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/capability-rows-say-what-they-do/specs/learning-plan/spec.md#requirement-a-coordinator-plans-a-class-in-ability-subgroups-with-learners-assigned-to-each
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * The GroupPlan and GroupPlanSubgroup schemas and their pages.
 *
 * @spec openspec/changes/capability-rows-say-what-they-do/specs/learning-plan/spec.md#requirement-a-coordinator-plans-a-class-in-ability-subgroups-with-learners-assigned-to-each
 */
class GroupPlanSubgroupRegisterTest extends TestCase {

	private const TENANT = '0f6c2a52-6d3f-4c1a-9b0a-5c1f6e2d7a10';

	private const GROUP_PLAN = '4a1b2c3d-5e6f-4a7b-8c9d-0e1f2a3b4c5d';

	/**
	 * The three subgroups of the scenario, as the coordinator adds them.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function subgroups(): array {
		return [
			'Verlengde instructie' => [
				'groupPlanId' => self::GROUP_PLAN,
				'name' => 'Verlengde instructie',
				'instructieniveau' => 'intensief',
				'differentiatedGoal' => 'Tafels van 6 tot 9 vlot en foutloos',
				'approach' => 'Elke dag tien minuten extra instructie aan de instructietafel',
				'learnerIds' => ['po-leerling-141', 'po-leerling-152'],
				'tenant_id' => self::TENANT,
			],
			'Basis' => [
				'groupPlanId' => self::GROUP_PLAN,
				'name' => 'Basis',
				'instructieniveau' => 'basis',
				'differentiatedGoal' => 'Vermenigvuldigen tot 100 met de methode',
				'approach' => 'Instructie volgens de methode, daarna zelfstandig werken',
				'learnerIds' => ['po-leerling-147', 'po-leerling-148', 'po-leerling-150'],
				'tenant_id' => self::TENANT,
			],
			'Plusgroep' => [
				'groupPlanId' => self::GROUP_PLAN,
				'name' => 'Plusgroep',
				'instructieniveau' => 'verdiept',
				'differentiatedGoal' => 'Vermenigvuldigen met grote getallen en schatten',
				'approach' => 'Korte instructie, daarna verrijkingstaken',
				'learnerIds' => ['po-leerling-144'],
				'tenant_id' => self::TENANT,
			],
		];
	}//end subgroups()

	/**
	 * A file of the app as decoded JSON.
	 *
	 * @param string $path The path from the app root.
	 *
	 * @return array<string, mixed>
	 */
	private static function json(string $path): array {
		$text = (string)file_get_contents(filename: dirname(path: __DIR__, levels: 3).'/'.$path);

		return json_decode(json: $text, associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
	}//end json()

	/**
	 * One schema of the shipped register.
	 *
	 * @param string $name The schema's name.
	 *
	 * @return array<string, mixed>
	 */
	private static function schema(string $name): array {
		return self::json(path: 'lib/Settings/learniq_register.json')['components']['schemas'][$name];
	}//end schema()

	/**
	 * One page of the learning manifest, by id.
	 *
	 * @param string $pageId The page id.
	 *
	 * @return array<string, mixed>
	 */
	private static function page(string $pageId): array {
		foreach (self::json(path: 'src/manifest.d/learning.json')['pages'] as $page) {
			if (($page['id'] ?? '') === $pageId) {
				return $page;
			}
		}

		return [];
	}//end page()

	/**
	 * Whether a payload validates against a schema's properties and required list.
	 *
	 * @param array<string, mixed> $schema  The register schema.
	 * @param array<string, mixed> $payload The object to save.
	 *
	 * @return bool
	 */
	private static function validates(array $schema, array $payload): bool {
		$shape  = ['type' => 'object', 'properties' => self::withoutReferences(node: $schema['properties']), 'required' => $schema['required']];
		$result = (new Validator())->validate(
			data: json_decode(json: (string)json_encode(value: $payload)),
			schema: json_decode(json: (string)json_encode(value: $shape))
		);

		return $result->isValid();
	}//end validates()

	/**
	 * A schema node without its `$ref` links: OpenRegister resolves those to
	 * other schemas as relations, which a plain JSON Schema validator cannot.
	 *
	 * @param array<string, mixed> $node A schema node.
	 *
	 * @return array<string, mixed>
	 */
	private static function withoutReferences(array $node): array {
		foreach ($node as $key => $child) {
			if ($key === '$ref') {
				unset($node[$key]);
				continue;
			}

			if (is_array(value: $child) === true) {
				$node[$key] = self::withoutReferences(node: $child);
			}
		}

		return $node;
	}//end withoutReferences()

	/**
	 * Scenario "A teacher splits a class into three groups": each subgroup is stored with its level, goal, approach and learners.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-rows-say-what-they-do/specs/learning-plan/spec.md#scenario-a-teacher-splits-a-class-into-three-groups
	 */
	public function testATeacherSplitsAClassIntoThreeGroups(): void {
		$plan = self::schema(name: 'GroupPlan');
		$this->assertTrue(
			condition: self::validates(
				schema: $plan,
				payload: [
					'cohortId' => '7c8d9e0f-1a2b-4c3d-8e4f-5a6b7c8d9e0f',
					'subject' => 'Rekenen',
					'coordinatorId' => 'po-ib-01',
					'tenant_id' => self::TENANT,
				]
			),
			message: 'a group plan for a cohort and a subject'
		);

		$schema = self::schema(name: 'GroupPlanSubgroup');
		foreach (self::subgroups() as $name => $subgroup) {
			$this->assertTrue(condition: self::validates(schema: $schema, payload: $subgroup), message: $name.' validates');
		}

		$this->assertSame(
			expected: ['intensief', 'basis', 'verdiept', 'custom'],
			actual: $schema['properties']['instructieniveau']['enum']
		);
		$this->assertSame(expected: 'array', actual: $schema['properties']['learnerIds']['type'], message: 'the learners assigned to it');
	}//end testATeacherSplitsAClassIntoThreeGroups()

	/**
	 * A subgroup without its plan, name, level, goal or approach is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-rows-say-what-they-do/specs/learning-plan/spec.md#requirement-a-coordinator-plans-a-class-in-ability-subgroups-with-learners-assigned-to-each
	 */
	public function testASubgroupNeedsItsLevelGoalAndApproach(): void {
		$schema = self::schema(name: 'GroupPlanSubgroup');
		$basis  = self::subgroups()['Basis'];

		foreach (['groupPlanId', 'name', 'instructieniveau', 'differentiatedGoal', 'approach'] as $field) {
			$without = $basis;
			unset($without[$field]);
			$this->assertFalse(condition: self::validates(schema: $schema, payload: $without), message: 'refused without '.$field);
		}

		$this->assertFalse(
			condition: self::validates(schema: $schema, payload: array_merge($basis, ['instructieniveau' => 'snel'])),
			message: 'an instruction level outside the four is refused'
		);
	}//end testASubgroupNeedsItsLevelGoalAndApproach()

	/**
	 * The group plan's page lists its own subgroups; a subgroup's page shows its fields and the plan.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-rows-say-what-they-do/specs/learning-plan/spec.md#scenario-a-teacher-splits-a-class-into-three-groups
	 */
	public function testThePagesListTheSubgroupsAndShowEach(): void {
		$widgets = array_column(array: self::page(pageId: 'GroupPlanDetail')['config']['widgets'], column_key: null, index_key: 'id');
		$this->assertArrayHasKey(key: 'gp-subgroups', array: $widgets);

		$list = $widgets['gp-subgroups']['content'];
		$this->assertSame(expected: 'group-plan-subgroup', actual: $list['schema']);
		$this->assertSame(expected: ['groupPlanId' => '@objectId'], actual: $list['filter'], message: 'only this plan\'s subgroups');
		$this->assertSame(expected: ['name', 'instructieniveau', 'differentiatedGoal'], actual: array_column(array: $list['columns'], column_key: 'key'));
		$this->assertSame(expected: 'GroupPlanSubgroupDetail', actual: $list['rowRoute']);

		$detail = self::page(pageId: 'GroupPlanSubgroupDetail');
		$this->assertSame(expected: 'group-plan-subgroup', actual: $detail['config']['schema']);
		$types = array_column(array: $detail['config']['widgets'], column_key: 'type');
		$this->assertContains(needle: 'data', haystack: $types, message: 'the data widget shows its level, goal, approach and learners');
		$this->assertContains(needle: 'related', haystack: $types, message: 'and the group plan it belongs to');
	}//end testThePagesListTheSubgroupsAndShowEach()
}//end class
