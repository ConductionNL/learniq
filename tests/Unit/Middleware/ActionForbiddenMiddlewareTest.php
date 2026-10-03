<?php

/**
 * An action-matrix refusal is a 403 (live pass D1: it was a 500 page).
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
 *
 * @spec openspec/parity/capabilities.json#comp-roll-up-by-department
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Middleware;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Controller\ComplianceRollupController;
use OCA\Learniq\Middleware\ActionForbiddenMiddleware;
use OCA\Learniq\Service\ActionAuthService;
use OCA\Learniq\Service\ComplianceRollupService;
use OCA\Learniq\Service\RegulationAssignmentService;
use OCA\Learniq\Service\RegulationAudienceResolver;
use OCA\Learniq\Service\RegulationCoverageService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCSController;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The middleware, and that the app registers it.
 */
class ActionForbiddenMiddlewareTest extends TestCase {

	/**
	 * A refusal from the compliance controller answers 403 with the reason.
	 *
	 * @return void
	 */
	public function testARefusalIsA403(): void {
		$response = (new ActionForbiddenMiddleware())->afterException(
			$this->createStub(ComplianceRollupController::class),
			'departments',
			new OCSForbiddenException("Action 'compliance.department-rollup' not allowed for your groups")
		);

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(403, $response->getStatus());
		self::assertStringContainsString('not allowed', $response->getData()['error']);
	}//end testARefusalIsA403()

	/**
	 * Any other exception, and any refusal from an OCS controller (which
	 * answers it itself), passes through.
	 *
	 * @return void
	 */
	public function testOtherExceptionsPassThrough(): void {
		$middleware = new ActionForbiddenMiddleware();
		$other = new RuntimeException('boom');
		try {
			$middleware->afterException($this->createStub(ComplianceRollupController::class), 'departments', $other);
			self::fail('rethrown expected');
		} catch (RuntimeException $caught) {
			self::assertSame($other, $caught);
		}

		$ocs = new OCSForbiddenException('no');
		$this->expectExceptionObject($ocs);
		$middleware->afterException($this->createStub(OCSController::class), 'index', $ocs);
	}//end testOtherExceptionsPassThrough()

	/**
	 * The app object without its constructor: App's constructor needs a live
	 * Nextcloud server, and register() is what these tests read.
	 *
	 * @return Application
	 */
	private static function application(): Application {
		return (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor();
	}//end application()

	/**
	 * The app registers the middleware, or no controller ever uses it.
	 *
	 * @return void
	 */
	public function testTheAppRegistersIt(): void {
		$registered = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerMiddleware')->willReturnCallback(
			static function (string $class) use (&$registered): void {
				$registered[] = $class;
			}
		);

		try {
			self::application()->register($context);
		} catch (\Throwable $e) {
			// Bootstrap's own wiring may need a live server; the middleware
			// registration is what this test reads.
		}

		self::assertContains(ActionForbiddenMiddleware::class, $registered);
	}//end testTheAppRegistersIt()
	/**
	 * Live pass D7: a learner reads the by-regulation figures. The controller
	 * asks the action matrix, which refuses the way OpenRegister does; the
	 * request goes through the app's middleware the way Nextcloud's
	 * MiddlewareDispatcher hands an exception to afterException(). The
	 * rule-coverage spec says 403; it was a 500 page.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/compliance-rule-coverage-table/specs/compliance-rule-coverage/spec.md#requirement-per-rule-coverage-table
	 */
	public function testALearnerReadingTheCoverageGets403(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('lp-learner');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$actionAuth = $this->createMock(ActionAuthService::class);
		$actionAuth->method('requireAction')->willThrowException(
			new OCSForbiddenException("Action 'compliance.department-rollup' not allowed for your groups")
		);
		$rollup = $this->createMock(ComplianceRollupService::class);
		$controller = new ComplianceRollupController(
			$this->createMock(IRequest::class),
			$session,
			$actionAuth,
			$rollup,
			$this->createMock(RegulationAssignmentService::class),
			$this->createMock(ObjectService::class),
			new RegulationCoverageService($rollup, new RegulationAudienceResolver())
		);

		$registered = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerMiddleware')->willReturnCallback(
			static function (string $class) use (&$registered): void {
				$registered[] = $class;
			}
		);
		try {
			self::application()->register($context);
		} catch (\Throwable $e) {
			// See testTheAppRegistersIt().
		}

		foreach (['regulations', 'departments', 'assignRegulation'] as $method) {
			$response = null;
			try {
				$response = $controller->$method();
			} catch (\Exception $exception) {
				foreach (array_reverse($registered) as $middlewareClass) {
					try {
						$response = (new $middlewareClass())->afterException($controller, $method, $exception);
						break;
					} catch (\Exception $rethrown) {
						$exception = $rethrown;
					}
				}
			}

			self::assertInstanceOf(JSONResponse::class, $response, $method . ': the refusal reached Nextcloud as an exception (a 500 page).');
			self::assertSame(403, $response->getStatus(), $method);
		}
	}//end testALearnerReadingTheCoverageGets403()
}//end class
