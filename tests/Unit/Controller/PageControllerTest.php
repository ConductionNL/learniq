<?php

/**
 * Learniq PageController unit tests.
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
 * @spec openspec/changes/retrofit-2026-05-25-app-shell-settings/tasks.md#task-5
 * @spec openspec/changes/segment-runtime-bridge/specs/nextcloud-app/spec.md#requirement-the-segment-reaches-the-manifest-runtime
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\PageController;
use OCA\Learniq\Service\DashboardRoleService;
use OCA\Learniq\Service\SegmentService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Contract tests for the two public page endpoints, manifest() and catchAll().
 */
class PageControllerTest extends TestCase {

	/**
	 * Build a PageController for the given signed-in user (or anonymous).
	 *
	 * @param IUser|null         $user         The signed-in user, or null for anonymous.
	 * @param IInitialState|null $initialState The initial-state double, or a silent mock.
	 * @param bool               $segmentFails Whether resolving SegmentService throws.
	 *
	 * @return PageController
	 */
	private function controller(?IUser $user, ?IInitialState $initialState = null, bool $segmentFails = false): PageController {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$roleService = $this->createMock(DashboardRoleService::class);
		$roleService->method('resolvePrimaryRole')->willReturn('learner');
		$roleService->method('resolveDefaultView')->willReturn('learner');
		$roleService->method('resolveViews')->willReturn(['learner']);

		$segmentService = $this->createMock(SegmentService::class);
		$segmentService->method('currentSegment')->willReturn('po');
		$container = $this->createMock(ContainerInterface::class);
		if ($segmentFails === true) {
			$container->method('get')->willThrowException(new RuntimeException('OpenRegister is not installed'));
		} else {
			$container->method('get')->with(SegmentService::class)->willReturn($segmentService);
		}

		return new PageController(
			request: $this->createMock(IRequest::class),
			userSession: $userSession,
			initialState: ($initialState ?? $this->createMock(IInitialState::class)),
			dashboardRoleSvc: $roleService,
			container: $container,
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

	/**
	 * A signed-in user's page carries the instance segment as initial state,
	 * next to the role context, so `src/main.js` can publish it at
	 * `runtime.workspace.segment`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-runtime-bridge/specs/nextcloud-app/spec.md#scenario-a-signed-in-users-page-carries-the-segment
	 */
	public function testIndexProvidesTheSegmentForASignedInUser(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('learner-1');

		$provided     = [];
		$initialState = $this->createMock(IInitialState::class);
		$initialState->method('provideInitialState')
			->willReturnCallback(
				static function (string $key, mixed $value) use (&$provided): void {
					$provided[$key] = $value;
				}
			);

		$this->controller($user, $initialState)->index();

		self::assertSame('po', ($provided['segment'] ?? null));
		self::assertArrayHasKey('primaryRole', $provided);
	}//end testIndexProvidesTheSegmentForASignedInUser()

	/**
	 * Without OpenRegister the segment cannot be read, and the start screen
	 * still renders with the default segment rather than a 500 (ADR-083).
	 *
	 * @return void
	 */
	public function testIndexFallsBackToTheDefaultWhenTheSegmentCannotBeResolved(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('learner-1');

		$provided     = [];
		$initialState = $this->createMock(IInitialState::class);
		$initialState->method('provideInitialState')
			->willReturnCallback(
				static function (string $key, mixed $value) use (&$provided): void {
					$provided[$key] = $value;
				}
			);

		$response = $this->controller($user, $initialState, true)->index();

		self::assertSame('index', $response->getTemplateName());
		self::assertSame('corporate', ($provided['segment'] ?? null));
	}//end testIndexFallsBackToTheDefaultWhenTheSegmentCannotBeResolved()

	/**
	 * An anonymous request gets the shell and no initial state at all.
	 *
	 * @return void
	 */
	public function testIndexProvidesNothingWhenAnonymous(): void {
		$initialState = $this->createMock(IInitialState::class);
		$initialState->expects(self::never())->method('provideInitialState');

		$this->controller(null, $initialState)->index();
	}//end testIndexProvidesNothingWhenAnonymous()

	/**
	 * index() hands the page shell the confidential counsellor flag, so the
	 * confidential notes menu can gate on group membership.
	 *
	 * @spec openspec/changes/confidential-counsellor-channel/specs/confidential-counsel/spec.md#requirement-the-confidential-notes-menu-is-shown-to-confidential-counsellors-only
	 *
	 * @return void
	 */
	public function testIndexProvidesTheConfidentialCounsellorFlag(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('vp-01');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$roleService = $this->createMock(DashboardRoleService::class);
		$roleService->method('resolvePrimaryRole')->willReturn('instructor');
		$roleService->method('resolveDefaultView')->willReturn('teacher');
		$roleService->method('resolveViews')->willReturn(['teacher', 'student']);
		$roleService->method('isConfidentialCounsellor')->willReturn(true);

		$provided     = [];
		$initialState = $this->createMock(IInitialState::class);
		$initialState->method('provideInitialState')->willReturnCallback(
			static function (string $key, mixed $value) use (&$provided): void {
				$provided[$key] = $value;
			}
		);

		$controller = new PageController(
			request: $this->createMock(IRequest::class),
			userSession: $userSession,
			initialState: $initialState,
			dashboardRoleSvc: $roleService,
			container: $this->createMock(ContainerInterface::class),
		);
		$controller->index();

		self::assertTrue($provided['confidentialCounsellor']);
		self::assertSame('instructor', $provided['primaryRole']);
	}//end testIndexProvidesTheConfidentialCounsellorFlag()
}//end class
