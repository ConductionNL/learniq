<?php

/**
 * The assessor's practical exam form: assigned only, stamped by the server, resumable.
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
 * @spec openspec/changes/practical-exam-assessment/specs/exam-board/spec.md#requirement-a-practical-exam-assessment-records-who-assessed-and-how-sure-the-school-is
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Portal;

use OCA\Learniq\Service\Portal\PortalPracticalExamAssessment;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Ruud assesses Daan at the 9.00 sitting; another assessor's sitting grants him nothing.
 *
 * @covers \OCA\Learniq\Service\Portal\PortalPracticalExamAssessment
 * @uses   \OCA\Learniq\Service\Portal\PortalOutcome
 */
class PortalPracticalExamAssessmentTest extends TestCase {

	private const RUUD = 'ee010008-0000-4000-8000-000000000701';

	private const DAAN = 'ee010008-0000-4000-8000-000000000501';

	private const SITTING = 'ee010008-0000-4000-8000-000000000601';

	private const OTHER_SITTING = 'ee010008-0000-4000-8000-000000000602';

	private const TENANT = 'ee010008-0000-4000-8000-000000000901';

	private RegisterFaithfulStore $store;

	private string $floor = 'basic';

	private bool $failSave = false;

	protected function setUp(): void {
		parent::setUp();
		$this->store = new RegisterFaithfulStore();
		$this->store->rows = [
			'external-assessor' => [['id' => self::RUUD, 'givenName' => 'Ruud', 'familyName' => 'Smit', 'tenant_id' => self::TENANT]],
			'exam-assessor-assignment' => [
				['id' => 'as-1', 'examSittingId' => self::SITTING, 'externalAssessorId' => self::RUUD, 'learnerRef' => self::DAAN, 'role' => 'first', 'lifecycle' => 'assigned', 'tenant_id' => self::TENANT],
				['id' => 'as-2', 'examSittingId' => self::OTHER_SITTING, 'externalAssessorId' => self::RUUD, 'learnerRef' => self::DAAN, 'role' => 'first', 'lifecycle' => 'withdrawn', 'tenant_id' => self::TENANT],
			],
			'practical-exam-assessment' => [],
		];
	}

	private function service(): PortalPracticalExamAssessment {
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

		return new PortalPracticalExamAssessment(objectService: $objects, appConfig: $config, logger: new NullLogger());
	}

