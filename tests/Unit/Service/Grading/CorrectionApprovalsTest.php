<?php

/**
 * Golden tests for CorrectionApprovals: which approved correction request
 * lets a grade entry in a locked period be published, and which never does.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\Grading
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
 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#requirement-second-approver-for-changes-to-approved-data
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Grading;

use OCA\Learniq\Service\Grading\CorrectionApprovals;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * Pins the allowed and refused outcomes of the four-eyes correction lookup.
 */
class CorrectionApprovalsTest extends TestCase {

	/**
	 * The configs findAll was called with.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $calls = [];

	/**
	 * Approvals over the given stored rows.
	 *
	 * @param array<int, mixed> $rows The rows findAll returns.
	 *
	 * @return CorrectionApprovals
	 */
	private function approvals(array $rows): CorrectionApprovals {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config = [], bool $_rbac = true) use ($rows): array {
				$this->calls[] = ['config' => $config, 'rbac' => $_rbac];
				return $rows;
			}
		);

		return new CorrectionApprovals($objects);
	}//end approvals()

	/**
	 * An approved request decided by a second person.
	 *
	 * @param array<string, mixed> $extra Replaced fields.
	 *
	 * @return array<string, mixed>
	 */
	private static function request(array $extra = []): array {
		return $extra + [
			'id'            => 'dcr-1',
			'lifecycle'     => 'approved',
			'gradeEntryId'  => 'ge-1',
			'requestedBy'   => 'teacher',
			'decidedBy'     => 'head',
			'proposedValue' => '7.5',
		];
	}//end request()

	/**
	 * A request from a second approver covers the publish, and the lookup reads without RBAC.
	 *
	 * @return void
	 */
	public function testASecondApproverCoversThePublish(): void {
		$request = self::request();

		self::assertSame($request, $this->approvals([$request])->approvedFor(['id' => 'ge-1', 'value' => 7.5], 'teacher'));
		self::assertSame($request, $this->approvals([$request])->approvedFor(['uuid' => 'ge-1', 'value' => '7.50'], 'clerk'));
		self::assertSame(
			[
				'config' => [
					'filters' => ['register' => 'learniq', 'schema' => 'data-correction-request', 'gradeEntryId' => 'ge-1', 'lifecycle' => 'approved'],
					'limit'   => 50,
				],
				'rbac'   => false,
			],
			$this->calls[0]
		);
	}//end testASecondApproverCoversThePublish()

	/**
	 * Each failing fact refuses the publish.
	 *
	 * @return void
	 */
	public function testEveryFailingFactRefuses(): void {
		$entry = ['id' => 'ge-1', 'value' => '7.5'];
		$cases = [
			'not approved'        => self::request(['lifecycle' => 'submitted']),
			'no lifecycle'        => array_diff_key(self::request(), ['lifecycle' => true]),
			'another entry'       => self::request(['gradeEntryId' => 'ge-2']),
			'no requester'        => self::request(['requestedBy' => '']),
			'no approver'         => self::request(['decidedBy' => '']),
			'approved own ask'    => self::request(['decidedBy' => 'teacher']),
			'publisher approved'  => self::request(['decidedBy' => 'publisher']),
			'another value'       => self::request(['proposedValue' => '8']),
			'non-numeric value'   => self::request(['proposedValue' => 'good']),
			'empty proposal'      => self::request(['proposedValue' => '']),
		];
		foreach ($cases as $name => $request) {
			self::assertNull($this->approvals([$request])->approvedFor($entry, 'publisher'), $name);
		}
	}//end testEveryFailingFactRefuses()

	/**
	 * Without an entry id or a publisher nothing is read and nothing covers.
	 *
	 * @return void
	 */
	public function testNoEntryOrPublisherRefusesWithoutReading(): void {
		$approvals = $this->approvals([self::request()]);

		self::assertNull($approvals->approvedFor(['value' => '7.5'], 'teacher'));
		self::assertNull($approvals->approvedFor(['id' => '', 'uuid' => 'ge-1', 'value' => '7.5'], 'teacher'));
		self::assertNull($approvals->approvedFor(['id' => 'ge-1', 'value' => '7.5'], ''));
		self::assertSame([], $this->calls);
	}//end testNoEntryOrPublisherRefusesWithoutReading()

	/**
	 * The first covering request wins, serialised objects included.
	 *
	 * @return void
	 */
	public function testTheFirstCoveringRequestWins(): void {
		$first  = self::request(['id' => 'dcr-2', 'proposedValue' => null]);
		$object = new class($first) implements \JsonSerializable {
			/**
			 * @param array<string, mixed> $row The row.
			 */
			public function __construct(private array $row) {
			}

			/**
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return $this->row;
			}
		};

		$found = $this->approvals([self::request(['decidedBy' => 'teacher']), $object, self::request(['id' => 'dcr-3', 'proposedValue' => null])])
			->approvedFor(['id' => 'ge-1', 'value' => ''], 'publisher');

		self::assertSame('dcr-2', $found['id']);
	}//end testTheFirstCoveringRequestWins()

	/**
	 * Values compare as numbers, and blank matches only blank.
	 *
	 * @return void
	 */
	public function testSameValue(): void {
		$approvals = $this->approvals([]);
		$cases     = [
			[null, null, true],
			[null, '', true],
			['', null, true],
			['', 0, false],
			[null, '7', false],
			['7.5', 7.5, true],
			['7.5', '7.5000001', true],
			['7.5', '7.51', false],
			['7', null, false],
			['seven', 'seven', false],
			[8, '8.0', true],
		];
		foreach ($cases as [$approved, $current, $same]) {
			self::assertSame($same, $approvals->sameValue($approved, $current), var_export([$approved, $current], true));
		}
	}//end testSameValue()
}//end class
