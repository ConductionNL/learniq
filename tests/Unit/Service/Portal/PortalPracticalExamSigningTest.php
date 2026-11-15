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
use OCA\Learniq\Service\Portal\PortalPracticalExamSigning;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
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
 * @uses   \OCA\Learniq\Service\Portal\PortalOutcome
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
			function (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null) {
				if ($this->failSave === true) {
					throw new RuntimeException('database gone');
				}

				return $this->store->save((string)$schema, $object, $uuid, false);
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
}
