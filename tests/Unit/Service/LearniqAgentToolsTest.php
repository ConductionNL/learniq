<?php

/**
 * Unit tests for LearniqAgentTools.
 *
 * Pins the governance of the agent tools: the action matrix is asked before
 * any read or write, every write goes through ObjectService with RBAC on (the
 * UI's own path, so a guard refusal reaches the agent unchanged), a grade is
 * only ever a concept, and the credential read returns exactly its closed
 * field list.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-every-write-tool-delegates-to-the-existing-guarded-path-and-cannot-bypass-a-gate-req-008
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Mcp\LearniqScannableServices;
use OCA\Learniq\Service\ActionAuthService;
use OCA\Learniq\Service\AgentToolAnswer;
use OCA\Learniq\Service\LearniqAgentTools;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Mcp\Attribute\McpTool;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * Tests for the curated agent tools.
 */
class LearniqAgentToolsTest extends TestCase {

	/**
	 * Objects by schema, as the caller may read them.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $objects = [];

	/**
	 * Every saveObject call: [schema, object, uuid, rbac].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>, 2: string|null, 3: bool}>
	 */
	private array $saves = [];

	/**
	 * Whether the action matrix allows the caller.
	 *
	 * @var bool
	 */
	private bool $allowed = true;

	/**
	 * When set, saveObject throws this message (a guard refusing).
	 *
	 * @var string|null
	 */
	private ?string $guardRefusal = null;

