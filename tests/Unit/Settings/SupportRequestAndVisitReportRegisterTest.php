<?php

/**
 * Register tests for two records the capability rows name: a TLV application
 * that points back at its support request, and a BPV visit report that a
 * school coach writes and finalizes.
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
 * @spec openspec/changes/capability-rows-say-what-they-do/specs/learning-plan/spec.md#requirement-a-tlv-application-is-made-for-a-support-request-and-points-back-to-it
 * @spec openspec/changes/capability-rows-say-what-they-do/specs/bpv/spec.md#requirement-a-school-coach-writes-up-a-visit-or-a-three-way-conversation-for-a-placement
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * The TlvApplication and BpvVisitReport schemas and the support request page.
 */
class SupportRequestAndVisitReportRegisterTest extends TestCase {

	private const TENANT = '0f6c2a52-6d3f-4c1a-9b0a-5c1f6e2d7a10';

	/**
	 * One schema of the shipped register.
	 *
	 * @param string $name The schema's name.
	 *
	 * @return array<string, mixed> The schema.
	 */
	private function schema(string $name): array {
		$json     = (string)file_get_contents(filename: dirname(path: __DIR__, levels: 3).'/lib/Settings/learniq_register.json');
		$register = json_decode(json: $json, associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);

		return $register['components']['schemas'][$name];
	}//end schema()

