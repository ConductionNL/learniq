<?php

/**
 * The decision on a data correction request: never by the person who asked,
 * and applied only once the grade is published with the approved value.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Lifecycle
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

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\Lifecycle\DataCorrectionDecisionGuard;
use OCA\Learniq\Tests\Support\GuardVerdicts;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * DataCorrectionDecisionGuard over a grade entry store.
 *
 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#requirement-second-approver-for-changes-to-approved-data
 */
class DataCorrectionDecisionGuardTest extends TestCase {

	use GuardVerdicts;

	private const ENTRY = '0b7c5a3e-0000-4000-8000-000000000001';

	/**
	 * A request from teacher A to change a 5.5 into a 6.5.
	 *
	 * @var array<string, mixed>
	 */
	private const REQUEST = [
		'id'            => 'dcr-1',
		'gradeEntryId'  => self::ENTRY,
		'proposedValue' => 6.5,
		'currentValue'  => 5.5,
		'reason'        => 'A page of the exam was not counted.',
		'requestedBy'   => 'teacher-a',
		'lifecycle'     => 'approved',
	];

	/**
	 * The guard over a store holding one grade entry.
	 *
	 * @param array<string, mixed>|null $entry The grade entry, null for none.
	 *
	 * @return DataCorrectionDecisionGuard
	 */
	private function guard(?array $entry = null): DataCorrectionDecisionGuard {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			static function (int|string $id) use ($entry) {
				if ($entry !== null && (string)$id === self::ENTRY) {
					return OrEntityFactory::make($entry, 'grade-entry');
				}

				throw new DoesNotExistException('gone');
			}
		);

		return new DataCorrectionDecisionGuard(objects: $objects);
	}//end guard()

	/**
	 * Principal B approves teacher A's request.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#scenario-a-second-person-approves-a-correction
	 */
	public function testASecondPersonApproves(): void {
		self::assertAllowed($this->guard()->check(self::REQUEST, 'approve', 'principal-b'));
	}//end testASecondPersonApproves()

	/**
	 * Teacher A can not approve their own request, and nobody approves without a session.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#scenario-the-requester-cannot-approve
	 */
	public function testTheRequesterCannotApprove(): void {
		$verdict = $this->guard()->check(self::REQUEST, 'approve', 'teacher-a');
		self::assertDenied($verdict);
		self::assertStringContainsString('second person', (string)$verdict->getMessage());

		self::assertDenied($this->guard()->check(self::REQUEST, 'approve', ''));
	}//end testTheRequesterCannotApprove()

	/**
	 * A request is applied when its grade is published with the approved
	 * value, not while the grade is still revised or carries another value.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#scenario-an-auditor-reads-the-trail
	 */
	public function testApplyWaitsForThePublishedApprovedValue(): void {
		$published = ['id' => self::ENTRY, 'value' => 6.5, 'lifecycle' => 'published'];
		self::assertAllowed($this->guard(entry: $published)->check(self::REQUEST, 'apply', 'teacher-a'));

		self::assertDenied($this->guard(entry: array_merge($published, ['lifecycle' => 'revised']))->check(self::REQUEST, 'apply', 'teacher-a'));
		self::assertDenied($this->guard(entry: array_merge($published, ['value' => 7.0]))->check(self::REQUEST, 'apply', 'teacher-a'));
		self::assertDenied($this->guard(entry: null)->check(self::REQUEST, 'apply', 'teacher-a'));
	}//end testApplyWaitsForThePublishedApprovedValue()
}//end class
