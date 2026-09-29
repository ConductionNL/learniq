<?php

/**
 * DisplayScreenController: who may create and revoke a screen's address.
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
 * @spec openspec/changes/timetabling-display-screens/specs/personal-timetable/spec.md#requirement-an-administrator-sets-up-a-display-screen
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\DisplayScreenController;
use OCA\Learniq\Service\ActionAuthService;
use OCA\Learniq\Service\DisplayScreenService;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The action matrix, the one-time answer, and 404 for an unseen screen.
 */
class DisplayScreenControllerTest extends TestCase {

	/**
	 * The controller with its doubles.
	 *
	 * @param DisplayScreenService $screens  The service double.
	 * @param bool                 $allowed  Whether the action matrix allows.
	 * @param bool                 $loggedIn Whether a user is signed in.
	 *
	 * @return DisplayScreenController
	 */
	private function controller(DisplayScreenService $screens, bool $allowed=true, bool $loggedIn=true): DisplayScreenController {
		$user = $this->createMock(IUser::class);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($loggedIn ? $user : null);

		$auth = $this->createMock(ActionAuthService::class);
		$call = $auth->method('requireAction')->with($this->anything(), DisplayScreenController::ACTION);
		if ($allowed === false) {
			$call->willThrowException(new OCSForbiddenException('Not allowed.'));
		}

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->willReturnCallback(static fn (string $route, array $args) => '/apps/learniq/display/'.$args['token']);
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path) => 'https://school.example'.$path);

		return new DisplayScreenController($this->createMock(IRequest::class), $session, $auth, $screens, $urls);
	}//end controller()

	/**
	 * A team lead gets the address once.
	 *
	 * @return void
	 */
	public function testATeamLeadGetsTheAddressOnce(): void {
		$screens = $this->createMock(DisplayScreenService::class);
		$screens->method('issueToken')->with('screen-1')->willReturn('screen-1.secret');

		$response = $this->controller($screens)->token('screen-1');

		self::assertSame(Http::STATUS_CREATED, $response->getStatus());
		self::assertSame('https://school.example/apps/learniq/display/screen-1.secret', $response->getData()['address']);
		self::assertStringContainsString('shown once', $response->getData()['note']);
	}//end testATeamLeadGetsTheAddressOnce()

	/**
	 * Without the action, or without a user, nothing is issued or revoked.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		$screens = $this->createMock(DisplayScreenService::class);
		$screens->expects($this->never())->method('issueToken');
		$screens->expects($this->never())->method('revoke');

		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller($screens, allowed: false)->token('screen-1')->getStatus());
		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller($screens, allowed: false)->revoke('screen-1')->getStatus());
		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller($screens, loggedIn: false)->token('screen-1')->getStatus());
	}//end testRefusals()

	/**
	 * A screen the caller cannot see is 404; a revoke answers its status.
	 *
	 * @return void
	 */
	public function testNotFoundAndRevoke(): void {
		$screens = $this->createMock(DisplayScreenService::class);
		$screens->method('issueToken')->willReturn(null);
		$screens->method('revoke')->willReturnCallback(static fn (string $id) => $id === 'screen-1');

		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller($screens)->token('nope')->getStatus());
		self::assertSame(['status' => 'revoked'], $this->controller($screens)->revoke('screen-1')->getData());
		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller($screens)->revoke('nope')->getStatus());
	}//end testNotFoundAndRevoke()
}//end class
