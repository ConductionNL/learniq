<?php

/**
 * Unit tests for StoreAccessService: the store buttons a user sees mirror the
 * checks the store endpoints make (D27).
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\CourseStore
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/course-management/spec.md#requirement-the-store-page-shows-each-user-the-actions-they-may-take
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\CourseStore;

use OCA\Learniq\Service\ActionAuthService;
use OCA\Learniq\Service\CourseStore\CourseStorePublisher;
use OCA\Learniq\Service\CourseStore\StoreAccessService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Learniq\Service\CourseStore\StoreAccessService
 */
class StoreAccessServiceTest extends TestCase {

	/**
	 * Build the service over a matrix that admits the given actions.
	 *
	 * @param array<int, string> $actions       Actions the matrix admits for the user.
	 * @param bool               $supported     Whether OpenRegister can publish.
	 * @param bool               $planeAdmits   Whether the plane's authorizer admits the user.
	 * @param IUser|null         $sessionUser   The signed-in user, for forCurrentUser().
	 *
	 * @return StoreAccessService
	 */
	private function service(array $actions, bool $supported=true, bool $planeAdmits=true, ?IUser $sessionUser=null): StoreAccessService {
		$actionAuth = $this->createMock(ActionAuthService::class);
		$actionAuth->method('can')->willReturnCallback(
			static fn (IUser $user, string $action): bool => in_array($action, $actions, true)
		);

		$publisher = $this->createMock(CourseStorePublisher::class);
		$publisher->method('supportsPublish')->willReturn($supported);
		$publisher->method('mayPublish')->willReturn($planeAdmits);

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($sessionUser);

		return new StoreAccessService($actionAuth, $publisher, $userSession);
	}//end service()

	/**
	 * A teacher installs and does not publish.
	 *
	 * @return void
	 */
	public function testATeacherInstallsButDoesNotPublish(): void {
		$access = $this->service(['course-store.install'])->forUser($this->createMock(IUser::class));

		self::assertSame(['install' => true, 'publish' => false], $access);
	}//end testATeacherInstallsButDoesNotPublish()

	/**
	 * A team lead the matrix and the plane admit does both.
	 *
	 * @return void
	 */
	public function testATeamLeadInstallsAndPublishes(): void {
		$access = $this->service(['course-store.install', 'course-package.share'])->forUser($this->createMock(IUser::class));

		self::assertSame(['install' => true, 'publish' => true], $access);
	}//end testATeamLeadInstallsAndPublishes()

	/**
	 * Publish shows only when every check the endpoint makes would pass.
	 *
	 * @return void
	 */
	public function testPublishNeedsTheMatrixThePlaneAndAPublishingOpenRegister(): void {
		$user = $this->createMock(IUser::class);

		self::assertFalse($this->service(['course-package.share'], false, true)->forUser($user)['publish'], 'older OpenRegister');
		self::assertFalse($this->service(['course-package.share'], true, false)->forUser($user)['publish'], 'plane refuses');
		self::assertFalse($this->service([], true, true)->forUser($user)['publish'], 'matrix refuses');
	}//end testPublishNeedsTheMatrixThePlaneAndAPublishingOpenRegister()

	/**
	 * A learner the matrix admits for nothing sees no store action.
	 *
	 * @return void
	 */
	public function testALearnerSeesNoStoreAction(): void {
		self::assertSame(['install' => false, 'publish' => false], $this->service([])->forUser($this->createMock(IUser::class)));
	}//end testALearnerSeesNoStoreAction()

	/**
	 * The page asks for the signed-in user; without a session there is none.
	 *
	 * @return void
	 */
	public function testForCurrentUserReadsTheSession(): void {
		$signedIn = $this->service(['course-store.install'], true, true, $this->createMock(IUser::class));
		self::assertSame(['install' => true, 'publish' => false], $signedIn->forCurrentUser());

		$anonymous = $this->service(['course-store.install', 'course-package.share']);
		self::assertSame(['install' => false, 'publish' => false], $anonymous->forCurrentUser());
	}//end testForCurrentUserReadsTheSession()
}//end class
