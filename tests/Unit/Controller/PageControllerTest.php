<?php

/**
 * Scholiq PageController unit tests.
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
 * @spec openspec/changes/retrofit-2026-05-25-app-shell-settings/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Controller;

use OCA\Scholiq\Controller\PageController;
use OCA\Scholiq\Service\DashboardRoleService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for the two public page endpoints, manifest() and catchAll().
 */
class PageControllerTest extends TestCase {

	/**
	 * Build a PageController for the given signed-in user (or anonymous).
	 *
	 * @param IUser|null $user The signed-in user, or null for anonymous.
	 *
	 * @return PageController
	 */
	private function controller(?IUser $user): PageController {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$roleService = $this->createMock(DashboardRoleService::class);
		$roleService->method('resolvePrimaryRole')->willReturn('learner');
		$roleService->method('resolveDefaultView')->willReturn('learner');
		$roleService->method('resolveViews')->willReturn(['learner']);

		return new PageController(
			request: $this->createMock(IRequest::class),
			userSession: $userSession,
			initialState: $this->createMock(IInitialState::class),
			dashboardRoleSvc: $roleService,
		);
	}//end controller()

	/**
	 * An authenticated caller receives the parsed manifest, not a raw string.
	 *
	 * Asserts on the SHAPE of the payload — that `pages` is a non-empty array —
	 * rather than on the response merely being a 200. The endpoint reads
	 * src/manifest.json off disk and json_decode()s it; if that file moved or
	 * stopped parsing, json_decode() returns null and this endpoint would
	 * happily serve `null` with a 200. Asserting the status alone would not
	 * notice.
	 *
	 * @return void
	 */
	public function testManifestReturnsTheParsedManifestForAnAuthenticatedUser(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('learner-1');

		$response = $this->controller($user)->manifest();
		$data = $response->getData();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertIsArray($data, 'manifest() must serve a decoded array, never a null from a failed json_decode');
		self::assertArrayHasKey('pages', $data);
		self::assertNotEmpty($data['pages']);
	}//end testManifestReturnsTheParsedManifestForAnAuthenticatedUser()

	/**
	 * An anonymous caller is refused before the manifest is read.
	 *
	 * @return void
	 */
	public function testManifestRefusesAnonymousCallers(): void {
		$response = $this->controller(null)->manifest();

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertSame('Not authenticated', ((array)$response->getData())['error'] ?? null);
	}//end testManifestRefusesAnonymousCallers()

	/**
	 * catchAll() serves the same SPA template as index() for deep links.
	 *
	 * Vue Router runs in history mode, so any unmatched in-app path must still
	 * return the SPA shell rather than a 404, or a refreshed deep link breaks.
	 *
	 * @return void
	 */
	public function testCatchAllServesTheSpaTemplateForDeepLinks(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('learner-1');

		$controller = $this->controller($user);
		$response = $controller->catchAll();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('index', $response->getTemplateName());
		self::assertSame($controller->index()->getTemplateName(), $response->getTemplateName());
	}//end testCatchAllServesTheSpaTemplateForDeepLinks()

	/**
	 * catchAll() is reachable anonymously and still returns the shell.
	 *
	 * The template is public; the data it later fetches is not. A 500 here
	 * would break every unauthenticated deep link into a login redirect loop.
	 *
	 * @return void
	 */
	public function testCatchAllStillServesTheShellWhenAnonymous(): void {
		$response = $this->controller(null)->catchAll();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('index', $response->getTemplateName());
	}//end testCatchAllStillServesTheShellWhenAnonymous()
}//end class
