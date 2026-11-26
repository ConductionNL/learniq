<?php

/**
 * Each assessor signs a submitted practical exam assessment once, from his own assignment.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\Portal
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

namespace OCA\Learniq\Tests\Unit\Service\Portal;

use DateTime;
use OCA\Learniq\Service\Portal\PortalOutcome;
use OCA\Learniq\Service\Portal\PortalPracticalExamSigning;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Ruud (first) and Sanne (second) sign Daan's assessment; Piet, not assigned, cannot.
 *
 * @covers \OCA\Learniq\Service\Portal\PortalPracticalExamSigning
 * @uses   PortalOutcome
 * @uses   \OCA\Learniq\Service\Portal\Support\AssessmentRowValues
 * @uses   \OCA\Learniq\Support\ObjectRow
 * @uses   \OCA\Learniq\Service\Portal\PortalPracticalExamAssessment
 */
class PortalPracticalExamSigningTest extends TestCase {

	private const RUUD = 'ee010008-0000-4000-8000-000000000701';

	private const SANNE = 'ee010008-0000-4000-8000-000000000702';

	private const PIET = 'ee010008-0000-4000-8000-000000000703';

	private const DAAN = 'ee010008-0000-4000-8000-000000000501';

	private const SITTING = 'ee010008-0000-4000-8000-000000000601';

	private const ASSESSMENT = 'ee010008-0000-4000-8000-000000000801';

	private const DRAFT = 'ee010008-0000-4000-8000-000000000802';

	private const TENANT = 'ee010008-0000-4000-8000-000000000901';

	private RegisterFaithfulStore $store;

	private string $floor = 'basic';

	private bool $failSave = false;

	protected function setUp(): void {
		parent::setUp();
		$this->store = new RegisterFaithfulStore();
		$this->store->rows = [
			'exam-assessor-assignment' => [
				['id' => 'as-1', 'examSittingId' => self::SITTING, 'externalAssessorId' => self::RUUD, 'learnerRef' => self::DAAN, 'role' => 'first', 'lifecycle' => 'assigned', 'tenant_id' => self::TENANT],
				['id' => 'as-2', 'examSittingId' => self::SITTING, 'externalAssessorId' => self::SANNE, 'learnerRef' => self::DAAN, 'role' => 'second', 'lifecycle' => 'assigned', 'tenant_id' => self::TENANT],
				['id' => 'as-3', 'examSittingId' => self::SITTING, 'externalAssessorId' => self::PIET, 'learnerRef' => self::DAAN, 'role' => 'second', 'lifecycle' => 'withdrawn', 'tenant_id' => self::TENANT],
			],
			'practical-exam-assessment' => [
				['id' => self::ASSESSMENT, 'examSittingId' => self::SITTING, 'learnerRef' => self::DAAN, 'assessorId' => self::RUUD, 'outcome' => 'pass', 'lifecycle' => 'submitted', 'tenant_id' => self::TENANT],
				['id' => self::DRAFT, 'examSittingId' => self::SITTING, 'learnerRef' => self::DAAN, 'assessorId' => self::SANNE, 'lifecycle' => 'draft', 'tenant_id' => self::TENANT],
			],
			'signature' => [],
		];
	}

