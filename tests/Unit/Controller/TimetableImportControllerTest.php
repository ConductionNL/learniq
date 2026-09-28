<?php

/**
 * Tests for TimetableImportController: the timetable import is a delivery
 * into planninq, asked for directly now that DataExchangeJob is gone.
 *
 * @category Test
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
 *
 * @spec openspec/specs/timetable-source/spec.md#requirement-a-timetable-import-job-delivers-into-planninq-when-planninq-is-the-source-req-003
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\TimetableImportController;
use OCA\Learniq\Service\ActionAuthService;
use OCA\Learniq\Timetabling\PlanninqTimetableImport;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Session, action, planninq presence and the delivery.
 */
class TimetableImportControllerTest extends TestCase {

	/**
	 * Build the controller.
	 *
	 * @param PlanninqTimetableImport $planninq The import double.
	 * @param bool                    $allowed  Whether exchange.request is granted.
	 * @param bool                    $loggedIn Whether a user is logged in.
	 *
	 * @return TimetableImportController The controller.
	 */
	private function controller(PlanninqTimetableImport $planninq, bool $allowed = true, bool $loggedIn = true): TimetableImportController {
		$params = ['rosterSource' => 'roster-zermelo', 'groupMap' => ['1a' => 'cohort-1'], 'from' => '', 'ignored' => 'x'];
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => ($params[$key] ?? $default));

		$user = $this->createMock(IUser::class);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($loggedIn ? $user : null);

		$auth = $this->createMock(ActionAuthService::class);
		$call = $auth->method('requireAction')->with($this->anything(), 'exchange.request');
		if ($allowed === false) {
			$call->willThrowException(new OCSForbiddenException('no'));
		}

		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn('corr-1');

		return new TimetableImportController($request, $session, $auth, $planninq, $random);
	}//end controller()

	/**
	 * With planninq, the delivery runs with the request's scope and the lessons are scanned.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/timetable-source/spec.md#requirement-conflict-detection-runs-on-the-adapters-lessons-req-004
	 */
	public function testADeliveryIntoPlanninq(): void {
		$planninq = $this->createMock(PlanninqTimetableImport::class);
		$planninq->method('applies')->willReturn(true);
		$expectedJob = ['id' => 'corr-1', 'scope' => ['rosterSource' => 'roster-zermelo', 'groupMap' => ['1a' => 'cohort-1']], 'tenant_id' => ''];
		$planninq->expects($this->once())->method('deliver')->with($expectedJob, null)->willReturn(
			['state' => 'succeed', 'fields' => ['result' => ['recordsProcessed' => 3, 'recordsAccepted' => 3]]]
		);
		$planninq->expects($this->once())->method('scanConflicts')->with($expectedJob)->willReturn(3);

		$response = $this->controller($planninq)->create();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame('corr-1', $response->getData()['correlationId']);
		$this->assertSame('succeed', $response->getData()['state']);
		$this->assertSame(3, $response->getData()['result']['recordsAccepted']);
	}//end testADeliveryIntoPlanninq()

	/**
	 * Without planninq, a refused delivery, no action, no session.
	 *
	 * @return void
	 */
	public function testTheRestIsRefused(): void {
		$absent = $this->createMock(PlanninqTimetableImport::class);
		$absent->method('applies')->willReturn(false);
		$absent->expects($this->never())->method('deliver');
		$response = $this->controller($absent)->create();
		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('planninq-required', $response->getData()['code']);

		$refusing = $this->createMock(PlanninqTimetableImport::class);
		$refusing->method('applies')->willReturn(true);
		$refusing->method('deliver')->willThrowException(new RuntimeException('Integriq did not answer the timetable delivery.'));
		$refusing->expects($this->never())->method('scanConflicts');
		$this->assertSame('delivery-refused', $this->controller($refusing)->create()->getData()['code']);

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller($this->createMock(PlanninqTimetableImport::class), false)->create()->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller($this->createMock(PlanninqTimetableImport::class), true, false)->create()->getStatus());
	}//end testTheRestIsRefused()

	/**
	 * The access check answers what the endpoint would: the matrix right and
	 * whether planninq takes a delivery.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/timetabling/spec.md#scenario-an-administration-manager-opens-the-timetable-conflicts
	 */
	public function testTheAccessCheckAnswersTheRightAndPlanninq(): void {
		$planninq = $this->createMock(PlanninqTimetableImport::class);
		$planninq->method('applies')->willReturn(true);
		$user    = $this->createMock(IUser::class);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$auth = $this->createMock(ActionAuthService::class);
		$auth->expects($this->once())->method('can')->with($user, 'exchange.request')->willReturn(false);

		$controller = new TimetableImportController($this->createMock(IRequest::class), $session, $auth, $planninq, $this->createMock(ISecureRandom::class));

		$this->assertSame(['canImport' => false, 'planninq' => true], $controller->access()->getData());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller($planninq, true, false)->access()->getStatus());
	}//end testTheAccessCheckAnswersTheRightAndPlanninq()
}//end class
