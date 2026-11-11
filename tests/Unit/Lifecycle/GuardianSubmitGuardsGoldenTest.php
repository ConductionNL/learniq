<?php

/**
 * Golden tests for the two guardian-or-self submit guards.
 *
 * ConferenceSignupGuardianGuard and SubjectChoiceConsentGuard apply one rule
 * to two schemas. This pins, for both, every allowed and refused outcome,
 * the refusal reason, the exact log line per refusal, and the profile query.
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
 * @spec openspec/specs/school-structure/spec.md#requirement-guardian-consent-gates-a-minor-s-subject-choice-submission
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\Lifecycle\ConferenceSignupGuardianGuard;
use OCA\Learniq\Lifecycle\SubjectChoiceConsentGuard;
use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Golden tests for both guardian-or-self submit guards.
 */
class GuardianSubmitGuardsGoldenTest extends TestCase {

	/**
	 * Recorded log calls.
	 *
	 * @var array<int,array{0:string,1:string,2:array<string,mixed>}>
	 */
	private array $logs = [];

	/**
	 * Recorded findAll configs.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $queries = [];

	/**
	 * Both guards with their labels and refusal reason.
	 *
	 * @return array<string,array{0:string,1:string,2:string,3:string}>
	 */
	public static function guards(): array {
		return [
			'conference signup' => [
				ConferenceSignupGuardianGuard::class,
				'[ConferenceSignupGuardianGuard] ',
				'ConferenceSignup',
				'Only a guardian of this learner, or the adult learner, can sign up for this conference.',
			],
			'subject choice' => [
				SubjectChoiceConsentGuard::class,
				'[SubjectChoiceConsentGuard] ',
				'SubjectChoice',
				'Only a guardian of this learner, or the adult learner, can submit this subject choice.',
			],
		];
	}//end guards()

	/**
	 * Run one guard with a caller and profile rows.
	 *
	 * @param string                $class    Guard class.
	 * @param array<string,mixed>   $object   Object under transition.
	 * @param string|null           $caller   Caller uid, or null for no session user.
	 * @param array<int,mixed>      $profiles Rows findAll returns.
	 *
	 * @return GuardResult
	 */
	private function runGuard(string $class, array $object, ?string $caller, array $profiles): GuardResult {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($caller !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($caller);
		}

		$session->method('getUser')->willReturn($user);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($profiles): array {
				$this->queries[] = $config;
				return $profiles;
			}
		);

