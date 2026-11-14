<?php

/**
 * Learniq PortalElectiveController unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/pupil-books-support-lessons-and-mentor-talks/specs/portal-contribution/spec.md#requirement-a-pupil-reads-her-schools-open-support-lessons-and-signs-herself-up
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\PortalElectiveController;
use OCA\Learniq\Kernel\Contracts\PortalAssertionVerifierInterface;
use OCA\Learniq\Kernel\Contracts\PortalElectiveSignUpInterface;
use OCA\Learniq\Kernel\Contracts\PortalLearnerResolverInterface;
use OCA\Learniq\Portal\PortalAdmission;
use OCA\Learniq\Portal\PortalForwardFailure;
use OCA\Learniq\Portal\PortalForwardGate;
use OCA\Learniq\Portal\PortalLearnerForward;
use OCA\Learniq\Portal\PortalLearnerStep;
use OCA\Learniq\Service\Portal\PortalLearner;
use OCA\Learniq\Service\Portal\PortalOutcome;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IRequest;
use OCP\IUser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;
use RuntimeException;

/**
 * The receiver order around the pupil's optional-lesson sign-up.
 */
#[CoversClass(PortalElectiveController::class)]
#[UsesClass(PortalAdmission::class)]
#[UsesClass(PortalForwardFailure::class)]
#[UsesClass(PortalForwardGate::class)]
#[UsesClass(PortalLearnerForward::class)]
#[UsesClass(PortalLearnerStep::class)]
#[UsesClass(PortalLearner::class)]
#[UsesClass(PortalOutcome::class)]
class PortalElectiveControllerTest extends TestCase {

	/**
	 * Calls that reached the sign-up.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * The receiver.
	 *
	 * @param array<string, mixed>|null $claims  What the verifier returns.
	 * @param array<string, mixed>      $params  The forwarded body.
	 * @param bool                      $throws  Whether the sign-up throws.
	 * @param bool                      $account Whether the pupil has an account.
	 *
	 * @return PortalElectiveController
	 */
	private function controller(?array $claims, array $params, bool $throws=false, bool $account=true): PortalElectiveController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('token');
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default=null) => ($params[$key] ?? $default));
		$verifier = $this->createMock(PortalAssertionVerifierInterface::class);
		$verifier->method('verify')->willReturn($claims);
		$user = $this->createMock(IUser::class);
		$learners = $this->createMock(PortalLearnerResolverInterface::class);
		$learners->method('resolve')->willReturnCallback(
			static fn (string $learnerRef): ?PortalLearner => ($account === true) ? new PortalLearner(profileRef: $learnerRef, ncUserId: 'noor', tenantId: '', user: $user) : null
		);
		$signUps = $this->createMock(PortalElectiveSignUpInterface::class);
		$signUps->method('signUp')->willReturnCallback(
			function (PortalLearner $learner, string $offerId) use ($throws): PortalOutcome {
				$this->calls[] = $learner->profileRef . ':' . $offerId;
				if ($throws === true) {
					throw new RuntimeException('SQLSTATE secret');
				}

				return new PortalOutcome(status: 422, body: ['error' => 'full'], reason: 'full');
			}
		);
		$signUps->method('message')->willReturnCallback(static fn (string $reason): string => 'msg:' . $reason);

		return new PortalElectiveController(request: $request, verifier: $verifier, learners: $learners, signUps: $signUps, logger: new NullLogger());
	}//end controller()

	/**
	 * The receiver is a public page: the forward carries no session.
	 *
	 * @return void
	 */
	public function testTheReceiverIsAPublicPage(): void {
		self::assertNotEmpty((new ReflectionMethod(PortalElectiveController::class, 'signUp'))->getAttributes(PublicPage::class));
	}//end testTheReceiverIsAPublicPage()

	/**
	 * No assertion, another audience or no learnerRef never reach the
	 * sign-up; a pupil without an account is not available.
	 *
	 * @return void
	 */
	public function testOnlyAStudentForwardReachesTheSignUp(): void {
		self::assertSame(401, $this->controller(claims: null, params: ['learnerRef' => 'lp-noor', 'offerId' => 'offer-1'])->signUp()->getStatus());
		self::assertSame(403, $this->controller(claims: ['audience' => 'parent'], params: ['learnerRef' => 'lp-noor', 'offerId' => 'offer-1'])->signUp()->getStatus());
		self::assertSame(403, $this->controller(claims: ['audience' => 'student'], params: ['offerId' => 'offer-1'])->signUp()->getStatus());
		$missing = $this->controller(claims: ['audience' => 'student'], params: ['learnerRef' => 'lp-noor', 'offerId' => 'offer-1'], account: false)->signUp();
		self::assertSame([403, 'not_available', 'msg:no-account'], [$missing->getStatus(), $missing->getData()['error'], $missing->getData()['message']]);
		self::assertSame([], $this->calls);
	}//end testOnlyAStudentForwardReachesTheSignUp()

	/**
	 * A student forward signs up the forwarded pupil for the named offer and
	 * relays the refusal in words; a failure is a bare 502.
	 *
	 * @return void
	 */
	public function testTheForwardedPupilIsSignedUpAndARefusalIsWorded(): void {
		$response = $this->controller(claims: ['audience' => 'student'], params: ['learnerRef' => 'lp-noor', 'offerId' => 'offer-1'])->signUp();
		self::assertSame(422, $response->getStatus());
		self::assertSame(['error' => 'full', 'message' => 'msg:full'], $response->getData());
		self::assertSame(['lp-noor:offer-1'], $this->calls);

		$failed = $this->controller(claims: ['audience' => 'student'], params: ['learnerRef' => 'lp-noor', 'offerId' => 'offer-1'], throws: true)->signUp();
		self::assertSame(502, $failed->getStatus());
		self::assertStringNotContainsString('SQLSTATE', json_encode($failed->getData()));
	}//end testTheForwardedPupilIsSignedUpAndARefusalIsWorded()
}//end class
