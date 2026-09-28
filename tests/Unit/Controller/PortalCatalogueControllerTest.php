<?php

/**
 * Learniq PortalCatalogueController and CatalogueController unit tests.
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
 * @spec openspec/changes/enrolment-catalogue-self-signup/specs/enrolment/spec.md#requirement-a-learner-signs-up-from-the-catalogue
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\CatalogueController;
use OCA\Learniq\Controller\PortalCatalogueController;
use OCA\Learniq\Portal\PortalAssertionVerifier;
use OCA\Learniq\Service\Catalogue\CatalogueMessages;
use OCA\Learniq\Service\Catalogue\CatalogueReader;
use OCA\Learniq\Service\Catalogue\CatalogueSignUpService;
use OCA\Learniq\Service\LearnerRefResolver;
use OCA\Learniq\Service\Portal\PortalLearner;
use OCA\Learniq\Service\Portal\PortalLearnerResolver;
use OCA\Learniq\Service\Portal\PortalOutcome;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;
use RuntimeException;

/**
 * The portal receiver order and the in-app caller-only sign-up.
 */
class PortalCatalogueControllerTest extends TestCase {

	/**
	 * Calls that reached the sign-up service.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * A sign-up service double that records calls.
	 *
	 * @param bool $throws Whether it throws.
	 *
	 * @return CatalogueSignUpService
	 */
	private function signUps(bool $throws = false): CatalogueSignUpService {
		$signUps = $this->createMock(CatalogueSignUpService::class);
		$record = function (string $what, PortalLearner $learner, string $id) use ($throws): PortalOutcome {
			$this->calls[] = $what . ':' . $learner->ncUserId . ':' . $id;
			if ($throws === true) {
				throw new RuntimeException('SQLSTATE secret');
			}

			return new PortalOutcome(status: 422, body: ['error' => 'closed'], reason: 'closed');
		};
		$signUps->method('signUpCourse')->willReturnCallback(fn (PortalLearner $learner, string $courseId) => $record('course', $learner, $courseId));
		$signUps->method('signUpProgramme')->willReturnCallback(fn (PortalLearner $learner, string $programmeId) => $record('programme', $learner, $programmeId));
		$signUps->method('withdraw')->willReturnCallback(fn (PortalLearner $learner, string $enrolmentId) => $record('withdraw', $learner, $enrolmentId));

		return $signUps;
	}//end signUps()

	/**
	 * The portal receiver.
	 *
	 * @param array<string, mixed>|null $claims What the verifier returns.
	 * @param array<string, mixed>      $params The forwarded body.
	 * @param bool                      $throws Whether the service throws.
	 *
	 * @return PortalCatalogueController
	 */
	private function portal(?array $claims, array $params, bool $throws = false): PortalCatalogueController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('token');
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => $params[$key] ?? $default);
		$verifier = $this->createMock(PortalAssertionVerifier::class);
		$verifier->method('verify')->willReturn($claims);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('pupil-1');
		$learners = $this->createMock(PortalLearnerResolver::class);
		$learners->method('resolve')->willReturn(new PortalLearner(profileRef: 'lp-1', ncUserId: 'pupil-1', tenantId: '', user: $user));
		$messages = $this->createMock(CatalogueMessages::class);
		$messages->method('message')->willReturnCallback(static fn (string $reason): string => 'msg:' . $reason);

		return new PortalCatalogueController(
			request: $request,
			verifier: $verifier,
			learners: $learners,
			signUps: $this->signUps(throws: $throws),
			messages: $messages,
			logger: new NullLogger()
		);
	}//end portal()

	/**
	 * Every receiver method is a public page for the middleware.
	 *
	 * @return void
	 */
	public function testEveryReceiverIsAPublicPage(): void {
		foreach (['catalogue', 'signUp', 'withdraw'] as $name) {
			self::assertNotEmpty((new ReflectionMethod(PortalCatalogueController::class, $name))->getAttributes(PublicPage::class), $name);
		}
	}//end testEveryReceiverIsAPublicPage()

	/**
	 * No assertion or the wrong audience never reach the service; a pupil's
	 * sign-up goes to the course or the programme as named; a failure is 502.
	 *
	 * @return void
	 */
	public function testTheReceiverOrder(): void {
		self::assertSame(401, $this->portal(claims: null, params: ['learnerRef' => 'lp-1'])->signUp()->getStatus());
		self::assertSame(403, $this->portal(claims: ['audience' => 'parent'], params: ['learnerRef' => 'lp-1'])->signUp()->getStatus());
		self::assertSame([], $this->calls);

		$response = $this->portal(claims: ['audience' => 'student'], params: ['learnerRef' => 'lp-1', 'courseId' => 'c-1'])->signUp();
		self::assertSame(422, $response->getStatus());
		self::assertSame('msg:closed', $response->getData()['message']);
		$this->portal(claims: ['audience' => 'student'], params: ['learnerRef' => 'lp-1', 'programmeId' => 'p-1'])->signUp();
		$this->portal(claims: ['audience' => 'student'], params: ['learnerRef' => 'lp-1', 'enrolmentId' => 'e-1'])->withdraw();
		self::assertSame(['course:pupil-1:c-1', 'programme:pupil-1:p-1', 'withdraw:pupil-1:e-1'], $this->calls);

		self::assertSame(502, $this->portal(claims: ['audience' => 'student'], params: ['learnerRef' => 'lp-1', 'courseId' => 'c-1'], throws: true)->signUp()->getStatus());
	}//end testTheReceiverOrder()

	/**
	 * In the app the caller signs up as themselves, with their profile; no
	 * session is 401.
	 *
	 * @return void
	 */
	public function testTheAppSignsUpTheCallerOnly(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('p.ganpat');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnOnConsecutiveCalls($user, null);
		$profiles = $this->createMock(LearnerRefResolver::class);
		$profiles->method('resolve')->willReturn('lp-9');

		$controller = new CatalogueController(
			request: $this->createMock(IRequest::class),
			userSession: $session,
			reader: $this->createMock(CatalogueReader::class),
			signUps: $this->signUps(),
			messages: $this->createMock(CatalogueMessages::class),
			profiles: $profiles
		);

		self::assertSame(422, $controller->signUpCourse(id: 'c-1')->getStatus());
		self::assertSame(['course:p.ganpat:c-1'], $this->calls);
		self::assertSame(401, $controller->withdraw(id: 'e-1')->getStatus());

		$noProfile = $this->createMock(LearnerRefResolver::class);
		$noProfile->method('resolve')->willReturn(null);
		$staffSession = $this->createMock(IUserSession::class);
		$staffSession->method('getUser')->willReturn($user);
		$staff = new CatalogueController(
			request: $this->createMock(IRequest::class),
			userSession: $staffSession,
			reader: $this->createMock(CatalogueReader::class),
			signUps: $this->signUps(),
			messages: $this->createMock(CatalogueMessages::class),
			profiles: $noProfile
		);
		self::assertSame(403, $staff->signUpCourse(id: 'c-1')->getStatus());
		self::assertSame(['course:p.ganpat:c-1'], $this->calls);
	}//end testTheAppSignsUpTheCallerOnly()
}//end class
