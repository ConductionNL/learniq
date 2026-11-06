<?php

/**
 * Controllers that check group membership keep the caller's session and group manager.
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
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\LeaderboardController;
use OCA\Learniq\Controller\PeerReviewController;
use OCA\Learniq\Controller\PortalEmployerInviteController;
use OCA\Learniq\Controller\PortalGuardianController;
use OCA\Learniq\Controller\RoomUtilisationController;
use OCA\Learniq\Service\CallerGroups;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * Builds each controller from its constructor signature and reads back the
 * session and the group manager its guards use.
 *
 * @covers \OCA\Learniq\Controller\LeaderboardController
 * @covers \OCA\Learniq\Controller\PeerReviewController
 * @covers \OCA\Learniq\Controller\PortalEmployerInviteController
 * @covers \OCA\Learniq\Controller\PortalGuardianController
 * @covers \OCA\Learniq\Controller\RoomUtilisationController
 * @uses   \OCA\Learniq\Service\CallerGroups
 */
class CallerGroupsWiringTest extends TestCase {

	/**
	 * The controllers and the property that holds their group manager.
	 *
	 * @return array<string, array{0: class-string, 1: string}>
	 */
	public static function controllers(): array {
		return [
			'leaderboard' => [LeaderboardController::class, 'groupManager'],
			'peer review' => [PeerReviewController::class, 'groupManager'],
			'employer invite' => [PortalEmployerInviteController::class, 'groups'],
			'guardian' => [PortalGuardianController::class, 'groups'],
			'room utilisation' => [RoomUtilisationController::class, 'groupManager'],
		];
	}//end controllers()

	/**
	 * The guards read the very session and group manager the controller was built with.
	 *
	 * @param class-string $controller The controller class.
	 * @param string       $property   The group manager property.
	 *
	 * @return void
	 */
	#[DataProvider('controllers')]
	public function testTheGuardsUseTheCallersSessionAndGroups(string $controller, string $property): void {
		$session = $this->createMock(IUserSession::class);
		$groups = $this->createMock(IGroupManager::class);

		$arguments = [];
		foreach ((new ReflectionClass($controller))->getConstructor()->getParameters() as $parameter) {
			$type = $parameter->getType();
			self::assertInstanceOf(ReflectionNamedType::class, $type);
			$arguments[] = match ($type->getName()) {
				IUserSession::class => $session,
				IGroupManager::class => $groups,
				CallerGroups::class => new CallerGroups(userSession: $session, groupManager: $groups),
				default => (new ReflectionClass($type->getName()))->isFinal() === true
					? (new ReflectionClass($type->getName()))->newInstanceWithoutConstructor()
					: $this->createMock($type->getName()),
			};
		}

		$instance = (new ReflectionClass($controller))->newInstanceArgs($arguments);

		self::assertSame($session, (new ReflectionProperty($controller, 'userSession'))->getValue($instance));
		self::assertSame($groups, (new ReflectionProperty($controller, $property))->getValue($instance));
	}//end testTheGuardsUseTheCallersSessionAndGroups()
}//end class
