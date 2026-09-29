<?php

/**
 * Learniq PortalWorkGroupController unit tests.
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
 * @spec openspec/changes/enrolment-self-join-work-group/specs/enrolment/spec.md#requirement-a-learner-joins-a-work-group-with-a-free-place
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\PortalWorkGroupController;
use OCA\Learniq\Portal\PortalAssertionVerifier;
use OCA\Learniq\Service\Portal\PortalLearner;
use OCA\Learniq\Service\Portal\PortalLearnerResolver;
use OCA\Learniq\Service\Portal\PortalOutcome;
use OCA\Learniq\Service\WorkGroup\WorkGroupMembershipService;
use OCA\Learniq\Service\WorkGroup\WorkGroupMessages;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IRequest;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * The work group receiver order.
 */
class PortalWorkGroupControllerTest extends TestCase {

	/**
	 * Calls that reached the membership service.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * The receiver.
	 *
	 * @param array<string, mixed>|null $claims What the verifier returns.
	 *
	 * @return PortalWorkGroupController
	 */
	private function portal(?array $claims): PortalWorkGroupController {
		$params = ['learnerRef' => 'lp-1', 'groupId' => 'g1'];
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('token');
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => $params[$key] ?? $default);
		$verifier = $this->createMock(PortalAssertionVerifier::class);
		$verifier->method('verify')->willReturn($claims);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('pupil-1');
		$learners = $this->createMock(PortalLearnerResolver::class);
		$learners->method('resolve')->willReturn(new PortalLearner(profileRef: 'lp-1', ncUserId: 'pupil-1', tenantId: '', user: $user));
		$memberships = $this->createMock(WorkGroupMembershipService::class);
		$memberships->method('join')->willReturnCallback(function (PortalLearner $learner, string $groupId): PortalOutcome {
			$this->calls[] = 'join:' . $learner->ncUserId . ':' . $groupId;
			return new PortalOutcome(status: 409, body: ['error' => 'full'], reason: 'full');
		});
		$messages = $this->createMock(WorkGroupMessages::class);
		$messages->method('message')->willReturnCallback(static fn (string $reason): string => 'msg:' . $reason);

		return new PortalWorkGroupController(
			request: $request,
			verifier: $verifier,
			learners: $learners,
			memberships: $memberships,
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
		foreach (['mine', 'join', 'leave'] as $name) {
			self::assertNotEmpty((new ReflectionMethod(PortalWorkGroupController::class, $name))->getAttributes(PublicPage::class), $name);
		}
	}//end testEveryReceiverIsAPublicPage()

	/**
	 * No assertion or the wrong audience never reach the service; a pupil's
	 * join reaches it as the pupil, with the reason worded.
	 *
	 * @return void
	 */
	public function testTheReceiverOrder(): void {
		self::assertSame(401, $this->portal(claims: null)->join()->getStatus());
		self::assertSame(403, $this->portal(claims: ['audience' => 'parent'])->join()->getStatus());
		self::assertSame([], $this->calls);

		$response = $this->portal(claims: ['audience' => 'student'])->join();
		self::assertSame(409, $response->getStatus());
		self::assertSame('msg:full', $response->getData()['message']);
		self::assertSame(['join:pupil-1:g1'], $this->calls);
	}//end testTheReceiverOrder()
}//end class