	/**
	 * Build the tools over in-memory objects.
	 *
	 * @return LearniqAgentTools
	 */
	private function tools(): LearniqAgentTools {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null): ?ObjectEntity {
				foreach ($this->objects[(string)$schema] ?? [] as $row) {
					if (($row['id'] ?? null) === $id) {
						return $this->entity(data: $row);
					}
				}

				return null;
			}
		);
		$objects->method('findAll')->willReturnCallback(
			function (array $config): array {
				$filters = $config['filters'];
				$schema  = $filters['schema'];
				unset($filters['register'], $filters['schema']);

				return array_values(
					array_filter(
						$this->objects[$schema] ?? [],
						static function (array $row) use ($filters): bool {
							foreach ($filters as $key => $want) {
								$have = ($row[$key] ?? null);
								if ((is_array($want) === true && in_array($have, $want, true) === false) || (is_array($want) === false && $have !== $want)) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null, bool $_rbac = true): ObjectEntity {
				if ($this->guardRefusal !== null) {
					throw new RuntimeException($this->guardRefusal);
				}

				$this->saves[] = [(string)$schema, $object, $uuid, $_rbac];
				return $this->entity(data: array_merge($object, ['id' => ($uuid ?? 'new-id')]));
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('teacher1');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$auth = $this->createMock(ActionAuthService::class);
		$auth->method('requireAction')->willReturnCallback(
			function (): void {
				if ($this->allowed === false) {
					throw new RuntimeException('forbidden');
				}
			}
		);

		$named = $this->createMock(IUser::class);
		$named->method('getDisplayName')->willReturn('Sam de Vries');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($named);

		return new LearniqAgentTools(objectService: $objects, userSession: $session, actionAuth: $auth, answer: new AgentToolAnswer(userManager: $users));
	}//end tools()

	/**
	 * An entity double serialising to the given data.
	 *
	 * @param array<string, mixed> $data The data.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * The scannable-services opt-in names the tool class and every tool declares scope and hints.
	 *
	 * @return void
	 */
	public function testToolsAreDeclaredWithScopeAndHints(): void {
		self::assertSame([LearniqAgentTools::class], (new LearniqScannableServices())->getScannableServiceClasses());

		$declared = [];
		foreach ((new ReflectionClass(LearniqAgentTools::class))->getMethods() as $method) {
			foreach ($method->getAttributes(McpTool::class) as $attribute) {
				$tool = $attribute->newInstance();
				$declared[$tool->name] = [$tool->scope, $tool->readOnlyHint, $tool->subject !== null, $tool->action !== null];
			}
		}

		self::assertSame(
			[
				'enrolLearner'            => ['create', false, true, true],
				'recordAttendance'        => ['create', false, true, true],
				'gradeSubmission'         => ['create', false, true, true],
				'listExpiringCredentials' => ['read', true, true, true],
			],
			$declared
		);
	}//end testToolsAreDeclaredWithScopeAndHints()

	/**
	 * Without the action right nothing is read or written.
	 *
	 * @return void
	 */
	public function testActionMatrixGatesBeforeAnyWrite(): void {
		$this->allowed = false;
		$this->objects = ['course' => [['id' => 'c1']], 'session' => [['id' => 's1']]];
		$tools = $this->tools();

		self::assertFalse($tools->enrolLearner(learnerId: 'pupil1', courseId: 'c1')['ok']);
		self::assertFalse($tools->recordAttendance(sessionId: 's1', learnerId: 'pupil1', status: 'present')['ok']);
		self::assertFalse($tools->gradeSubmission(submissionId: 'sub1', value: 7.5)['ok']);
		self::assertSame([], $this->saves);
	}//end testActionMatrixGatesBeforeAnyWrite()

	/**
	 * Enrolment writes a pending enrolment with RBAC on, and a second call returns the open one.
	 *
	 * @return void
	 */
	public function testEnrolIsPendingAndIdempotent(): void {
		$this->objects = ['course' => [['id' => 'c1', 'tenant_id' => 't1']]];
		$tools = $this->tools();

		$first = $tools->enrolLearner(learnerId: 'pupil1', courseId: 'c1', reason: 'Renewal');
		self::assertTrue($first['ok']);
		self::assertSame(['enrolment', true], [$this->saves[0][0], $this->saves[0][3]]);
		self::assertSame('pending', $this->saves[0][1]['lifecycle']);
		self::assertStringContainsString('learniq.enrolLearner', $this->saves[0][1]['reason']);

		$this->objects['enrolment'] = [['id' => 'e1', 'learnerId' => 'pupil1', 'courseId' => 'c1', 'lifecycle' => 'active']];
		$again = $tools->enrolLearner(learnerId: 'pupil1', courseId: 'c1');
		self::assertSame(['ok' => true, 'enrolmentId' => 'e1', 'created' => false], $again);
		self::assertCount(1, $this->saves);
	}//end testEnrolIsPendingAndIdempotent()

	/**
	 * Attendance corrects an existing record instead of adding a second one.
	 *
	 * @return void
	 */
	public function testAttendanceUpsertsAndRejectsUnknownStatus(): void {
		$this->objects = [
			'session'           => [['id' => 's1', 'cohortId' => 'k1', 'tenant_id' => 't1']],
			'attendance-record' => [['id' => 'a1', 'sessionId' => 's1', 'learnerId' => 'pupil1']],
		];
		$tools = $this->tools();

		self::assertFalse($tools->recordAttendance(sessionId: 's1', learnerId: 'pupil1', status: 'asleep')['ok']);
		$result = $tools->recordAttendance(sessionId: 's1', learnerId: 'pupil1', status: 'absent-excused', reason: 'Sick');

		self::assertTrue($result['ok']);
		self::assertSame('a1', $this->saves[0][2], 'the existing record is updated');
		self::assertSame('teacher1', $this->saves[0][1]['markedBy']);
		self::assertSame('k1', $this->saves[0][1]['cohortId']);
	}//end testAttendanceUpsertsAndRejectsUnknownStatus()

	/**
	 * A proposed grade is a concept; a guard refusal reaches the agent as is and nothing is saved.
	 *
	 * @return void
	 */
	public function testGradeIsAConceptAndGuardRefusalsPassThrough(): void {
		$this->objects = [
			'submission' => [['id' => 'sub1', 'assignmentId' => 'as1', 'learnerIds' => ['pupil1'], 'tenant_id' => 't1']],
			'assignment' => [['id' => 'as1', 'curriculumPlanId' => 'p1', 'curriculumPlanComponentId' => 'comp1', 'gradeScaleId' => 'g1']],
		];

		$result = $this->tools()->gradeSubmission(submissionId: 'sub1', value: 7.5, comment: 'Good structure');
		self::assertTrue($result['ok']);
		self::assertSame('concept', $this->saves[0][1]['lifecycle']);
		self::assertSame('assignment-submission', $this->saves[0][1]['sourceKind']);
		self::assertSame('pupil1', $this->saves[0][1]['learnerId']);
		self::assertTrue($this->saves[0][3], 'written with RBAC on');

		$this->saves        = [];
		$this->guardRefusal = 'The report period is locked';
		$refused = $this->tools()->gradeSubmission(submissionId: 'sub1', value: 6.0);
		self::assertSame(['ok' => false, 'error' => ['code' => 'refused', 'message' => 'The report period is locked']], $refused);
		self::assertSame([], $this->saves);
	}//end testGradeIsAConceptAndGuardRefusalsPassThrough()

	/**
	 * The credential read returns exactly the closed field list, only for credentials expiring before the date.
	 *
	 * @return void
	 */
	public function testExpiringCredentialsAreAClosedProjection(): void {
		$this->objects = [
			'credential' => [
				['id' => 'cr1', 'learnerId' => 'pupil1', 'courseId' => 'c1', 'expiresAt' => '2026-11-01', 'lifecycle' => 'issued', 'signature' => 'secret', 'openbadges3Payload' => ['x' => 1]],
				['id' => 'cr2', 'learnerId' => 'pupil2', 'courseId' => 'c1', 'expiresAt' => '2027-06-01', 'lifecycle' => 'issued'],
			],
			'course'     => [['id' => 'c1', 'title' => 'BHV', 'renewalCourseSlug' => 'bhv-herhaling']],
		];

		$result = $this->tools()->listExpiringCredentials(before: '2026-12-31');

		self::assertTrue($result['ok']);
		self::assertCount(1, $result['credentials']);
		self::assertSame(LearniqAgentTools::EXPIRING_FIELDS, array_keys($result['credentials'][0]));
		self::assertSame('Sam de Vries', $result['credentials'][0]['learnerDisplayName']);
		self::assertSame('bhv-herhaling', $result['credentials'][0]['renewalCourseSlug']);
	}//end testExpiringCredentialsAreAClosedProjection()
}//end class