		$test = $this;
		$logger = new class($test) extends AbstractLogger {
			/**
			 * Constructor.
			 *
			 * @param GuardianSubmitGuardsGoldenTest $test Recorder.
			 */
			public function __construct(private GuardianSubmitGuardsGoldenTest $test) {
			}

			/**
			 * Record a log call.
			 *
			 * @param mixed              $level   Level.
			 * @param string|\Stringable $message Message.
			 * @param array<mixed>       $context Context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->test->record((string)$level, (string)$message, $context);
			}
		};

		$guard = new $class($session, $objectService, $logger);

		return $guard->check($object, 'submit', 'ignored-uid');
	}//end runGuard()

	/**
	 * Record a log call (called by the logger double).
	 *
	 * @param string              $level   Level.
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context.
	 *
	 * @return void
	 */
	public function record(string $level, string $message, array $context): void {
		$this->logs[] = [$level, $message, $context];
	}//end record()

	/**
	 * A linked guardian passes, with the tenant-scoped profile query and no log line.
	 *
	 * @param string $class  Guard class.
	 * @param string $prefix Log prefix.
	 * @param string $label  Schema label.
	 * @param string $denial Refusal reason.
	 *
	 * @return void
	 */
	#[DataProvider('guards')]
	public function testLinkedGuardianIsAllowed(string $class, string $prefix, string $label, string $denial): void {
		$result = $this->runGuard($class, ['learnerId' => 'kid', 'tenant_id' => 't1'], 'mum', [['ncUserId' => 'kid', 'parentIds' => ['dad', 'mum']]]);

		self::assertTrue($result->isAllowed());
		self::assertSame([], $this->logs);
		self::assertSame(
			[
				[
					'filters' => ['ncUserId' => 'kid', 'tenant_id' => 't1', 'register' => 'learniq', 'schema' => 'learner-profile'],
					'limit' => 1,
				],
			],
			$this->queries
		);
	}//end testLinkedGuardianIsAllowed()

	/**
	 * The learner themself passes; an object row is serialised; no tenant means no tenant filter.
	 *
	 * @param string $class  Guard class.
	 * @param string $prefix Log prefix.
	 * @param string $label  Schema label.
	 * @param string $denial Refusal reason.
	 *
	 * @return void
	 */
	#[DataProvider('guards')]
	public function testSelfIsAllowedFromSerialisedRow(string $class, string $prefix, string $label, string $denial): void {
		$row = new class implements \JsonSerializable {
			/**
			 * Serialised profile.
			 *
			 * @return array<string,mixed>
			 */
			public function jsonSerialize(): array {
				return ['ncUserId' => 'adult', 'parentIds' => 'not-a-list'];
			}
		};

		$result = $this->runGuard($class, ['learnerId' => 'adult'], 'adult', [$row]);

		self::assertTrue($result->isAllowed());
		self::assertSame([], $this->logs);
		self::assertSame(['ncUserId' => 'adult', 'register' => 'learniq', 'schema' => 'learner-profile'], $this->queries[0]['filters']);
	}//end testSelfIsAllowedFromSerialisedRow()

	/**
	 * An unrelated caller is refused with the reason and an info line; a non-list parentIds refuses.
	 *
	 * @param string $class  Guard class.
	 * @param string $prefix Log prefix.
	 * @param string $label  Schema label.
	 * @param string $denial Refusal reason.
	 *
	 * @return void
	 */
	#[DataProvider('guards')]
	public function testUnrelatedCallerIsRefused(string $class, string $prefix, string $label, string $denial): void {
		$result = $this->runGuard($class, ['learnerId' => 'kid'], 'stranger', [['ncUserId' => 'kid', 'parentIds' => ['mum']]]);

		self::assertFalse($result->isAllowed());
		self::assertSame($denial, $result->getMessage());
		self::assertSame(
			[
				[
					'info',
					$prefix . 'Caller {caller} is not a linked guardian of learner {learnerId} and is not the learner; blocking submit.',
					['caller' => 'stranger', 'learnerId' => 'kid'],
				],
			],
			$this->logs
		);

		$this->logs = [];
		$stringParents = $this->runGuard($class, ['learnerId' => 'kid'], 'mum', [['ncUserId' => 'kid', 'parentIds' => 'mum']]);
		self::assertFalse($stringParents->isAllowed());

		$emptySelf = $this->runGuard($class, ['learnerId' => 'kid'], '', [['ncUserId' => '', 'parentIds' => []]]);
		self::assertFalse($emptySelf->isAllowed());
	}//end testUnrelatedCallerIsRefused()

	/**
	 * No learnerId refuses with a warning and no query.
	 *
	 * @param string $class  Guard class.
	 * @param string $prefix Log prefix.
	 * @param string $label  Schema label.
	 * @param string $denial Refusal reason.
	 *
	 * @return void
	 */
	#[DataProvider('guards')]
	public function testMissingLearnerIsRefused(string $class, string $prefix, string $label, string $denial): void {
		$result = $this->runGuard($class, ['tenant_id' => 't1'], 'mum', []);

		self::assertFalse($result->isAllowed());
		self::assertSame($denial, $result->getMessage());
		self::assertSame([['warning', $prefix . $label . ' has no learnerId; blocking submit.', []]], $this->logs);
		self::assertSame([], $this->queries);
	}//end testMissingLearnerIsRefused()

	/**
	 * No session user refuses with an info line and no query.
	 *
	 * @param string $class  Guard class.
	 * @param string $prefix Log prefix.
	 * @param string $label  Schema label.
	 * @param string $denial Refusal reason.
	 *
	 * @return void
	 */
	#[DataProvider('guards')]
	public function testNoSessionUserIsRefused(string $class, string $prefix, string $label, string $denial): void {
		$result = $this->runGuard($class, ['learnerId' => 'kid'], null, []);

		self::assertFalse($result->isAllowed());
		self::assertSame($denial, $result->getMessage());
		self::assertSame([['info', $prefix . 'No authenticated user in session; blocking submit.', []]], $this->logs);
		self::assertSame([], $this->queries);
	}//end testNoSessionUserIsRefused()

	/**
	 * No profile refuses with a warning naming the learner.
	 *
	 * @param string $class  Guard class.
	 * @param string $prefix Log prefix.
	 * @param string $label  Schema label.
	 * @param string $denial Refusal reason.
	 *
	 * @return void
	 */
	#[DataProvider('guards')]
	public function testMissingProfileIsRefused(string $class, string $prefix, string $label, string $denial): void {
		$result = $this->runGuard($class, ['learnerId' => 'kid', 'tenant_id' => ''], 'mum', []);

		self::assertFalse($result->isAllowed());
		self::assertSame($denial, $result->getMessage());
		self::assertSame(
			[['warning', $prefix . 'No LearnerProfile found for learnerId {learnerId}; blocking submit (fail closed).', ['learnerId' => 'kid']]],
			$this->logs
		);
		self::assertSame(['ncUserId' => 'kid', 'register' => 'learniq', 'schema' => 'learner-profile'], $this->queries[0]['filters']);
	}//end testMissingProfileIsRefused()
}//end class
