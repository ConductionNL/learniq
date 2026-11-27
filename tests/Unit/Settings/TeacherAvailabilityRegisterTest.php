<?php

/**
 * Register test for a teacher's free times for a conference round: the
 * availability is stored with her as teacher and her blocks, starts as a
 * draft, and submitting moves it to submitted.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Settings
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
 * @spec openspec/changes/capability-rows-say-what-they-do/specs/parent-conferences/spec.md#requirement-a-teacher-enters-and-submits-their-own-free-times-for-a-conference-round
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * The TeacherAvailability schema and its lifecycle.
 */
class TeacherAvailabilityRegisterTest extends TestCase {

	private const TENANT = '0f6c2a52-6d3f-4c1a-9b0a-5c1f6e2d7a10';

	private const ROUND = '3c5e7a9b-1d2f-4a6b-8c0d-2e4f6a8b0c1d';

	/**
	 * The TeacherAvailability schema of the shipped register.
	 *
	 * @return array<string, mixed> The schema.
	 */
	private function schema(): array {
		$json     = (string)file_get_contents(filename: dirname(path: __DIR__, levels: 3).'/lib/Settings/learniq_register.json');
		$register = json_decode(json: $json, associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);

		return $register['components']['schemas']['TeacherAvailability'];
	}//end schema()

	/**
	 * Whether a payload validates against the schema's properties and required list.
	 *
	 * @param array<string, mixed> $payload The object to save.
	 *
	 * @return bool True when it validates.
	 */
	private function validates(array $payload): bool {
		$schema = $this->schema();
		$shape  = [
			'type' => 'object',
			'properties' => self::withoutReferences(node: $schema['properties']),
			'required' => $schema['required'],
		];
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
	 * @return array<string, mixed> The node without `$ref`.
	 */
	private static function withoutReferences(array $node): array {
		unset($node['$ref']);
		foreach ($node as $key => $child) {
			if (is_array(value: $child) === true) {
				$node[$key] = self::withoutReferences(node: $child);
			}
		}

		return $node;
	}//end withoutReferences()

	/**
	 * Her Thursday evening from 18:00 to 20:00 is stored with her as teacher,
	 * her display name and that block, starts as a draft, and "Submit" moves
	 * it to submitted.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-rows-say-what-they-do/specs/parent-conferences/spec.md#requirement-a-teacher-enters-and-submits-their-own-free-times-for-a-conference-round
	 */
	public function testATeacherSubmitsHerFreeEvening(): void {
		$schema  = $this->schema();
		$payload = [
			'conferenceRoundId' => self::ROUND,
			'teacherId' => 'po-leerkracht-09',
			'teacherName' => 'Juf Sanne',
			'blocks' => [
				['startsAt' => '2026-11-12T18:00:00+01:00', 'endsAt' => '2026-11-12T20:00:00+01:00'],
			],
			'tenant_id' => self::TENANT,
		];

		$this->assertTrue(condition: $this->validates(payload: $payload));
		$this->assertSame(expected: 'ConferenceRound', actual: $schema['properties']['conferenceRoundId']['$ref']);

		$lifecycle = $schema['x-openregister-lifecycle'];
		$this->assertSame(expected: 'lifecycle', actual: $lifecycle['property']);
		$this->assertSame(expected: 'draft', actual: $lifecycle['initial']);
		$this->assertSame(expected: ['from' => 'draft', 'to' => 'submitted'], actual: $lifecycle['transitions']['submit']);
		$this->assertSame(expected: ['from' => 'submitted', 'to' => 'locked'], actual: $lifecycle['transitions']['lock']);
	}//end testATeacherSubmitsHerFreeEvening()

	/**
	 * An availability names its round, its teacher and at least the blocks
	 * list; a block needs both its start and its end.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-rows-say-what-they-do/specs/parent-conferences/spec.md#requirement-a-teacher-enters-and-submits-their-own-free-times-for-a-conference-round
	 */
	public function testAnAvailabilityWithoutTeacherOrWithAHalfBlockIsRefused(): void {
		$complete = [
			'conferenceRoundId' => self::ROUND,
			'teacherId' => 'po-leerkracht-09',
			'blocks' => [
				['startsAt' => '2026-11-12T18:00:00+01:00', 'endsAt' => '2026-11-12T20:00:00+01:00'],
			],
			'tenant_id' => self::TENANT,
		];

		$noTeacher = $complete;
		unset($noTeacher['teacherId']);
		$this->assertFalse(condition: $this->validates(payload: $noTeacher));

		$noRound = $complete;
		unset($noRound['conferenceRoundId']);
		$this->assertFalse(condition: $this->validates(payload: $noRound));

		$halfBlock           = $complete;
		$halfBlock['blocks'] = [['startsAt' => '2026-11-12T18:00:00+01:00']];
		$this->assertFalse(condition: $this->validates(payload: $halfBlock));
	}//end testAnAvailabilityWithoutTeacherOrWithAHalfBlockIsRefused()

	/**
	 * Only `submit` leaves a draft: nothing moves a draft straight to locked,
	 * so the planning, which reads submitted and locked availabilities, never
	 * reads a draft.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-rows-say-what-they-do/specs/parent-conferences/spec.md#requirement-a-teacher-enters-and-submits-their-own-free-times-for-a-conference-round
	 */
	public function testOnlySubmitLeavesADraft(): void {
		$fromDraft = array_keys(
			array: array_filter(
				array: $this->schema()['x-openregister-lifecycle']['transitions'],
				callback: static fn (array $transition): bool => in_array(needle: 'draft', haystack: (array)$transition['from'], strict: true)
			)
		);

		$this->assertSame(expected: ['submit'], actual: $fromDraft);
	}//end testOnlySubmitLeavesADraft()
}//end class
