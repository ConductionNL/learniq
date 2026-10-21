<?php

/**
 * Tests for TimetableVisibilityPolicyWriter.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/personal-timetable/spec.md#requirement-a-school-sets-whose-timetables-each-role-may-see
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\TimetableVisibilityPolicyWriter;
use OCA\Learniq\Service\TimetableVisibilityService;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * The admin page's save of the visibility policy.
 */
class TimetableVisibilityPolicyWriterTest extends TestCase {
	/**
	 * Every saveObject call.
	 *
	 * @var array<int, array{object: array<string, mixed>, schema: mixed, uuid: string|null}>
	 */
	private array $saved = [];

	/**
	 * The writer over a policy that is stored under this id (or not at all).
	 *
	 * @param string|null $policyId The stored policy object, or null.
	 *
	 * @return TimetableVisibilityPolicyWriter
	 */
	private function writer(?string $policyId): TimetableVisibilityPolicyWriter {
		$visibility = $this->createMock(TimetableVisibilityService::class);
		$visibility->method('policy')->willReturn(TimetableVisibilityService::DEFAULTS);
		$visibility->method('policyId')->willReturn($policyId);

		$objects = $this->createMock(ObjectService::class);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null) {
				$this->saved[] = ['object' => $object, 'schema' => $schema, 'uuid' => $uuid];
				return OrEntityFactory::make($object, (string)$schema);
			}
		);

		return new TimetableVisibilityPolicyWriter(visibility: $visibility, objectService: $objects);
	}//end writer()

	/**
	 * A change keeps the other keys and updates the stored policy object.
	 *
	 * @return void
	 */
	public function testAChangeKeepsTheOtherKeysAndUpdatesTheStoredPolicy(): void {
		self::assertNull($this->writer(policyId: 'policy-1')->save(changes: ['learnerSeesGroups' => 'all']));

		self::assertSame('policy-1', $this->saved[0]['uuid']);
		self::assertSame('timetable-visibility-policy', $this->saved[0]['schema']);
		self::assertSame('all', $this->saved[0]['object']['learnerSeesGroups']);
		self::assertSame(TimetableVisibilityService::DEFAULTS['learnerSeesTeachers'], $this->saved[0]['object']['learnerSeesTeachers']);
	}//end testAChangeKeepsTheOtherKeysAndUpdatesTheStoredPolicy()

	/**
	 * The first save creates the policy.
	 *
	 * @return void
	 */
	public function testTheFirstSaveCreatesThePolicy(): void {
		$this->writer(policyId: null)->save(changes: ['learnerSeesRooms' => 'none']);

		self::assertNull($this->saved[0]['uuid']);
	}//end testTheFirstSaveCreatesThePolicy()

	/**
	 * An unknown key or value is refused and nothing is written.
	 *
	 * @return void
	 */
	public function testAnUnknownKeyOrValueIsRefused(): void {
		self::assertNotNull($this->writer(policyId: null)->save(changes: ['learnerSeesGroups' => 'everyone']));
		self::assertNotNull($this->writer(policyId: null)->save(changes: ['parentsSeeEverything' => 'all']));
		self::assertSame([], $this->saved);
	}//end testAnUnknownKeyOrValueIsRefused()
}//end class
