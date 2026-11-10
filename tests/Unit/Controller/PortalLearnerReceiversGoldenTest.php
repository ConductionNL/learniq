<?php

/**
 * Golden tests for the five pupil-facing portal receivers.
 *
 * PortalAssessmentController, PortalCatalogueController,
 * PortalCheckInController, PortalSubmissionController and
 * PortalWorkGroupController receive a portaliq forward the same way: verify
 * the assertion, check the audience and learnerRef, resolve the pupil, run
 * the step, relay its outcome with the pupil's message, and turn any failure
 * into a bare 502 with one error log line. This pins every one of those
 * outcomes for each receiver.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Controller
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
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\PortalAssessmentController;
use OCA\Learniq\Controller\PortalCatalogueController;
use OCA\Learniq\Controller\PortalCheckInController;
use OCA\Learniq\Controller\PortalSubmissionController;
use OCA\Learniq\Controller\PortalWorkGroupController;
use OCA\Learniq\Portal\PortalAssertionVerifier;
use OCA\Learniq\Service\Catalogue\CatalogueSignUpService;
use OCA\Learniq\Service\CheckIn\CheckInService;
use OCA\Learniq\Service\Portal\PortalAttemptService;
use OCA\Learniq\Service\Portal\PortalLearner;
use OCA\Learniq\Service\Portal\PortalLearnerResolver;
use OCA\Learniq\Service\Portal\PortalOutcome;
use OCA\Learniq\Service\Portal\PortalSubmissionHandIn;
use OCA\Learniq\Service\WorkGroup\WorkGroupMembershipService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;

/**
 * Golden tests for the pupil-facing portal receivers.
 */
class PortalLearnerReceiversGoldenTest extends TestCase {

	/**
	 * Recorded log calls.
	 *
	 * @var array<int,array{0:string,1:string,2:array<string,mixed>}>
	 */
	public array $logs = [];

	/**
	 * Calls that reached the step's service.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $calls = [];

	/**
	 * Each receiver: controller, action, step service, its method, the parameter it forwards, the log line.
	 *
	 * @return array<string,array{0:class-string,1:string,2:class-string,3:string,4:string,5:string}>
	 */
	public static function receivers(): array {
		return [
			'assessment submit' => [
				PortalAssessmentController::class,
				'submit',
				PortalAttemptService::class,
				'submit',
				'attemptId',
				'[PortalAssessmentController] A portal assessment step failed: {msg}',
			],
			'catalogue withdraw' => [
				PortalCatalogueController::class,
				'withdraw',
				CatalogueSignUpService::class,
				'withdraw',
				'enrolmentId',
				'[PortalCatalogueController] A portal catalogue step failed: {msg}',
			],
			'check-in' => [
				PortalCheckInController::class,
				'checkIn',
				CheckInService::class,
				'checkIn',
				'windowId',
				'[PortalCheckInController] A portal check-in failed: {msg}',
			],
			'hand-in' => [
				PortalSubmissionController::class,
				'handIn',
				PortalSubmissionHandIn::class,
				'handIn',
				'submissionId',
				'[PortalSubmissionController] A portal hand-in failed: {msg}',
			],
			'work group join' => [
				PortalWorkGroupController::class,
				'join',
				WorkGroupMembershipService::class,
				'join',
				'groupId',
				'[PortalWorkGroupController] A portal work group step failed: {msg}',
			],
		];
	}//end receivers()

	/**
	 * Build one receiver.
	 *
	 * @param array<int,mixed>          $receiver The provider row.
	 * @param array<string,mixed>|null  $claims   What the verifier returns.
	 * @param array<string,mixed>       $params   Request parameters.
	 * @param PortalOutcome|\Throwable  $outcome  What the step returns or throws.
	 * @param PortalLearner|\Throwable|null $pupil What the resolver returns or throws.
	 *
	 * @return object
	 */
	private function receiver(array $receiver, ?array $claims, array $params, PortalOutcome|\Throwable $outcome, PortalLearner|\Throwable|null $pupil): object {
		[$class, , $serviceClass, $method] = $receiver;
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(static fn (string $name): string => $name === PortalAssertionVerifier::HEADER ? 'jwt-1' : '');
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => $params[$key] ?? $default);

		$verifier = $this->createMock(PortalAssertionVerifier::class);
		$verifier->method('verify')->willReturnCallback(
			function (string $jwt) use ($claims): ?array {
				self::assertSame('jwt-1', $jwt);
				return $claims;
			}
		);