	/**
	 * Whether a payload validates against a schema's properties and required list.
	 *
	 * @param array<string, mixed> $schema  The register schema.
	 * @param array<string, mixed> $payload The object to save.
	 *
	 * @return bool True when it validates.
	 */
	private function validates(array $schema, array $payload): bool {
		$shape     = [
			'type' => 'object',
			'properties' => self::withoutReferences(node: $schema['properties']),
			'required' => $schema['required'],
		];
		$validator = new Validator();
		$result    = $validator->validate(
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
	 * A TLV application made from a support request is stored with that request.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-rows-say-what-they-do/specs/learning-plan/spec.md#requirement-a-tlv-application-is-made-for-a-support-request-and-points-back-to-it
	 */
	public function testATlvApplicationNamesItsSupportRequest(): void {
		$schema  = $this->schema(name: 'TlvApplication');
		$payload = [
			'supportRequestId' => '6b7e1f0a-2c3d-4e5f-8a9b-0c1d2e3f4a5b',
			'arrangementType' => 'Plaatsing in het speciaal basisonderwijs',
			'tenant_id' => self::TENANT,
		];

		$this->assertContains(needle: 'supportRequestId', haystack: $schema['required']);
		$this->assertSame(expected: 'uuid', actual: $schema['properties']['supportRequestId']['format']);
		$this->assertTrue(condition: $this->validates(schema: $schema, payload: $payload));
		$this->assertSame(expected: 'draft', actual: $schema['x-openregister-lifecycle']['initial']);
	}//end testATlvApplicationNamesItsSupportRequest()

	/**
	 * A TLV application without a support request is refused: the field is required.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-rows-say-what-they-do/specs/learning-plan/spec.md#requirement-a-tlv-application-is-made-for-a-support-request-and-points-back-to-it
	 */
	public function testATlvApplicationWithoutASupportRequestIsRefused(): void {
		$payload = ['arrangementType' => 'Plaatsing in het speciaal basisonderwijs', 'tenant_id' => self::TENANT];

		$this->assertFalse(condition: $this->validates(schema: $this->schema(name: 'TlvApplication'), payload: $payload));
	}//end testATlvApplicationWithoutASupportRequestIsRefused()

	/**
	 * The support request's page counts and lists the TLV applications that
	 * point to it, with their arrangement and decision.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-rows-say-what-they-do/specs/learning-plan/spec.md#requirement-a-tlv-application-is-made-for-a-support-request-and-points-back-to-it
	 */
	public function testTheSupportRequestPageListsItsTlvApplications(): void {
		$json     = (string)file_get_contents(filename: dirname(path: __DIR__, levels: 3).'/src/manifest.d/learning.json');
		$manifest = json_decode(json: $json, associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
		$widgets  = [];
		$counters = [];
		$this->collect(node: $manifest, widgets: $widgets, counters: $counters);

		$this->assertArrayHasKey(key: 'sr-tlvs', array: $widgets);
		$list = $widgets['sr-tlvs']['content'];
		$this->assertSame(expected: 'tlv-application', actual: $list['schema']);
		$this->assertSame(expected: ['supportRequestId' => '@objectId'], actual: $list['filter']);
		$columns = array_column(array: $list['columns'], column_key: 'key');
		$this->assertContains(needle: 'arrangementType', haystack: $columns);
		$this->assertContains(needle: 'decision', haystack: $columns);

		$this->assertNotSame(expected: [], actual: $counters, message: 'the page counts its TLV applications');
		$this->assertSame(expected: ['supportRequestId' => '@objectId'], actual: $counters[0]['filter']);
	}//end testTheSupportRequestPageListsItsTlvApplications()

	/**
	 * Collect widgets by id and the count entries over tlv-application.
	 *
	 * @param mixed                               $node     A manifest node.
	 * @param array<string, array<string, mixed>> $widgets  Receives widgets by id.
	 * @param array<int, array<string, mixed>>    $counters Receives the count entries.
	 *
	 * @return void
	 */
	private function collect(mixed $node, array &$widgets, array &$counters): void {
		if (is_array(value: $node) === false) {
			return;
		}

		if (isset($node['id'], $node['type']) === true && is_string(value: $node['id']) === true) {
			$widgets[$node['id']] = $node;
		}

		if (($node['metric'] ?? null) === 'count' && ($node['schema'] ?? null) === 'tlv-application') {
			$counters[] = $node;
		}

		foreach ($node as $child) {
			$this->collect(node: $child, widgets: $widgets, counters: $counters);
		}
	}//end collect()

	/**
	 * A coach's three-way conversation is stored with date, kind, attendees,
	 * narrative and action points, linked to the placement and the learner,
	 * and finalizing moves it from draft to finalized.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-rows-say-what-they-do/specs/bpv/spec.md#requirement-a-school-coach-writes-up-a-visit-or-a-three-way-conversation-for-a-placement
	 */
	public function testACoachRecordsAThreeWayConversation(): void {
		$schema  = $this->schema(name: 'BpvVisitReport');
		$payload = [
			'bpvPlacementId' => '2d4f6a8c-1b3e-4c5d-9e7f-0a1b2c3d4e5f',
			'learnerRef' => '7a9c1e3f-5b7d-4f1a-8c2e-4d6f8a0b2c4e',
			'visitDate' => '2026-10-13',
			'visitKind' => 'tussentijds-gesprek',
			'attendees' => [
				['role' => 'student', 'name' => 'Jesse Kok'],
				['role' => 'praktijkopleider', 'name' => 'Ilse van Dijk'],
				['role' => 'bpv-begeleider', 'name' => 'Petra de Boer'],
			],
			'schoolCoachId' => 'petra.deboer',
			'narrative' => 'Jesse werkt zelfstandig aan de draaibank.',
			'actionPoints' => "1. Werkproces B1-K2-W1 afronden.\n2. Urenbriefjes wekelijks indienen.",
			'tenant_id' => self::TENANT,
		];

		$this->assertTrue(condition: $this->validates(schema: $schema, payload: $payload));
		foreach (['bpvPlacementId', 'learnerRef', 'visitDate', 'visitKind'] as $field) {
			$this->assertContains(needle: $field, haystack: $schema['required']);
			$without = $payload;
			unset($without[$field]);
			$this->assertFalse(condition: $this->validates(schema: $schema, payload: $without), message: $field);
		}

		$this->assertSame(
			expected: ['voortgangsbezoek', 'tussentijds-gesprek', 'eindgesprek', 'incident'],
			actual: $schema['properties']['visitKind']['enum']
		);

		$lifecycle = $schema['x-openregister-lifecycle'];
		$this->assertSame(expected: 'draft', actual: $lifecycle['initial']);
		$this->assertSame(expected: ['draft'], actual: (array)$lifecycle['transitions']['finalize']['from']);
		$this->assertSame(expected: 'finalized', actual: $lifecycle['transitions']['finalize']['to']);
	}//end testACoachRecordsAThreeWayConversation()
}//end class