	private function service(): PortalPracticalExamSigning {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $this->store->findAll($config, $_rbac, $_multitenancy)
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ObjectSaveOptions $options = new ObjectSaveOptions()) {
				if ($this->failSave === true) {
					throw new RuntimeException('database gone');
				}

				return $this->store->save((string)$options->target->schema, $object, $options->uuid, false);
			}
		);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default): string => $this->floor);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-11-14T15:30:00+00:00'));

		return new PortalPracticalExamSigning(objectService: $objects, appConfig: $config, timeFactory: $time, logger: new NullLogger());
	}

	/**
	 * The stored signatures.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function signatures(): array {
		return array_values($this->store->rows['signature']);
	}

	public function testBothAssessorsSignWithTheAssuranceLevelOfTheirSession(): void {
		$first = $this->service()->sign(assessorRef: self::RUUD, trust: 'low', assessmentId: self::ASSESSMENT);
		$second = $this->service()->sign(assessorRef: self::SANNE, trust: 'substantial', assessmentId: self::ASSESSMENT);

		self::assertSame(201, $first->status);
		self::assertSame('first', $first->body['role']);
		self::assertSame('basic', $first->body['assuranceLevel']);
		self::assertSame('2026-11-14T15:30:00+00:00', $first->body['signedAt']);
		self::assertSame('second', $second->body['role']);
		self::assertSame('substantial', $second->body['assuranceLevel']);

		$rows = $this->signatures();
		self::assertCount(2, $rows);
		self::assertSame('practical-exam-assessment', $rows[0]['subjectKind']);
		self::assertSame(self::ASSESSMENT, $rows[0]['subjectId']);
		self::assertSame(self::RUUD, $rows[0]['signerId']);
		self::assertSame('assessor', $rows[0]['signerRole']);
		self::assertSame('basic', $rows[0]['assuranceLevel']);
		self::assertSame('portal-assertion', $rows[0]['method']);
		self::assertSame('exam-assessor-assignment:as-1', $rows[0]['evidenceRef']);
		self::assertSame(self::TENANT, $rows[0]['tenant_id']);
		self::assertSame(self::SANNE, $rows[1]['signerId']);
		self::assertSame('substantial', $rows[1]['assuranceLevel']);
	}

	public function testAnAssessorSignsOnlyOnce(): void {
		self::assertSame(201, $this->service()->sign(assessorRef: self::RUUD, trust: 'low', assessmentId: self::ASSESSMENT)->status);

		$again = $this->service()->sign(assessorRef: self::RUUD, trust: 'low', assessmentId: self::ASSESSMENT);

		self::assertSame(409, $again->status);
		self::assertSame(['error' => 'already_signed'], $again->body);
		self::assertCount(1, $this->signatures());
	}

	public function testNoLiveAssignmentOrNoSuchAssessmentIsRefusedAlike(): void {
		$withdrawn = $this->service()->sign(assessorRef: self::PIET, trust: 'high', assessmentId: self::ASSESSMENT);
		$missing = $this->service()->sign(assessorRef: self::RUUD, trust: 'low', assessmentId: 'ee010008-0000-4000-8000-000000000899');

		self::assertSame(403, $withdrawn->status);
		self::assertSame(['error' => 'not_assigned'], $withdrawn->body);
		self::assertSame($withdrawn->body, $missing->body);
		self::assertSame([], $this->signatures());
	}

	public function testADraftCannotBeSignedYet(): void {
		$outcome = $this->service()->sign(assessorRef: self::RUUD, trust: 'low', assessmentId: self::DRAFT);

		self::assertSame(409, $outcome->status);
		self::assertSame(['error' => 'not_submitted'], $outcome->body);
		self::assertSame([], $this->signatures());
	}

	public function testTheFloorIncompleteRequestsAndAFailedWrite(): void {
		$this->floor = 'substantial';
		self::assertSame(['error' => 'assurance_too_low', 'required' => 'substantial'], $this->service()->sign(assessorRef: self::RUUD, trust: 'low', assessmentId: self::ASSESSMENT)->body);
		self::assertSame([], $this->store->reads, 'the floor refuses before any read');

		$this->floor = 'unknown-level';
		self::assertSame(422, $this->service()->sign(assessorRef: self::RUUD, trust: 'low', assessmentId: '')->status);
		self::assertSame(422, $this->service()->sign(assessorRef: '', trust: 'low', assessmentId: self::ASSESSMENT)->status);

		$this->failSave = true;
		$failed = $this->service()->sign(assessorRef: self::RUUD, trust: 'low', assessmentId: self::ASSESSMENT);
		self::assertSame(502, $failed->status);
		self::assertSame(['error' => 'downstream_error'], $failed->body);
	}

	/**
	 * Sanne signs Daan's submitted assessment by naming the sitting and the candidate, once.
	 *
	 * @return void
	 */
	public function testTheSecondAssessorSignsTheCandidatesSubmittedAssessmentFromThePortal(): void {
		$outcome = $this->signFor(assessor: self::SANNE, trust: 'substantial', learner: self::DAAN);

		self::assertSame(expected: 201, actual: $outcome->status);
		self::assertSame(expected: 'second', actual: $outcome->body['role']);
		$rows = $this->signatures();
		self::assertCount(expectedCount: 1, haystack: $rows);
		self::assertSame(expected: self::ASSESSMENT, actual: $rows[0]['subjectId'], message: 'the submitted one, not her own draft');
		self::assertSame(expected: self::SANNE, actual: $rows[0]['signerId']);
		self::assertSame(expected: 'substantial', actual: $rows[0]['assuranceLevel']);
		self::assertSame(expected: 'exam-assessor-assignment:as-2', actual: $rows[0]['evidenceRef']);

		$again = $this->signFor(assessor: self::SANNE, trust: 'substantial', learner: self::DAAN);
		self::assertSame(expected: ['error' => 'already_signed'], actual: $again->body);
	}//end testTheSecondAssessorSignsTheCandidatesSubmittedAssessmentFromThePortal()

	/**
	 * Without a live assignment, or without exactly one submitted assessment, nothing is signed.
	 *
	 * @return void
	 */
	public function testThePortalSignatureNeedsALiveAssignmentAndExactlyOneSubmittedAssessment(): void {
		$withdrawn = $this->signFor(assessor: self::PIET, trust: 'high', learner: self::DAAN);
		self::assertSame(expected: 403, actual: $withdrawn->status);
		self::assertSame(expected: ['error' => 'not_assigned'], actual: $withdrawn->body);

		$otherCandidate = $this->signFor(assessor: self::RUUD, trust: 'low', learner: 'ee010008-0000-4000-8000-000000000599');
		self::assertSame(expected: ['error' => 'not_assigned'], actual: $otherCandidate->body);

		$this->store->rows['practical-exam-assessment'][0]['lifecycle'] = 'draft';
		$none = $this->signFor(assessor: self::RUUD, trust: 'low', learner: self::DAAN);
		self::assertSame(expected: 409, actual: $none->status);
		self::assertSame(expected: ['error' => 'not_submitted'], actual: $none->body);

		$this->store->rows['practical-exam-assessment'][0]['lifecycle'] = 'submitted';
		$this->store->rows['practical-exam-assessment'][1]['lifecycle'] = 'submitted';
		$several = $this->signFor(assessor: self::RUUD, trust: 'low', learner: self::DAAN);
		self::assertSame(expected: 409, actual: $several->status);
		self::assertSame(expected: ['error' => 'several_assessments'], actual: $several->body);
		self::assertSame(expected: [], actual: $this->signatures());
	}//end testThePortalSignatureNeedsALiveAssignmentAndExactlyOneSubmittedAssessment()

	/**
	 * The floor refuses before any read; an empty value is 422 and a failed write 502.
	 *
	 * @return void
	 */
	public function testThePortalSignatureKeepsTheFloorAndAnswersIncompleteAndFailedWrites(): void {
		$this->floor = 'substantial';
		$refused     = $this->signFor(assessor: self::RUUD, trust: 'low', learner: self::DAAN);
		self::assertSame(expected: ['error' => 'assurance_too_low', 'required' => 'substantial'], actual: $refused->body);
		self::assertSame(expected: [], actual: $this->store->reads, message: 'the floor refuses before any read');

		$this->floor = 'basic';
		$service     = $this->service();
		$noSitting   = $service->signForCandidate(assessorRef: self::RUUD, trust: 'low', sittingId: '', learnerRef: self::DAAN);
		self::assertSame(expected: 422, actual: $noSitting->status);
		self::assertSame(expected: 422, actual: $this->signFor(assessor: self::RUUD, trust: 'low', learner: '')->status);
		self::assertSame(expected: 422, actual: $this->signFor(assessor: '', trust: 'low', learner: self::DAAN)->status);

		$this->failSave = true;
		$failed         = $this->signFor(assessor: self::RUUD, trust: 'low', learner: self::DAAN);
		self::assertSame(expected: ['error' => 'downstream_error'], actual: $failed->body);
	}//end testThePortalSignatureKeepsTheFloorAndAnswersIncompleteAndFailedWrites()

	/**
	 * Sign from the portal at the shared sitting.
	 *
	 * @param string $assessor The calling assessor.
	 * @param string $trust    The session's trust.
	 * @param string $learner  The candidate.
	 *
	 * @return PortalOutcome The outcome.
	 */
	private function signFor(string $assessor, string $trust, string $learner): PortalOutcome {
		return $this->service()->signForCandidate(assessorRef: $assessor, trust: $trust, sittingId: self::SITTING, learnerRef: $learner);
	}//end signFor()
}