		$learners = $this->createMock(PortalLearnerResolver::class);
		$learners->method('resolve')->willReturnCallback(
			function (string $learnerRef) use ($pupil): ?PortalLearner {
				$this->calls[] = ['resolve', $learnerRef];
				if ($pupil instanceof \Throwable) {
					throw $pupil;
				}

				return $pupil;
			}
		);

		$service = $this->createMock($serviceClass);
		$service->method($method)->willReturnCallback(
			function (...$args) use ($outcome): PortalOutcome {
				$this->calls[] = ['step', $args[0] instanceof PortalLearner ? $args[0]->profileRef : $args[0], $args[1] ?? null];
				if ($outcome instanceof \Throwable) {
					throw $outcome;
				}

				return $outcome;
			}
		);

		$args = [];
		foreach ((new ReflectionClass($class))->getConstructor()->getParameters() as $parameter) {
			$type = (string)$parameter->getType();
			$args[] = match (true) {
				$type === IRequest::class => $request,
				$type === PortalAssertionVerifier::class => $verifier,
				$type === PortalLearnerResolver::class => $learners,
				$type === LoggerInterface::class => $this->logger(),
				$type === $serviceClass => $service,
				str_ends_with($type, 'Messages') === true => $this->messages($type),
				default => $this->createMock($type),
			};
		}

