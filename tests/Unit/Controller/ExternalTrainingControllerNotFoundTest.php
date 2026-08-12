<?php

/**
 * Scholiq ExternalTrainingController not-found path unit tests.
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Unit\Controller
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
 * @spec openspec/changes/external-training-recording/tasks.md
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Controller;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Scholiq\Controller\ExternalTrainingController;
use OCA\Scholiq\Service\ActionAuthService;
use OCA\Scholiq\Service\ExternalTrainingService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Covers issueCredential()'s unknown-record path.
 */
class ExternalTrainingControllerNotFoundTest extends TestCase {

	/**
	 * Build a controller whose ObjectService::find() throws for every id.
	 *
	 * @return ExternalTrainingController
	 */
	private function controllerWithThrowingFind(): ExternalTrainingController {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willThrowException(
			new \OCP\AppFramework\Db\DoesNotExistException('no such object')
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('officer-1');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		// requireAction() returns void on success; the default mock is a no-op,
		// which is the authorized case. This test is about the 404, not authz.
		$actionAuth = $this->createMock(ActionAuthService::class);

		return new ExternalTrainingController(
			request: $this->createMock(IRequest::class),
			userSession: $userSession,
			actionAuth: $actionAuth,
			trainingService: $this->createMock(ExternalTrainingService::class),
			objectService: $objectService,
		);
	}//end controllerWithThrowingFind()

	/**
	 * An unknown recordId returns 404, not a 500.
	 *
	 * ObjectService::find() raises DoesNotExistException for an unknown id
	 * rather than returning null, so before the catch in issueCredential() the
	 * exception escaped the controller and surfaced as a 500 with a stack
	 * trace — the method's own `Record not found` branch was unreachable.
	 *
	 * Asserts on the STATUS CODE and the error body, not merely that no
	 * exception escaped: a test that only caught would still pass on a 500.
	 *
	 * @return void
	 */
	public function testIssueCredentialReturnsNotFoundWhenObjectServiceThrows(): void {
		$response = $this->controllerWithThrowingFind()->issueCredential('nope');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame('Record not found', ((array)$response->getData())['error'] ?? null);
	}//end testIssueCredentialReturnsNotFoundWhenObjectServiceThrows()

	/**
	 * An empty recordId is rejected before any lookup happens.
	 *
	 * @return void
	 */
	public function testIssueCredentialRequiresARecordId(): void {
		$response = $this->controllerWithThrowingFind()->issueCredential('');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testIssueCredentialRequiresARecordId()

	/**
	 * learnerCoverage() reports the service's verdict and evidence class.
	 *
	 * Asserts on BOTH fields of the payload, not just the status: the endpoint
	 * exists to answer "is this learner covered, and by what" — a 200 carrying
	 * a wrong or absent `covered` flag is the failure that matters, and a
	 * status-only assertion would pass straight through it.
	 *
	 * @return void
	 */
	public function testLearnerCoverageReturnsTheServiceVerdict(): void {
		$trainingService = $this->createMock(ExternalTrainingService::class);
		$trainingService->method('isLearnerCovered')->willReturn(true);
		$trainingService->method('coveringEvidenceClass')->willReturn('credential');

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('officer-1');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$controller = new ExternalTrainingController(
			request: $this->createMock(IRequest::class),
			userSession: $userSession,
			actionAuth: $this->createMock(ActionAuthService::class),
			trainingService: $trainingService,
			objectService: $this->createMock(ObjectService::class),
		);

		$response = $controller->learnerCoverage('learner-1', 'NIS2');
		$data = (array)$response->getData();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertTrue($data['covered']);
		self::assertSame('credential', $data['evidenceClass']);
	}//end testLearnerCoverageReturnsTheServiceVerdict()

	/**
	 * learnerCoverage() requires both identifying parameters.
	 *
	 * @return void
	 */
	public function testLearnerCoverageRequiresBothParameters(): void {
		$response = $this->controllerWithThrowingFind()->learnerCoverage('learner-1', '');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testLearnerCoverageRequiresBothParameters()
}//end class
