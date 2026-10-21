<?php

/**
 * A fraud case's hearing records and decision internals are withheld by the
 * server from the accused learner and the reporter.
 *
 * The accused and the reporter may read the case itself, so this test reads
 * the stored object as each of them through the shipped object-level block,
 * then drops every property whose own `authorization.read` they do not pass,
 * the way OpenRegister's PropertyRbacHandler renders it for a non-admin.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Register
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
 * @spec openspec/specs/exam-board/spec.md#requirement-fraudcase-read-access-is-restricted-hearing-decision-internals-are-withheld-by-the-server
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Register;

use OCA\Learniq\Tests\Support\OrReadVerdict;
use PHPUnit\Framework\TestCase;

/**
 * Reads a shipped FraudCase as the accused, the reporter and the exam board.
 */
class ExamCaseInternalsWithheldTest extends TestCase {
	use OrReadVerdict;

	/**
	 * The fraud case properties only the exam board reads.
	 */
	private const INTERNALS = [
		'hearingRecords',
		'decisionRationale',
		'sanctionType',
		'sanctionDurationMonths',
		'sanctionScope',
	];

	/**
	 * A decided fraud case, as stored.
	 *
	 * @return array<string, mixed>
	 */
	private function storedCase(): array {
		return [
			'id'                     => 'case-1',
			'reporterId'             => 'teacher-anna',
			'accusedLearnerId'       => 'profile-uuid-1',
			'accusedLearnerUserId'   => 'pupil-noor',
			'sourceKind'             => 'assignment-submission',
			'allegation'             => 'Copied the essay.',
			'reportedAt'             => '2026-10-01T09:00:00+00:00',
			'hearingRecords'         => [['heldAt' => '2026-10-08', 'notes' => 'The board discussed the evidence.']],
			'verdict'                => 'fraud-proven',
			'decisionRationale'      => 'The board found the work copied.',
			'sanctionType'           => 'grade-annulment',
			'sanctionDurationMonths' => 1,
			'sanctionScope'          => 'single-assessment',
			'appealDeadline'         => '2026-11-19',
			'lifecycle'              => 'decided',
			'tenant_id'              => 'school',
		];
	}//end storedCase()

	/**
	 * The FraudCase properties as shipped.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function properties(): array {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'), true);

		return $register['components']['schemas']['FraudCase']['properties'];
	}//end properties()

	/**
	 * The case as a non-admin user receives it: null when the object read is
	 * refused, otherwise without the properties that user may not read.
	 *
	 * @param string             $userId The signed-in user.
	 * @param array<int, string> $groups The user's Nextcloud groups.
	 *
	 * @return array<string, mixed>|null
	 */
	private function readAs(string $userId, array $groups): ?array {
		$object = $this->storedCase();
		if ($this->canRead(authorization: self::shippedAuthorization(slug: 'fraud-case'), object: $object, userId: $userId, groups: $groups, owner: null) === false) {
			return null;
		}

		foreach ($this->properties() as $name => $definition) {
			$rules = ($definition['authorization']['read'] ?? []);
			if ($rules === [] || array_key_exists($name, $object) === false) {
				continue;
			}

			if ($this->canRead(authorization: ['read' => $rules], object: $object, userId: $userId, groups: $groups, owner: null) === false) {
				unset($object[$name]);
			}
		}

		return $object;
	}//end readAs()

	/**
	 * The accused learner reads the outline and the verdict, not the hearing
	 * records or the decision internals.
	 *
	 * @return void
	 */
	public function testTheAccusedReadsTheCaseWithoutItsInternals(): void {
		$read = $this->readAs(userId: 'pupil-noor', groups: []);

		$this->assertNotNull($read, 'The accused may read their own case.');
		$this->assertSame('Copied the essay.', $read['allegation']);
		$this->assertSame('fraud-proven', $read['verdict']);
		$this->assertSame('2026-11-19', $read['appealDeadline']);
		foreach (self::INTERNALS as $name) {
			$this->assertArrayNotHasKey($name, $read, $name . ' reached the accused.');
		}
	}//end testTheAccusedReadsTheCaseWithoutItsInternals()

	/**
	 * The reporter, an instructor, reads the case but not its internals.
	 *
	 * @return void
	 */
	public function testTheReporterReadsTheCaseWithoutItsInternals(): void {
		$read = $this->readAs(userId: 'teacher-anna', groups: ['instructors']);

		$this->assertNotNull($read);
		foreach (self::INTERNALS as $name) {
			$this->assertArrayNotHasKey($name, $read, $name . ' reached the reporter.');
		}
	}//end testTheReporterReadsTheCaseWithoutItsInternals()

	/**
	 * The exam board reads every field.
	 *
	 * @return void
	 */
	public function testTheExamBoardReadsTheInternals(): void {
		$read = $this->readAs(userId: 'board-member', groups: ['compliance-officers']);

		$this->assertNotNull($read);
		foreach (self::INTERNALS as $name) {
			$this->assertArrayHasKey($name, $read, $name . ' was withheld from the exam board.');
		}
	}//end testTheExamBoardReadsTheInternals()

	/**
	 * Only the exam board writes the internals; the transition inputs that
	 * set them are the board's.
	 *
	 * @return void
	 */
	public function testOnlyTheExamBoardWritesTheInternals(): void {
		$properties = $this->properties();
		foreach (self::INTERNALS as $name) {
			$this->assertSame(['compliance-officers'], ($properties[$name]['authorization']['read'] ?? null), $name . ' read');
			$this->assertSame(['compliance-officers'], ($properties[$name]['authorization']['update'] ?? null), $name . ' update');
		}
	}//end testOnlyTheExamBoardWritesTheInternals()
}//end class
