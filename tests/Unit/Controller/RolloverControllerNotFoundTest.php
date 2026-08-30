<?php

/**
 * Learniq RolloverController not-found path unit tests.
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
 *
 * @spec openspec/changes/school-year-rollover/tasks.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Learniq\Controller\RolloverController;
use OCA\Learniq\Service\ActionAuthService;
use OCA\Learniq\Service\RolloverService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Covers preview()'s unknown-plan path.
 */
class RolloverControllerNotFoundTest extends TestCase {

	/**
	 * Build a controller whose ObjectService::find() throws for every id.
	 *
	 * @return RolloverController
	 */
	private function controllerWithThrowingFind(): RolloverController {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willThrowException(
			new \OCP\AppFramework\Db\DoesNotExistException('no such object')
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('planner-1');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		// requireAction() returns void on success; the default mock is a no-op,
		// which is the authorized case. This test is about the 404, not authz.
		$actionAuth = $this->createMock(ActionAuthService::class);

		return new RolloverController(
			request: $this->createMock(IRequest::class),
			userSession: $userSession,
			actionAuth: $actionAuth,
			rolloverService: $this->createMock(RolloverService::class),
			objectService: $objectService,
		);
	}//end controllerWithThrowingFind()

	/**
	 * An unknown planId returns 404, not a 500.
	 *
	 * ObjectService::find() raises DoesNotExistException for an unknown id
	 * rather than returning null, so before the catch in preview() the
	 * exception escaped the controller and surfaced as a 500 with a stack
	 * trace — the method's own `Plan not found` branch was unreachable.
	 *
	 * @return void
	 */
	public function testPreviewReturnsNotFoundWhenObjectServiceThrows(): void {
		$response = $this->controllerWithThrowingFind()->preview('nope');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame('Plan not found', ((array)$response->getData())['error'] ?? null);
	}//end testPreviewReturnsNotFoundWhenObjectServiceThrows()

	/**
	 * An empty planId is rejected before any lookup happens.
	 *
	 * @return void
	 */
	public function testPreviewRequiresAPlanId(): void {
		$response = $this->controllerWithThrowingFind()->preview('');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testPreviewRequiresAPlanId()

	/**
	 * proposeMapping() forwards the year's cohorts to the service and returns
	 * the mapping it produces.
	 *
	 * Asserts the mapping CONTENT, not just a 200: the endpoint's whole job is
	 * to hand back a proposal, and an empty `mappings` array — which is what a
	 * silently-unfiltered or failed cohort lookup produces — would still be a
	 * 200. Also asserts the service actually received the cohorts, so a
	 * regression that dropped them on the floor cannot pass.
	 *
	 * @return void
	 */
	public function testProposeMappingReturnsTheServiceMapping(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturn(
			[['id' => 'cohort-1', 'academicYear' => '2025-2026']]
		);

		$expected = [['fromCohortId' => 'cohort-1', 'action' => 'promote', 'toCohortName' => '2026-2027']];

		$rolloverService = $this->createMock(RolloverService::class);
		$rolloverService->expects(self::once())
			->method('proposeDefaultMapping')
			->with(self::callback(
				static function (array $fromCohorts): bool {
					return count($fromCohorts) === 1 && ($fromCohorts[0]['id'] ?? null) === 'cohort-1';
				}
			))
			->willReturn($expected);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('planner-1');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$controller = new RolloverController(
			request: $this->createMock(IRequest::class),
			userSession: $userSession,
			actionAuth: $this->createMock(ActionAuthService::class),
			rolloverService: $rolloverService,
			objectService: $objectService,
		);

		$response = $controller->proposeMapping('2025-2026');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($expected, ((array)$response->getData())['mappings']);
	}//end testProposeMappingReturnsTheServiceMapping()

	/**
	 * proposeMapping() requires the from-year.
	 *
	 * @return void
	 */
	public function testProposeMappingRequiresAFromAcademicYear(): void {
		$response = $this->controllerWithThrowingFind()->proposeMapping('');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testProposeMappingRequiresAFromAcademicYear()
}//end class
