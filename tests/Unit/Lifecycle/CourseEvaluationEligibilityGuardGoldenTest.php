<?php

/**
 * Golden tests for CourseEvaluationEligibilityGuard.
 *
 * Pins every allowed and refused outcome of the course-evaluation submit
 * guard: the refusal reason, the exact log line per refusal, the invitation
 * query, the skipping of answered invitations and the field-by-field match
 * between the response and an open invitation.
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
 * @spec openspec/specs/course-evaluation/spec.md#requirement-eligibility-and-duplicate-submission-are-blocked-by-a-lifecycle-guard
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\Lifecycle\CourseEvaluationEligibilityGuard;
use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Golden tests for the course-evaluation submit guard.
 */
class CourseEvaluationEligibilityGuardGoldenTest extends TestCase {

	private const DENIAL = 'You have no open invitation for this course evaluation.';

	private const PREFIX = '[CourseEvaluationEligibilityGuard] ';

	/**
	 * Recorded log calls.
	 *
	 * @var array<int,array{0:string,1:string,2:array<string,mixed>}>
	 */
	public array $logs = [];

	/**
	 * Recorded findAll configs.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $queries = [];

	/**
	 * Run the guard.
	 *
	 * @param array<string,mixed> $object      The response.
	 * @param string|null         $caller      Session uid, null for none.
	 * @param array<int,mixed>    $invitations Rows findAll returns.
	 *
	 * @return GuardResult
	 */
	private function runGuard(array $object, ?string $caller, array $invitations): GuardResult {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($caller !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($caller);
		}

		$session->method('getUser')->willReturn($user);

		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config) use ($invitations): array {
				$this->queries[] = $config;
				return $invitations;
			}
		);

		$test = $this;
		$logger = new class($test) extends AbstractLogger {
			/**
			 * Constructor.
			 *
			 * @param CourseEvaluationEligibilityGuardGoldenTest $test Recorder.
			 */
			public function __construct(private CourseEvaluationEligibilityGuardGoldenTest $test) {
			}

			/**
			 * Record.
			 *
			 * @param mixed              $level   Level.
			 * @param string|\Stringable $message Message.
			 * @param array<mixed>       $context Context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->test->logs[] = [(string)$level, (string)$message, $context];
			}
		};

		return (new CourseEvaluationEligibilityGuard($session, $objects, $logger))->check($object, 'submit', 'ignored');
	}//end runGuard()

	/**
	 * A response as the answer page writes it.
	 *
	 * @param array<string,mixed> $overrides Field overrides.
	 *
	 * @return array<string,mixed>
	 */
	private static function response(array $overrides = []): array {
		return array_merge(
			['campaignId' => 'camp-1', 'tenant_id' => 't1', 'courseId' => 'c-1', 'cohortId' => 'h-1', 'academicYear' => '2026-2027', 'period' => 'P1'],
			$overrides
		);
	}//end response()

	/**
	 * An open invitation matching response().
	 *
	 * @param array<string,mixed> $overrides Field overrides.
	 *
	 * @return array<string,mixed>
	 */
	private static function invitation(array $overrides = []): array {
		return array_merge(
			['campaignId' => 'camp-1', 'learnerId' => 'anna', 'courseId' => 'c-1', 'cohortId' => 'h-1', 'academicYear' => '2026-2027', 'period' => 'P1', 'hasResponded' => false],
			$overrides
		);
	}//end invitation()

	/**
	 * A matching open invitation allows, with the tenant-scoped query and no log line.
	 *
	 * @return void
	 */
	public function testMatchingOpenInvitationIsAllowed(): void {
		$result = $this->runGuard(self::response(), 'anna', [self::invitation(['hasResponded' => true, 'courseId' => 'c-1']), self::invitation()]);

		self::assertTrue($result->isAllowed());
		self::assertSame([], $this->logs);
		self::assertSame(
			[
				[
					'filters' => ['campaignId' => 'camp-1', 'learnerId' => 'anna', 'tenant_id' => 't1', 'register' => 'learniq', 'schema' => 'evaluation-invitation'],
					'limit' => 50,
				],
			],
			$this->queries
		);
	}//end testMatchingOpenInvitationIsAllowed()

	/**
	 * Values compare as trimmed text; null, absent and '' are equal; entities are serialised; no tenant, no tenant filter.
	 *
	 * @return void
	 */
	public function testMatchingIsTextualAndEntitiesAreRead(): void {
		$entity = new class implements \JsonSerializable {
			/**
			 * Serialised invitation.
			 *
			 * @return array<string,mixed>
			 */
			public function jsonSerialize(): array {
				return ['courseId' => ' 7 ', 'cohortId' => null, 'academicYear' => '2026-2027', 'hasResponded' => 'yes'];
			}
		};

		$result = $this->runGuard(
			['campaignId' => 'camp-1', 'courseId' => 7, 'cohortId' => '', 'academicYear' => '2026-2027 ', 'period' => ['x'], 'teacherId' => '  '],
			'anna',
			[$entity]
		);

		self::assertTrue($result->isAllowed());
		self::assertSame(['campaignId' => 'camp-1', 'learnerId' => 'anna', 'register' => 'learniq', 'schema' => 'evaluation-invitation'], $this->queries[0]['filters']);
	}//end testMatchingIsTextualAndEntitiesAreRead()

	/**
	 * No campaign refuses with a warning and no query; no session user refuses with an info line and no query.
	 *
	 * @return void
	 */
	public function testNoCampaignOrNoCallerIsRefused(): void {
		$noCampaign = $this->runGuard(self::response(['campaignId' => '']), 'anna', [self::invitation()]);
		self::assertFalse($noCampaign->isAllowed());
		self::assertSame(self::DENIAL, $noCampaign->getMessage());
		self::assertSame([['warning', self::PREFIX . 'CourseEvaluationResponse has no campaignId; blocking submit.', []]], $this->logs);

		$this->logs = [];
		$noCaller = $this->runGuard(self::response(), null, [self::invitation()]);
		self::assertFalse($noCaller->isAllowed());
		self::assertSame([['info', self::PREFIX . 'No authenticated user in session; blocking submit.', []]], $this->logs);
		self::assertSame([], $this->queries);

		$absent = self::response();
		unset($absent['campaignId']);
		self::assertFalse($this->runGuard($absent, 'anna', [self::invitation()])->isAllowed());
	}//end testNoCampaignOrNoCallerIsRefused()

	/**
	 * No invitation, or only answered ones, refuses with the no-invitation line.
	 *
	 * @return void
	 */
	public function testNoOpenInvitationIsRefused(): void {
		$expected = [
			[
				'info',
				self::PREFIX . 'Caller {caller} has no eligible EvaluationInvitation for '
				. 'campaign {campaignId} (no invitation, or already responded); blocking submit (fail closed).',
				['caller' => 'anna', 'campaignId' => 'camp-1'],
			],
		];

		foreach ([[], [self::invitation(['hasResponded' => true])]] as $invitations) {
			$this->logs = [];
			$result = $this->runGuard(self::response(), 'anna', $invitations);
			self::assertFalse($result->isAllowed());
			self::assertSame(self::DENIAL, $result->getMessage());
			self::assertSame($expected, $this->logs);
		}
	}//end testNoOpenInvitationIsRefused()

	/**
	 * An open invitation that differs in a pinned field, or a response naming a teacher, refuses with the no-match line.
	 *
	 * @return void
	 */
	public function testMismatchIsRefused(): void {
		$expected = [
			[
				'info',
				self::PREFIX . 'The response submitted by {caller} for campaign {campaignId} does not '
				. 'match any of their open invitations (course, cohort, teacher, year or period differs); blocking submit.',
				['caller' => 'anna', 'campaignId' => 'camp-1'],
			],
		];

		$cases = [
			self::response(['courseId' => 'c-2']),
			self::response(['cohortId' => 'h-2']),
			self::response(['academicYear' => '2025-2026']),
			self::response(['period' => 'P2']),
			self::response(['teacherId' => 'mr-t']),
		];
		foreach ($cases as $response) {
			$this->logs = [];
			$result = $this->runGuard($response, 'anna', [self::invitation(), self::invitation(['courseId' => 'c-9'])]);
			self::assertFalse($result->isAllowed());
			self::assertSame($expected, $this->logs);
		}
	}//end testMismatchIsRefused()
}//end class
