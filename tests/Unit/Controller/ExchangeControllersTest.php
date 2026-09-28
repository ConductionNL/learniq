<?php

/**
 * Tests for ExchangeGateController and ExchangeRequestController: the two
 * learniq routes of data-exchange-to-integriq.
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
 * @spec openspec/specs/data-exchange/spec.md#requirement-learniq-serves-its-gate-decision-over-http-for-people
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\ExchangeGateController;
use OCA\Learniq\Controller\ExchangeRequestController;
use OCA\Learniq\Exception\ExchangeRequestRefusedException;
use OCA\Learniq\Exception\IntegriqUnavailableException;
use OCA\Learniq\Service\ActionAuthService;
use OCA\Learniq\Service\ExchangeGateService;
use OCA\Learniq\Service\IntegriqExchangeClient;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Session, action and ownership checks, and the answers.
 */
class ExchangeControllersTest extends TestCase {

	/**
	 * Request parameters.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * A request double.
	 *
	 * @return IRequest The request.
	 */
	private function request(): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => ($this->params[$key] ?? $default));
		return $request;
	}//end request()

	/**
	 * A session double.
	 *
	 * @param bool $loggedIn Whether a user is logged in.
	 *
	 * @return IUserSession The session.
	 */
	private function session(bool $loggedIn = true): IUserSession {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('coordinator-1');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($loggedIn ? $user : null);
		return $session;
	}//end session()

	/**
	 * An action matrix that allows or forbids.
	 *
	 * @param bool   $allowed  Whether the action is allowed.
	 * @param string $expected The action the controller must check.
	 *
	 * @return ActionAuthService The double.
	 */
	private function actions(bool $allowed, string $expected): ActionAuthService {
		$auth = $this->createMock(ActionAuthService::class);
		$call = $auth->method('requireAction')->with($this->anything(), $expected);
		if ($allowed === false) {
			$call->willThrowException(new OCSForbiddenException('no'));
		}

		return $auth;
	}//end actions()

	/**
	 * A gate controller over one integriq job row.
	 *
	 * @param array<string, mixed>|null $job      The job row, or null for none.
	 * @param bool                      $allowed  Whether exchange.gate-read is granted.
	 * @param array<string, mixed>|null $decision The gate's answer, or null for a refusal.
	 *
	 * @return ExchangeGateController The controller.
	 */
	private function gateController(?array $job, bool $allowed = true, ?array $decision = null): ExchangeGateController {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			static function () use ($job) {
				if ($job === null) {
					throw new \RuntimeException('not found');
				}

				return OrEntityFactory::make($job, 'job', 'integriq');
			}
		);
		$gate = $this->createMock(ExchangeGateService::class);
		$gate->method('evaluate')->willReturn(
			$decision ?? ['decision' => 'refuse', 'code' => 'teldatum-unconfirmed', 'reason' => 'Not confirmed.', 'checkedAt' => '2026-10-01T09:00:00+02:00', 'records' => []]
		);

		return new ExchangeGateController($this->request(), $this->session(), $this->actions($allowed, 'exchange.gate-read'), $objects, $gate);
	}//end gateController()

	/**
	 * A coordinator checks why a job waits: the decision, never the records.
	 *
	 * @return void
	 */
	public function testACoordinatorChecksWhyAJobWaits(): void {
		$response = $this->gateController(['id' => 'job-1', 'ownerApp' => 'learniq', 'exchangeTarget' => 'bron-rod', 'exchangeDirection' => 'export'])->show('job-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['jobId' => 'job-1', 'decision' => 'refuse', 'code' => 'teldatum-unconfirmed', 'reason' => 'Not confirmed.', 'checkedAt' => '2026-10-01T09:00:00+02:00'],
			$response->getData()
		);
	}//end testACoordinatorChecksWhyAJobWaits()

	/**
	 * An allowed job answers the decision; the records stay in process.
	 *
	 * @return void
	 */
	public function testAnAllowNeverShowsTheRecords(): void {
		$allow = ['decision' => 'allow', 'code' => '', 'reason' => '', 'checkedAt' => '2026-10-01T09:00:00+02:00', 'records' => [['recordId' => 'lp-1', 'data' => ['eckId' => 'eck-1']]]];
		$response = $this->gateController(['id' => 'job-1', 'ownerApp' => 'learniq', 'exchangeTarget' => 'bron-rod'], true, $allow)->show('job-1');

		$this->assertSame('allow', $response->getData()['decision']);
		$this->assertArrayNotHasKey('records', $response->getData());
		$this->assertStringNotContainsString('eck-1', (string)json_encode($response->getData()));
	}//end testAnAllowNeverShowsTheRecords()

	/**
	 * Another app's job, an unknown job, a user without the action, no session.
	 *
	 * @return void
	 */
	public function testTheGateRouteRefusesTheRest(): void {
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->gateController(['id' => 'job-2', 'ownerApp' => 'dossiq'])->show('job-2')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->gateController(null)->show('job-3')->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->gateController(['id' => 'job-1', 'ownerApp' => 'learniq'], false)->show('job-1')->getStatus());

		$controller = new ExchangeGateController(
			$this->request(),
			$this->session(false),
			$this->createMock(ActionAuthService::class),
			$this->createMock(ObjectService::class),
			$this->createMock(ExchangeGateService::class)
		);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->show('job-1')->getStatus());
	}//end testTheGateRouteRefusesTheRest()

	/**
	 * A request controller over an integriq client double.
	 *
	 * @param IntegriqExchangeClient $integriq The client.
	 * @param ObjectService|null     $objects  The store.
	 *
	 * @return ExchangeRequestController The controller.
	 */
	private function requestController(IntegriqExchangeClient $integriq, ?ObjectService $objects = null): ExchangeRequestController {
		return new ExchangeRequestController(
			$this->request(),
			$this->session(),
			$this->actions(true, 'exchange.request'),
			$integriq,
			$objects ?? $this->createMock(ObjectService::class)
		);
	}//end requestController()

	/**
	 * An OSO request for one learner picks the mapping and opens the parents' review.
	 *
	 * @spec openspec/specs/data-exchange/spec.md#requirement-learniq-asks-integriq-to-carry-an-exchange
	 *
	 * @return void
	 */
	public function testAnOsoRequestForOneLearner(): void {
		$this->params = ['target' => 'oso', 'learnerId' => 'pupil-1'];
		$integriq = $this->createMock(IntegriqExchangeClient::class);
		$integriq->expects($this->once())->method('requestJob')->with(
			'oso',
			'export',
			'user/coordinator-1',
			['schema' => 'learner-profile', 'filters' => ['ncUserId' => 'pupil-1']],
			'learniq-oso-export-dossier',
			'coordinator-1'
		)->willReturn('job-8');
		$objects = $this->createMock(ObjectService::class);
		$objects->expects($this->once())->method('saveObject')->with(
			['exchangeJobId' => 'job-8', 'target' => 'oso', 'learnerUserId' => 'pupil-1', 'status' => 'pending'],
			$this->anything(),
			'learniq',
			'dossier-review'
		);

		$response = $this->requestController($integriq, $objects)->create();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['jobId' => 'job-8'], $response->getData());
	}//end testAnOsoRequestForOneLearner()

	/**
	 * Unknown target, integriq absent, integriq refusing.
	 *
	 * @return void
	 */
	public function testTheRequestRouteAnswersFailures(): void {
		$this->params = ['target' => 'fax'];
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->requestController($this->createMock(IntegriqExchangeClient::class))->create()->getStatus());

		$this->params = ['target' => 'bron-rod'];
		$absent = $this->createMock(IntegriqExchangeClient::class);
		$absent->method('requestJob')->willThrowException(new IntegriqUnavailableException('Integriq is not installed.'));
		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $this->requestController($absent)->create()->getStatus());

		$refusing = $this->createMock(IntegriqExchangeClient::class);
		$refusing->method('requestJob')->willThrowException(new ExchangeRequestRefusedException('mapping-missing', 'No mapping.'));
		$response = $this->requestController($refusing)->create();
		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame(['code' => 'mapping-missing', 'reason' => 'No mapping.'], $response->getData());
	}//end testTheRequestRouteAnswersFailures()
}//end class
