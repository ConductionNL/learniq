<?php

/**
 * Learniq WorkGroupController unit tests.
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
use OCA\Learniq\Controller\WorkGroupController;
use OCA\Learniq\Portal\PortalAssertionVerifier;
use OCA\Learniq\Service\Portal\PortalLearner;
use OCA\Learniq\Service\Portal\PortalLearnerResolver;
use OCA\Learniq\Service\Portal\PortalOutcome;
use OCA\Learniq\Service\WorkGroup\WorkGroupMembershipService;
use OCA\Learniq\Service\WorkGroup\WorkGroupMessages;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The in-app routes act for the caller; the portal lists and leaves; the
 * refusals are worded.
 */
class WorkGroupControllerTest extends TestCase {

	/**
	 * Calls that reached the membership service.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * The real messages over an identity translator.
	 *
	 * @return WorkGroupMessages
	 */
	private function messages(): WorkGroupMessages {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);
		$factory->method('getUserLanguage')->willReturn('en');

		return new WorkGroupMessages(l10nFactory: $factory);
	}//end messages()

	/**
	 * A membership service that records calls.
	 *
	 * @return WorkGroupMembershipService
	 */
	private function memberships(): WorkGroupMembershipService {
		$service = $this->createMock(WorkGroupMembershipService::class);
		$service->method('mine')->willReturnCallback(function (PortalLearner $learner): PortalOutcome {
			$this->calls[] = 'mine:' . $learner->ncUserId;
			return new PortalOutcome(status: 200, body: ['sets' => []]);
		});
		$service->method('join')->willReturnCallback(function (PortalLearner $learner, string $groupId): PortalOutcome {
			$this->calls[] = 'join:' . $learner->ncUserId . ':' . $groupId;
			return new PortalOutcome(status: 409, body: ['error' => 'full'], reason: 'full');
		});
		$service->method('leave')->willReturnCallback(function (PortalLearner $learner, string $groupId): PortalOutcome {
			$this->calls[] = 'leave:' . $learner->ncUserId . ':' . $groupId;
			return new PortalOutcome(status: 200, body: ['groupId' => $groupId]);
		});

		return $service;
	}//end memberships()

	/**
	 * The signed-in learner lists, joins and leaves as themselves.
	 *
	 * @return void
	 */
	public function testTheAppActsForTheCaller(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('learner-1');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$controller = new WorkGroupController(request: $this->createMock(IRequest::class), userSession: $session, memberships: $this->memberships(), messages: $this->messages());

		self::assertSame(['sets' => []], $controller->mine()->getData());
		self::assertSame('This work group is full.', $controller->join(id: 'g1')->getData()['message']);
		self::assertSame(200, $controller->leave(id: 'g2')->getStatus());
		self::assertSame(['mine:learner-1', 'join:learner-1:g1', 'leave:learner-1:g2'], $this->calls);
	}//end testTheAppActsForTheCaller()

	/**
	 * The portal lists and leaves for the pupil.
	 *
	 * @return void
	 */
	public function testThePortalListsAndLeaves(): void {
		$params = ['learnerRef' => 'lp-1', 'groupId' => 'g3'];
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('token');
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => $params[$key] ?? $default);
		$verifier = $this->createMock(PortalAssertionVerifier::class);
		$verifier->method('verify')->willReturn(['audience' => 'student']);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('pupil-1');
		$learners = $this->createMock(PortalLearnerResolver::class);
		$learners->method('resolve')->willReturn(new PortalLearner(profileRef: 'lp-1', ncUserId: 'pupil-1', tenantId: '', user: $user));
		$portal = new PortalWorkGroupController(request: $request, verifier: $verifier, learners: $learners, memberships: $this->memberships(), messages: $this->messages(), logger: new NullLogger());

		self::assertSame(['sets' => []], $portal->mine()->getData());
		self::assertSame(['groupId' => 'g3'], $portal->leave()->getData());
		self::assertSame(['mine:pupil-1', 'leave:pupil-1:g3'], $this->calls);
	}//end testThePortalListsAndLeaves()
}//end class
