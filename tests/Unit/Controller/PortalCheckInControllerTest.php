<?php

/**
 * Learniq PortalCheckInController and CheckInCodeController unit tests.
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
 * @spec openspec/specs/attendance/spec.md#requirement-a-learner-checks-in-with-the-code
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\CheckInCodeController;
use OCA\Learniq\Controller\PortalCheckInController;
use OCA\Learniq\Portal\PortalAssertionVerifier;
use OCA\Learniq\Service\CheckIn\CheckInCodeService;
use OCA\Learniq\Service\CheckIn\CheckInMessages;
use OCA\Learniq\Service\CheckIn\CheckInService;
use OCA\Learniq\Service\Portal\PortalLearner;
use OCA\Learniq\Service\Portal\PortalLearnerResolver;
use OCA\Learniq\Service\Portal\PortalOutcome;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;
use RuntimeException;

/**
 * The portal receiver's order and the staff-only code read.
 */
class PortalCheckInControllerTest extends TestCase {

	/**
	 * Calls that reached the check-in service.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $calls = [];

	/**
	 * The portal receiver.
	 *
	 * @param array<string, mixed>|null $claims      What the verifier returns.
	 * @param bool                      $pupilExists Whether learnerRef resolves.
	 * @param bool                      $throws      Whether the service throws.
	 *
	 * @return PortalCheckInController
	 */
	private function portal(?array $claims, bool $pupilExists = true, bool $throws = false): PortalCheckInController {
		$params = ['learnerRef' => 'lp-1', 'windowId' => 'win-1', 'code' => 'ABCDEFGH'];
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('token');
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => $params[$key] ?? $default);

		$verifier = $this->createMock(PortalAssertionVerifier::class);
		$verifier->method('verify')->willReturn($claims);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('pupil-1');
		$learners = $this->createMock(PortalLearnerResolver::class);
		$learners->method('resolve')->willReturn(
			$pupilExists === true ? new PortalLearner(profileRef: 'lp-1', ncUserId: 'pupil-1', tenantId: '', user: $user) : null
		);

		$checkIns = $this->createMock(CheckInService::class);
		$checkIns->method('checkIn')->willReturnCallback(
			function (string $windowId, string $code, string $userId, ?string $learnerRef = null, ?IUser $runAs = null) use ($throws): PortalOutcome {
				$this->calls[] = compact('windowId', 'code', 'userId', 'learnerRef') + ['runAs' => $runAs?->getUID()];
				if ($throws === true) {
					throw new RuntimeException('SQLSTATE secret');
				}

				return new PortalOutcome(status: 409, body: ['error' => 'already_recorded'], reason: 'already-recorded');
			}
		);

		$messages = $this->createMock(CheckInMessages::class);
		$messages->method('message')->willReturnCallback(static fn (string $reason, ?IUser $u): string => 'msg:' . $reason);

		return new PortalCheckInController(request: $request, verifier: $verifier, learners: $learners, checkIns: $checkIns, messages: $messages, logger: new NullLogger());
	}//end portal()

	/**
	 * The receiver is public for the middleware, CSRF-free and rate limited.
	 *
	 * @return void
	 */
	public function testTheReceiverIsAPublicRateLimitedRoute(): void {
		$method = new ReflectionMethod(PortalCheckInController::class, 'checkIn');

		self::assertNotEmpty($method->getAttributes(PublicPage::class));
		self::assertNotEmpty($method->getAttributes(NoCSRFRequired::class));
		self::assertNotEmpty($method->getAttributes(AnonRateLimit::class));
	}//end testTheReceiverIsAPublicRateLimitedRoute()

	/**
	 * No assertion, the wrong audience or an unknown pupil never reach the
	 * service.
	 *
	 * @return void
	 */
	public function testTheReceiverRefusesBeforeTheService(): void {
		$response = $this->portal(claims: null)->checkIn();
		self::assertSame(401, $response->getStatus());
		self::assertTrue($response->isThrottled());
		self::assertSame(403, $this->portal(claims: ['audience' => 'parent'])->checkIn()->getStatus());
		self::assertSame(403, $this->portal(claims: ['audience' => 'student'], pupilExists: false)->checkIn()->getStatus());
		self::assertSame([], $this->calls);
	}//end testTheReceiverRefusesBeforeTheService()

	/**
	 * A pupil's check-in reaches the service as the pupil, with the reason
	 * worded; a failure answers 502 without internals.
	 *
	 * @return void
	 */
	public function testThePupilIsCheckedInAsThemselves(): void {
		$response = $this->portal(claims: ['audience' => 'student'])->checkIn();

		self::assertSame(409, $response->getStatus());
		self::assertSame('msg:already-recorded', $response->getData()['message']);
		self::assertSame([['windowId' => 'win-1', 'code' => 'ABCDEFGH', 'userId' => 'pupil-1', 'learnerRef' => 'lp-1', 'runAs' => 'pupil-1']], $this->calls);

		$failed = $this->portal(claims: ['audience' => 'student'], throws: true)->checkIn();
		self::assertSame(502, $failed->getStatus());
		self::assertSame(['error' => 'downstream_error'], $failed->getData());
	}//end testThePupilIsCheckedInAsThemselves()

	/**
	 * Only staff read the code for the board.
	 *
	 * @return void
	 */
	public function testOnlyStaffReadTheCode(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('pupil-1');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);
		$groups->method('isInGroup')->willReturn(false);
		$objects = $this->createMock(ObjectService::class);
		$objects->expects(self::never())->method('find');

		$controller = new CheckInCodeController(
			request: $this->createMock(IRequest::class),
			userSession: $session,
			groupManager: $groups,
			codes: $this->createMock(CheckInCodeService::class),
			objects: $objects,
			urls: $this->createMock(IURLGenerator::class)
		);

		self::assertSame(403, $controller->code(windowId: 'win-1')->getStatus());
	}//end testOnlyStaffReadTheCode()
}//end class