	/**
	 * The stored assessments.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function stored(): array {
		return array_values($this->store->rows['practical-exam-assessment']);
	}

	public function testAnInvitedAssessorsAssessmentNamesHimAndRecordsBasic(): void {
		$outcome = $this->service()->submit(
			assessorRef: self::RUUD,
			trust: 'low',
			body: [
				'examSittingId' => self::SITTING,
				'learnerRef' => self::DAAN,
				'criteria' => [['criterion' => 'veilig werken', 'score' => 3]],
				'outcome' => 'pass',
				// The server's fields, sent anyway: replaced.
				'assessorName' => 'Somebody else',
				'assuranceLevel' => 'high',
			]
		);

		self::assertSame(201, $outcome->status);
		self::assertSame('basic', $outcome->body['assuranceLevel']);
		self::assertSame('submitted', $outcome->body['lifecycle']);
		$row = $this->stored()[0];
		self::assertSame(self::RUUD, $row['assessorId']);
		self::assertSame('Ruud Smit', $row['assessorName']);
		self::assertSame('basic', $row['assuranceLevel']);
		self::assertSame(self::TENANT, $row['tenant_id']);
		self::assertSame('pass', $row['outcome']);
	}

	public function testASittingWithoutALiveAssignmentForHimIsRefused(): void {
		foreach ([self::OTHER_SITTING, 'ee010008-0000-4000-8000-000000000699'] as $sitting) {
			$outcome = $this->service()->submit(assessorRef: self::RUUD, trust: 'low', body: ['examSittingId' => $sitting, 'learnerRef' => self::DAAN]);
			self::assertSame(403, $outcome->status, $sitting);
			self::assertSame('not_assigned', $outcome->body['error']);
			self::assertSame(403, $this->service()->draft(assessorRef: self::RUUD, sittingId: $sitting, learnerRef: self::DAAN)->status);
		}

		self::assertSame([], $this->stored());
	}

	public function testADraftKeepsTheCriteriaScoredAndAReconnectResumesIt(): void {
		$three = [['criterion' => 'a', 'score' => 2], ['criterion' => 'b', 'score' => 3], ['criterion' => 'c', 'score' => 1]];
		$first = $this->service()->submit(
			assessorRef: self::RUUD,
			trust: 'low',
			body: ['examSittingId' => self::SITTING, 'learnerRef' => self::DAAN, 'criteria' => $three, 'lifecycle' => 'draft']
		);
		self::assertSame(201, $first->status);
		self::assertSame('draft', $first->body['lifecycle']);

		// The connection dropped; he returns.
		$resumed = $this->service()->draft(assessorRef: self::RUUD, sittingId: self::SITTING, learnerRef: self::DAAN);
		self::assertSame(200, $resumed->status);
		self::assertSame($three, $resumed->body['draft']['criteria']);
		self::assertSame($first->body['assessmentId'], $resumed->body['draft']['assessmentId']);

		// The next step carries the same record on rather than starting a second one.
		$five = array_merge($three, [['criterion' => 'd', 'score' => 3], ['criterion' => 'e', 'score' => 2]]);
		$last = $this->service()->submit(
			assessorRef: self::RUUD,
			trust: 'low',
			body: ['examSittingId' => self::SITTING, 'learnerRef' => self::DAAN, 'criteria' => $five, 'outcome' => 'pass']
		);
		self::assertSame(200, $last->status);
		self::assertSame($first->body['assessmentId'], $last->body['assessmentId']);
		self::assertCount(1, $this->stored());
		self::assertSame('submitted', $this->stored()[0]['lifecycle']);
		self::assertSame($five, $this->stored()[0]['criteria']);

		// Submitted is final: no draft to resume, and no second submit.
		self::assertNull($this->service()->draft(assessorRef: self::RUUD, sittingId: self::SITTING, learnerRef: self::DAAN)->body['draft']);
		self::assertSame(409, $this->service()->submit(assessorRef: self::RUUD, trust: 'low', body: ['examSittingId' => self::SITTING, 'learnerRef' => self::DAAN])->status);
	}

	public function testASchoolFloorAboveTheSessionIsRefusedBeforeAnyRead(): void {
		$this->floor = 'substantial';

		$outcome = $this->service()->submit(assessorRef: self::RUUD, trust: 'low', body: ['examSittingId' => self::SITTING, 'learnerRef' => self::DAAN]);

		self::assertSame(403, $outcome->status);
		self::assertSame(['error' => 'assurance_too_low', 'required' => 'substantial'], $outcome->body);
		self::assertSame('substantial', $this->service()->submit(assessorRef: self::RUUD, trust: 'substantial', body: ['examSittingId' => self::SITTING, 'learnerRef' => self::DAAN])->body['assuranceLevel']);
	}

	public function testAnIncompleteRequestAnUnknownAssessorAndAFailedWrite(): void {
		self::assertSame(422, $this->service()->submit(assessorRef: self::RUUD, trust: 'low', body: ['examSittingId' => self::SITTING])->status);
		self::assertSame(422, $this->service()->draft(assessorRef: self::RUUD, sittingId: '', learnerRef: self::DAAN)->status);
		self::assertSame(
			'unknown_assessor',
			$this->service()->submit(assessorRef: 'ee010008-0000-4000-8000-000000000799', trust: 'low', body: ['examSittingId' => self::SITTING, 'learnerRef' => self::DAAN])->body['error']
		);

		$this->failSave = true;
		self::assertSame(502, $this->service()->submit(assessorRef: self::RUUD, trust: 'low', body: ['examSittingId' => self::SITTING, 'learnerRef' => self::DAAN])->status);
	}
}