		return new $class(...$args);
	}//end receiver()

	/**
	 * A messages double: `msg:<reason>:<uid or none>`.
	 *
	 * @param class-string $type The messages class.
	 *
	 * @return object
	 */
	private function messages(string $type): object {
		$messages = $this->createMock($type);
		$messages->method('message')->willReturnCallback(static fn (string $reason, ?IUser $user): string => 'msg:' . $reason . ':' . ($user?->getUID() ?? 'none'));

		return $messages;
	}//end messages()

	/**
	 * A logger that records into $this->logs.
	 *
	 * @return LoggerInterface
	 */
	private function logger(): LoggerInterface {
		$test = $this;
		return new class($test) extends AbstractLogger {
			/**
			 * Constructor.
			 *
			 * @param PortalLearnerReceiversGoldenTest $test Recorder.
			 */
			public function __construct(private PortalLearnerReceiversGoldenTest $test) {
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
	}//end logger()

	/**
	 * The pupil the resolver finds.
	 *
	 * @return PortalLearner
	 */
	private function pupil(): PortalLearner {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('pupil-1');

		return new PortalLearner(profileRef: 'lp-1', ncUserId: 'pupil-1', tenantId: 't1', user: $user);
	}//end pupil()

	/**
	 * Call the receiver's action.
	 *
	 * @param array<int,mixed> $receiver The provider row.
	 * @param object           $controller The receiver.
	 *
	 * @return JSONResponse
	 */
	private static function call(array $receiver, object $controller): JSONResponse {
		$action = $receiver[1];
		return $controller->$action();
	}//end call()

	/**
	 * No valid assertion: 401, throttled in the shared bucket, nothing resolved.
	 *
	 * @param class-string $class   Controller.
	 * @param string       $action  Action.
	 * @param class-string $service Step service.
	 * @param string       $method  Step method.
	 * @param string       $param   Forwarded parameter.
	 * @param string       $log     Failure log line.
	 *
	 * @return void
	 */
	#[DataProvider('receivers')]
	public function testNoAssertionIsUnauthorizedAndThrottled(string $class, string $action, string $service, string $method, string $param, string $log): void {
		$row = func_get_args();
		$response = self::call($row, $this->receiver($row, null, ['learnerRef' => 'lp-1'], new PortalOutcome(status: 200, body: []), $this->pupil()));

		self::assertSame(401, $response->getStatus());
		self::assertSame(['error' => 'unauthorized'], $response->getData());
		self::assertTrue($response->isThrottled());
		self::assertSame(['action' => 'learniq_portal_assertion'], $response->getThrottleMetadata());
		self::assertSame([], $this->calls);
		self::assertSame([], $this->logs);
	}//end testNoAssertionIsUnauthorizedAndThrottled()

	/**
	 * Another audience, no audience, or an empty / non-string / blank learnerRef: 403, nothing resolved.
	 *
	 * @param class-string $class   Controller.
	 * @param string       $action  Action.
	 * @param class-string $service Step service.
	 * @param string       $method  Step method.
	 * @param string       $param   Forwarded parameter.
	 * @param string       $log     Failure log line.
	 *
	 * @return void
	 */
	#[DataProvider('receivers')]
	public function testWrongAudienceOrNoLearnerIsForbidden(string $class, string $action, string $service, string $method, string $param, string $log): void {
		$row = func_get_args();
		$cases = [
			[['audience' => 'parent'], ['learnerRef' => 'lp-1']],
			[[], ['learnerRef' => 'lp-1']],
			[['audience' => 'student'], []],
			[['audience' => 'student'], ['learnerRef' => ['lp-1']]],
			[['audience' => 'student'], ['learnerRef' => '   ']],
		];
		foreach ($cases as [$claims, $params]) {
			$response = self::call($row, $this->receiver($row, $claims, $params, new PortalOutcome(status: 200, body: []), $this->pupil()));
			self::assertSame(403, $response->getStatus());
			self::assertSame(['error' => 'forbidden'], $response->getData());
			self::assertFalse($response->isThrottled());
		}

		self::assertSame([], $this->calls);
	}//end testWrongAudienceOrNoLearnerIsForbidden()

	/**
	 * A learnerRef without an account: 403 not_available with the no-account message, no step.
	 *
	 * @param class-string $class   Controller.
	 * @param string       $action  Action.
	 * @param class-string $service Step service.
	 * @param string       $method  Step method.
	 * @param string       $param   Forwarded parameter.
	 * @param string       $log     Failure log line.
	 *
	 * @return void
	 */
	#[DataProvider('receivers')]
	public function testNoAccountIsNotAvailable(string $class, string $action, string $service, string $method, string $param, string $log): void {
		$row = func_get_args();
		$response = self::call($row, $this->receiver($row, ['audience' => 'student'], ['learnerRef' => ' lp-9 '], new PortalOutcome(status: 200, body: []), null));

		self::assertSame(403, $response->getStatus());
		self::assertSame(['error' => 'not_available', 'message' => 'msg:no-account:none'], $response->getData());
		self::assertSame([['resolve', 'lp-9']], $this->calls);
	}//end testNoAccountIsNotAvailable()

	/**
	 * The step's outcome is relayed with the pupil's message for a reason, and as-is without one.
	 *
	 * @param class-string $class   Controller.
	 * @param string       $action  Action.
	 * @param class-string $service Step service.
	 * @param string       $method  Step method.
	 * @param string       $param   Forwarded parameter.
	 * @param string       $log     Failure log line.
	 *
	 * @return void
	 */
	#[DataProvider('receivers')]
	public function testOutcomeIsRelayed(string $class, string $action, string $service, string $method, string $param, string $log): void {
		$row = func_get_args();
		$params = ['learnerRef' => 'lp-1', $param => ' x-1 ', 'code' => 'C'];

		$refused = self::call($row, $this->receiver($row, ['audience' => 'student'], $params, new PortalOutcome(status: 409, body: ['error' => 'closed'], reason: 'closed'), $this->pupil()));
		self::assertSame(409, $refused->getStatus());
		self::assertSame(['error' => 'closed', 'message' => 'msg:closed:pupil-1'], $refused->getData());

		$done = self::call($row, $this->receiver($row, ['audience' => 'student'], $params, new PortalOutcome(status: 200, body: ['ok' => true]), $this->pupil()));
		self::assertSame(200, $done->getStatus());
		self::assertSame(['ok' => true], $done->getData());
		self::assertSame([], $this->logs);
		self::assertSame(['resolve', 'lp-1'], $this->calls[0]);
		self::assertSame('step', $this->calls[1][0]);
	}//end testOutcomeIsRelayed()

	/**
	 * A failing step or a failing lookup: a bare 502 and one error line with the exception.
	 *
	 * @param class-string $class   Controller.
	 * @param string       $action  Action.
	 * @param class-string $service Step service.
	 * @param string       $method  Step method.
	 * @param string       $param   Forwarded parameter.
	 * @param string       $log     Failure log line.
	 *
	 * @return void
	 */
	#[DataProvider('receivers')]
	public function testFailureIsABareBadGateway(string $class, string $action, string $service, string $method, string $param, string $log): void {
		$row = func_get_args();
		$params = ['learnerRef' => 'lp-1', $param => 'x-1', 'code' => 'C'];
		$exception = new RuntimeException('SQLSTATE secret');

		foreach ([[$exception, $this->pupil()], [new PortalOutcome(status: 200, body: []), $exception]] as [$outcome, $pupil]) {
			$this->logs = [];
			$response = self::call($row, $this->receiver($row, ['audience' => 'student'], $params, $outcome, $pupil));

			self::assertSame(502, $response->getStatus());
			self::assertSame(['error' => 'downstream_error'], $response->getData());
			self::assertSame([['error', $log, ['msg' => 'SQLSTATE secret', 'exception' => $exception]]], $this->logs);
		}
	}//end testFailureIsABareBadGateway()
}//end class
