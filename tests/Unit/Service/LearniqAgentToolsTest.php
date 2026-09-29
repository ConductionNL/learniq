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
use OCA\Learniq\Service\AssignmentGradePlan;
use OCA\Learniq\Service\CredentialLearner;
use OCA\Learniq\Service\LearnerRefResolver;
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
	 * How many find/findAll calls the tools made.
	 *
	 * @var int
	 */
	private int $reads = 0;

	/**
	 * Build the tools over in-memory objects.
	 *
	 * @return LearniqAgentTools
	 */
	private function tools(): LearniqAgentTools {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null): ?ObjectEntity {
				$this->reads++;
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
				$this->reads++;
				$filters = $config['filters'];
				$schema  = $filters['schema'];
				unset($filters['register'], $filters['schema']);

				// Honour paging the way OpenRegister does, so a tool that reads one
				// page and treats it as the whole set is caught here.
				$matches = array_values(
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

				return array_slice($matches, (int)($config['offset'] ?? 0), $config['limit'] ?? null);
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

		return new LearniqAgentTools(objectService: $objects, userSession: $session, actionAuth: $auth, answer: new AgentToolAnswer(userManager: $users), learners: new CredentialLearner(profiles: new LearnerRefResolver(objectService: $objects)), gradePlan: new AssignmentGradePlan(objectService: $objects));
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
		self::assertSame(0, $this->reads, 'The matrix refuses before any object is read.');
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
		self::assertStringContainsString('learniq.recordAttendance', $this->saves[0][1]['reason']);
	}//end testAttendanceUpsertsAndRejectsUnknownStatus()

	/**
	 * A proposed grade is a concept; a guard refusal reaches the agent as is and nothing is saved.
	 *
	 * @return void
	 */
	public function testGradeIsAConceptAndGuardRefusalsPassThrough(): void {
		$this->objects = [
			'submission'      => [['id' => 'sub1', 'assignmentId' => 'as1', 'learnerIds' => ['pupil1'], 'tenant_id' => 't1']],
			// The real Assignment shape: no curriculumPlanId or gradeScaleId (the
			// schema declares neither and OpenRegister drops them); the plan
			// hangs off the course.
			'assignment'      => [['id' => 'as1', 'courseId' => 'c1', 'curriculumPlanComponentId' => 'comp1']],
			'course'          => [['id' => 'c1', 'curriculumPlanId' => 'p1']],
			'curriculum-plan' => [['id' => 'p1', 'gradeScaleId' => 'g1']],
		];

		$result = $this->tools()->gradeSubmission(submissionId: 'sub1', value: 7.5, comment: 'Good structure');
		self::assertTrue($result['ok']);
		self::assertSame('p1', $this->saves[0][1]['curriculumPlanId'], 'the plan comes from the course');
		self::assertSame('g1', $this->saves[0][1]['gradeScaleId'], 'the scale comes from the plan');
		self::assertSame('c1', $this->saves[0][1]['courseId']);
		self::assertSame('concept', $this->saves[0][1]['lifecycle']);
		self::assertSame('assignment-submission', $this->saves[0][1]['sourceKind']);
		self::assertSame('pupil1', $this->saves[0][1]['learnerId']);
		self::assertTrue($this->saves[0][3], 'written with RBAC on');
		self::assertSame('teacher1', $this->saves[0][1]['grader'], 'REQ-010: the grader is the calling user.');
		self::assertStringContainsString('Good structure', $this->saves[0][1]['comment']);
		self::assertStringContainsString('learniq.gradeSubmission', $this->saves[0][1]['comment'], 'REQ-010: the comment names the tool.');

		$this->saves        = [];
		$this->guardRefusal = 'The report period is locked';
		$refused = $this->tools()->gradeSubmission(submissionId: 'sub1', value: 6.0);
		self::assertSame(['ok' => false, 'error' => ['code' => 'refused', 'message' => 'The report period is locked']], $refused);
		self::assertSame([], $this->saves);
	}//end testGradeIsAConceptAndGuardRefusalsPassThrough()

	/**
	 * An assignment whose course has no curriculum plan is refused, and nothing is written.
	 *
	 * Found live on :8080 (2026-09-29): with the plan read off the assignment,
	 * where the schema cannot hold it, every call was refused like this, even for
	 * a fully linked assignment.
	 *
	 * @return void
	 */
	public function testGradeWithoutACoursePlanIsRefused(): void {
		$this->objects = [
			'submission' => [['id' => 'sub1', 'assignmentId' => 'as1', 'learnerIds' => ['pupil1']]],
			'assignment' => [['id' => 'as1', 'courseId' => 'c1', 'curriculumPlanComponentId' => 'comp1']],
			'course'     => [['id' => 'c1']],
		];

		$result = $this->tools()->gradeSubmission(submissionId: 'sub1', value: 7.5);

		self::assertFalse($result['ok']);
		self::assertSame('invalid', $result['error']['code']);
		self::assertSame([], $this->saves);
	}//end testGradeWithoutACoursePlanIsRefused()

	/**
	 * The credential read returns exactly the closed field list, only for credentials expiring before the date.
	 *
	 * @return void
	 */
	public function testExpiringCredentialsAreAClosedProjection(): void {
		$this->objects = [
			'credential' => [
				['id' => 'cr1', 'learnerId' => '9d2c4e6a-1b3f-4a5c-8e7d-6f5a4b3c2d1e', 'learnerUserId' => 'pupil1', 'courseId' => 'c1', 'expiresAt' => '2026-11-01', 'lifecycle' => 'issued', 'signature' => 'secret', 'openbadges3Payload' => ['x' => 1]],
				['id' => 'cr2', 'learnerId' => 'pupil2', 'courseId' => 'c1', 'expiresAt' => '2027-06-01', 'lifecycle' => 'issued'],
			],
			'course'     => [['id' => 'c1', 'title' => 'BHV', 'renewalCourseSlug' => 'bhv-herhaling']],
		];

		$result = $this->tools()->listExpiringCredentials(before: '2026-12-31');

		self::assertTrue($result['ok']);
		self::assertCount(1, $result['credentials']);
		self::assertSame(LearniqAgentTools::EXPIRING_FIELDS, array_keys($result['credentials'][0]));
		self::assertSame('pupil1', $result['credentials'][0]['learnerId'], 'The user id enrolLearner takes, not the profile uuid.');
		self::assertSame('Sam de Vries', $result['credentials'][0]['learnerDisplayName']);
		self::assertSame('bhv-herhaling', $result['credentials'][0]['renewalCourseSlug']);
		self::assertFalse($result['truncated']);
	}//end testExpiringCredentialsAreAClosedProjection()

	/**
	 * A certificate past the first page of issued credentials is still found.
	 *
	 * Most issued credentials never expire (expiresAt null), so the expiring
	 * ones can sit anywhere in the list. One page of 200 used to be read as
	 * the whole set.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-the-expiring-credentials-read-is-a-closed-minimised-projection-req-011
	 */
	public function testExpiringCredentialsPastTheFirstPageAreFound(): void {
		$credentials = [];
		for ($i = 0; $i < 250; $i++) {
			$credentials[] = ['id' => 'forever' . $i, 'learnerId' => 'p' . $i, 'courseId' => 'c1', 'expiresAt' => null, 'lifecycle' => 'issued'];
		}

		$credentials[] = ['id' => 'late-row', 'learnerId' => 'pupil1', 'courseId' => 'c1', 'expiresAt' => '2026-11-01', 'lifecycle' => 'issued'];
		$this->objects = ['credential' => $credentials, 'course' => [['id' => 'c1', 'title' => 'BHV']]];

		$result = $this->tools()->listExpiringCredentials(before: '2026-12-31');

		self::assertTrue($result['ok']);
		self::assertSame(['late-row'], array_column($result['credentials'], 'credentialId'));
		self::assertFalse($result['truncated']);
	}//end testExpiringCredentialsPastTheFirstPageAreFound()

	/**
	 * More expiring certificates than one answer holds come back marked truncated.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-the-expiring-credentials-read-is-a-closed-minimised-projection-req-011
	 */
	public function testTooManyExpiringCredentialsSayTruncated(): void {
		$credentials = [];
		for ($i = 0; $i < 201; $i++) {
			$credentials[] = ['id' => 'cr' . $i, 'learnerId' => 'p' . $i, 'courseId' => 'c1', 'expiresAt' => '2026-11-01', 'lifecycle' => 'issued'];
		}

		$this->objects = ['credential' => $credentials, 'course' => [['id' => 'c1', 'title' => 'BHV']]];

		$result = $this->tools()->listExpiringCredentials(before: '2026-12-31');

		self::assertCount(200, $result['credentials']);
		self::assertTrue($result['truncated']);
	}//end testTooManyExpiringCredentialsSayTruncated()

	/**
	 * The app opts in to the attribute scan and registers no tool provider.
	 *
	 * A provider under `IMcpToolProvider::learniq` would shadow the tools
	 * OpenRegister derives from the register, so both halves are pinned.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-no-hand-written-mcp-tool-code-remains-in-learniq-req-006
	 */
	public function testTheAppRegistersScannableServicesAndNoToolProvider(): void {
		$root        = dirname(__DIR__, 3);
		$application = (string)file_get_contents($root . '/lib/AppInfo/Application.php');

		self::assertMatchesRegularExpression(
			"/registerServiceAlias\\(\\s*'OCA\\\\\\\\OpenRegister\\\\\\\\Mcp\\\\\\\\IMcpScannableServices::learniq',\\s*LearniqScannableServices::class\\s*\\)/",
			$application
		);
		self::assertStringNotContainsString("'mcpProvider'", $application);
		self::assertStringNotContainsString('IMcpToolProvider::', $application);

		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/lib', \FilesystemIterator::SKIP_DOTS));
		foreach ($files as $file) {
			if ($file->getExtension() !== 'php') {
				continue;
			}

			self::assertDoesNotMatchRegularExpression(
				'/implements[^{]*\\bIMcpToolProvider\\b/',
				(string)file_get_contents($file->getPathname()),
				$file->getPathname() . ' implements IMcpToolProvider.'
			);
		}
	}//end testTheAppRegistersScannableServicesAndNoToolProvider()
}//end class
