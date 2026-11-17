<?php

/**
 * The exam board sees a practical exam result only once both assessors signed it.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\Exam
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
 * @spec openspec/changes/practical-exam-assessment/specs/exam-board/spec.md#requirement-a-practical-exam-result-needs-both-assessors
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Exam;

use OCA\Learniq\Service\Exam\PracticalExamBoardResults;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * Daan's assessment carries both signatures, Eva's only the first; Piet's signature is no assessor's.
 *
 * @covers \OCA\Learniq\Service\Exam\PracticalExamBoardResults
 * @uses   \OCA\Learniq\Support\ObjectRow
 */
class PracticalExamBoardResultsTest extends TestCase {

	private const RUUD = 'ee010008-0000-4000-8000-000000000701';

	private const SANNE = 'ee010008-0000-4000-8000-000000000702';

	private const PIET = 'ee010008-0000-4000-8000-000000000703';

	private const DAAN = 'ee010008-0000-4000-8000-000000000501';

	private const EVA = 'ee010008-0000-4000-8000-000000000502';

	private const SITTING = 'ee010008-0000-4000-8000-000000000601';

	private const DAANS = 'ee010008-0000-4000-8000-000000000801';

	private const EVAS = 'ee010008-0000-4000-8000-000000000802';

	private const TENANT = 'ee010008-0000-4000-8000-000000000901';

	private RegisterFaithfulStore $store;

	protected function setUp(): void {
		parent::setUp();
		$this->store = new RegisterFaithfulStore();
		$this->store->actingUser = 'examboard-chair';
		$this->store->callerGroups = ['compliance-officers'];
		$assignments = [];
		foreach ([self::DAAN, self::EVA] as $n => $learner) {
			$assignments[] = ['id' => 'as-f' . $n, 'examSittingId' => self::SITTING, 'externalAssessorId' => self::RUUD, 'learnerRef' => $learner, 'role' => 'first', 'lifecycle' => 'assigned', 'tenant_id' => self::TENANT];
			$assignments[] = ['id' => 'as-s' . $n, 'examSittingId' => self::SITTING, 'externalAssessorId' => self::SANNE, 'learnerRef' => $learner, 'role' => 'second', 'lifecycle' => 'assigned', 'tenant_id' => self::TENANT];
		}

		$this->store->rows = [
			'exam-assessor-assignment' => $assignments,
			'practical-exam-assessment' => [
				['id' => self::DAANS, 'examSittingId' => self::SITTING, 'learnerRef' => self::DAAN, 'assessorId' => self::RUUD, 'assessorName' => 'Ruud Smit', 'outcome' => 'pass', 'criteria' => [['criterion' => 'veilig werken', 'score' => 3]], 'lifecycle' => 'submitted', 'tenant_id' => self::TENANT],
				['id' => self::EVAS, 'examSittingId' => self::SITTING, 'learnerRef' => self::EVA, 'assessorId' => self::RUUD, 'outcome' => 'fail', 'lifecycle' => 'submitted', 'tenant_id' => self::TENANT],
			],
			'signature' => [
				$this->signature(id: 'sig-1', subject: self::DAANS, signer: self::RUUD, level: 'basic'),
				$this->signature(id: 'sig-2', subject: self::DAANS, signer: self::SANNE, level: 'substantial'),
				$this->signature(id: 'sig-3', subject: self::EVAS, signer: self::RUUD, level: 'basic'),
				// Somebody who holds no role on Eva's exam: never the second signature.
				$this->signature(id: 'sig-4', subject: self::EVAS, signer: self::PIET, level: 'high'),
			],
		];
	}

	/**
	 * One assessor signature row.
	 *
	 * @return array<string, mixed>
	 */
	private function signature(string $id, string $subject, string $signer, string $level): array {
		return [
			'id' => $id, 'subjectKind' => 'practical-exam-assessment', 'subjectId' => $subject, 'subjectVersion' => 1,
			'signerId' => $signer, 'signerRole' => 'assessor', 'signedAt' => '2026-11-14T15:30:00+00:00',
			'assuranceLevel' => $level, 'method' => 'portal-assertion', 'tenant_id' => self::TENANT,
		];
	}

	private function service(): PracticalExamBoardResults {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $this->store->findAll($config, $_rbac, $_multitenancy)
		);

		return new PracticalExamBoardResults(objectService: $objects);
	}

	public function testOnlyTheAssessmentBothAssessorsSignedReachesTheBoard(): void {
		$results = $this->service()->forSitting(sittingId: self::SITTING);

		self::assertCount(1, $results);
		self::assertSame(self::DAANS, $results[0]['assessmentId']);
		self::assertSame(self::DAAN, $results[0]['learnerRef']);
		self::assertSame('pass', $results[0]['outcome']);
		self::assertSame('Ruud Smit', $results[0]['assessorName']);
		self::assertSame(
			[
				['role' => 'first', 'signerId' => self::RUUD, 'signedAt' => '2026-11-14T15:30:00+00:00', 'assuranceLevel' => 'basic'],
				['role' => 'second', 'signerId' => self::SANNE, 'signedAt' => '2026-11-14T15:30:00+00:00', 'assuranceLevel' => 'substantial'],
			],
			$results[0]['signatures']
		);
	}

	public function testTheSecondSignatureCompletesEvasResult(): void {
		$this->store->rows['signature'][] = $this->signature(id: 'sig-5', subject: self::EVAS, signer: self::SANNE, level: 'basic');

		$results = $this->service()->forSitting(sittingId: self::SITTING);

		self::assertSame([self::DAANS, self::EVAS], array_column($results, 'assessmentId'));
	}

	public function testAWithdrawnSecondAssessorLeavesTheResultOffTheBoard(): void {
		foreach ($this->store->rows['exam-assessor-assignment'] as $i => $row) {
			if ($row['id'] === 'as-s0') {
				$this->store->rows['exam-assessor-assignment'][$i]['lifecycle'] = 'withdrawn';
			}
		}

		self::assertSame([], $this->service()->forSitting(sittingId: self::SITTING));
	}

	public function testACallerWhoMayNotReadAssessmentsGetsNothing(): void {
		$this->store->callerGroups = ['students'];

		self::assertSame([], $this->service()->forSitting(sittingId: self::SITTING));
		self::assertSame([], $this->service()->forSitting(sittingId: ''));
		foreach ($this->store->reads as $read) {
			self::assertTrue($read['rbac'], 'every read runs with the caller\'s rights');
		}
	}
}
