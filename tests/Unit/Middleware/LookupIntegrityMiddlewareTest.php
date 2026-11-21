<?php

/**
 * Tests for the lookup-integrity translation of a lookup that matched more than one object.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Middleware
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

namespace OCA\Learniq\Tests\Unit\Middleware;

use OCA\Learniq\Controller\CheckInCodeController;
use OCA\Learniq\Controller\ComplianceRollupController;
use OCA\Learniq\Controller\CredentialEuropassController;
use OCA\Learniq\Controller\LessonNextStepController;
use OCA\Learniq\Controller\LessonReleaseController;
use OCA\Learniq\Controller\SubmissionMarkController;
use OCA\Learniq\Middleware\LookupIntegrityMiddleware;
use OCA\Learniq\Service\DashboardRoleService;
use OCA\Learniq\Tests\Support\CapturingLogger;
use OCA\Learniq\Tests\Support\ContractImplementations;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

/**
 * A lookup by id that matches more than one object reaches the middleware, which answers a generic 500.
 *
 * @covers \OCA\Learniq\Middleware\LookupIntegrityMiddleware
 * @covers \OCA\Learniq\Controller\CheckInCodeController
 * @covers \OCA\Learniq\Controller\ComplianceRollupController
 * @covers \OCA\Learniq\Controller\CredentialEuropassController
 * @covers \OCA\Learniq\Controller\LessonNextStepController
 * @covers \OCA\Learniq\Controller\LessonReleaseController
 * @covers \OCA\Learniq\Controller\SubmissionMarkController
 * @uses \OCA\Learniq\Service\StaffGroupCheck
 */
class LookupIntegrityMiddlewareTest extends TestCase {

	/**
	 * The fault OpenRegister raises, carrying object ids in its text.
	 *
	 * @return MultipleObjectsReturnedException
	 */
	private static function fault(): MultipleObjectsReturnedException {
		return new MultipleObjectsReturnedException('2 rows for uuid obj-1: row-7, row-9');
	}//end fault()

	/**
	 * Every endpoint whose lookup can match more than one object, with its call.
	 *
	 * @return array<string, array{0: class-string<Controller>, 1: string, 2: array<int, string>}>
	 */
	public static function endpoints(): array {
		return [
			'check-in code' => [CheckInCodeController::class, 'code', ['win-1']],
			'assign regulation' => [ComplianceRollupController::class, 'assignRegulation', ['reg-1']],
			'europass download' => [CredentialEuropassController::class, 'download', ['cred-1']],
			'europass create' => [CredentialEuropassController::class, 'create', ['cred-1']],
			'lesson next step' => [LessonNextStepController::class, 'nextStep', ['les-1']],
			'course preview' => [LessonNextStepController::class, 'coursePreview', ['co-1']],
			'lesson release status' => [LessonReleaseController::class, 'status', ['les-1']],
			'assessment release status' => [LessonReleaseController::class, 'assessmentStatus', ['as-1']],
			'allocate marks' => [SubmissionMarkController::class, 'allocate', ['asg-1']],
			'submission marks' => [SubmissionMarkController::class, 'marks', ['sub-1']],
		];
	}//end endpoints()

	/**
	 * The endpoint lets the fault through, and the middleware answers 500 with a message that names no object.
	 *
	 * @param class-string<Controller> $class     The controller.
	 * @param string                   $method    The endpoint.
	 * @param array<int, string>       $arguments Its arguments.
	 *
	 * @return void
	 *
	 * @dataProvider endpoints
	 */
	public function testAnAmbiguousLookupIsAGeneric500(string $class, string $method, array $arguments): void {
		$caught = null;
		try {
			$this->controller(class: $class, find: self::fault())->$method(...$arguments);
		} catch (MultipleObjectsReturnedException $exception) {
			$caught = $exception;
		}

		self::assertNotNull($caught, $method . ' swallowed a lookup that matched more than one object');

		$logger   = new CapturingLogger();
		$request  = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn(['id' => 'obj-1', 'lessonId' => 'les-1', 'note' => 'free text', '_route' => 'x']);
		$response = (new LookupIntegrityMiddleware($request, $logger))->afterException($this->createMock(Controller::class), $method, $caught);

		self::assertSame(500, $response->getStatus());
		self::assertSame(['error' => LookupIntegrityMiddleware::MESSAGE], $response->getData());
		self::assertStringNotContainsString('row-', (string)json_encode($response->getData()));
		self::assertCount(1, $logger->records);
		self::assertSame('error', $logger->records[0]['level']);
		self::assertSame(['id' => 'obj-1', 'lessonId' => 'les-1'], $logger->records[0]['context']['ids']);
		self::assertSame($method, $logger->records[0]['context']['method']);
	}//end testAnAmbiguousLookupIsAGeneric500()

	/**
	 * Every other exception passes through untouched.
	 *
	 * @return void
	 */
	public function testOtherExceptionsAreRethrown(): void {
		$logger     = new CapturingLogger();
		$middleware = new LookupIntegrityMiddleware($this->createMock(IRequest::class), $logger);
		foreach ([new RuntimeException('x'), new DoesNotExistException('y')] as $exception) {
			try {
				$middleware->afterException($this->createMock(Controller::class), 'm', $exception);
				self::fail('translated ' . $exception::class);
			} catch (\Exception $rethrown) {
				self::assertSame($exception, $rethrown);
			}
		}

		self::assertSame([], $logger->records);
	}//end testOtherExceptionsAreRethrown()

	/**
	 * The controller with a double for every collaborator: a signed-in staff user, and an object service whose find() throws.
	 *
	 * @param class-string<Controller> $class The controller.
	 * @param \Exception               $find  What find() throws.
	 *
	 * @return Controller
	 */
	private function controller(string $class, \Exception $find): Controller {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('teacher-1');
		$arguments = [];
		foreach ((new ReflectionClass($class))->getConstructor()->getParameters() as $parameter) {
			$type = $parameter->getType();
			self::assertInstanceOf(ReflectionNamedType::class, $type);
			$double = $this->createMock(ContractImplementations::concrete($type->getName()));
			if ($double instanceof IUserSession) {
				$double->method('getUser')->willReturn($user);
			}

			if ($double instanceof IGroupManager) {
				$double->method('isInGroup')->willReturn(true);
			}

			if ($double instanceof DashboardRoleService) {
				$double->method('resolveViews')->willReturn(['teacher', 'admin', 'author', 'instructor']);
			}

			if ($double instanceof ObjectService) {
				$double->method('find')->willThrowException($find);
			}

			$arguments[] = $double;
		}

		return new $class(...$arguments);
	}//end controller()
}//end class
