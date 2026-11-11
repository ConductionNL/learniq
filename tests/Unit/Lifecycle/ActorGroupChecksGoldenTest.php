<?php

/**
 * Golden tests for the "is the actor in one of these groups" check seven
 * lifecycle guards share.
 *
 * Each guard answers it for its own group list: an unknown user is not in
 * any group, and otherwise the actor's group ids must meet the list. This
 * pins the answer and the exact user and group lookups, per guard, and the
 * guard's own group list.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Lifecycle
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
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\Lifecycle\ExamAccommodationApprovalGuard;
use OCA\Learniq\Lifecycle\ExternalTrainingVerificationGuard;
use OCA\Learniq\Lifecycle\LvsResultVerifyGuard;
use OCA\Learniq\Lifecycle\MunicipalityFeedbackGuard;
use OCA\Learniq\Lifecycle\OsoImportAcceptGuard;
use OCA\Learniq\Lifecycle\OsoImportRejectGuard;
use OCA\Learniq\Lifecycle\SessionChangeGuard;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Golden tests for the shared actor group check.
 */
class ActorGroupChecksGoldenTest extends TestCase {

	/**
	 * Calls in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $calls = [];

	/**
	 * Each guard, its check method and its group list.
	 *
	 * @return array<string,array{0:class-string,1:string,2:array<int,string>}>
	 */
	public static function guards(): array {
		return [
			'exam accommodation' => [ExamAccommodationApprovalGuard::class, 'actorIsAuthorised', ['admin', 'compliance-officers', 'team-leads']],
			'external training' => [ExternalTrainingVerificationGuard::class, 'actorIsVerifier', ['admin', 'compliance-officers', 'hr']],
			'lvs result' => [LvsResultVerifyGuard::class, 'actorIsAuthorised', ['admin', 'coordinators']],
			'municipality feedback' => [MunicipalityFeedbackGuard::class, 'actorIsAuthorised', ['admin', 'coordinators']],
			'oso accept' => [OsoImportAcceptGuard::class, 'actorIsAuthorised', ['admin', 'coordinators']],
			'oso reject' => [OsoImportRejectGuard::class, 'actorIsAuthorised', ['admin', 'coordinators']],
			'session change' => [SessionChangeGuard::class, 'actorIsOverrideRole', ['admin', 'coordinators']],
		];
	}//end guards()

	/**
	 * Ask the guard's check for one actor.
	 *
	 * @param class-string           $class  The guard.
	 * @param string                 $method Its private check.
	 * @param string                 $actor  The actor.
	 * @param array<int,string>|null $groups The actor's group ids, null for an unknown user.
	 *
	 * @return bool
	 */
	private function ask(string $class, string $method, string $actor, ?array $groups): bool {
		$user = $this->createMock(IUser::class);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(
			function (string $uid) use ($groups, $user): ?IUser {
				$this->calls[] = ['get', $uid];
				return $groups === null ? null : $user;
			}
		);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturnCallback(
			function (IUser $who) use ($groups, $user): array {
				self::assertSame($user, $who);
				$this->calls[] = ['getUserGroupIds'];
				return $groups;
			}
		);

		$args = [];
		foreach ((new ReflectionClass($class))->getConstructor()->getParameters() as $parameter) {
			$type = (string)$parameter->getType();
			$args[] = match ($type) {
				IUserManager::class => $users,
				IGroupManager::class => $groupManager,
				default => $this->createMock($type),
			};
		}

		$check = new ReflectionMethod($class, $method);

		return $check->invoke(new $class(...$args), $actor);
	}//end ask()

	/**
	 * Every group of the list admits; an unknown user and any other groups do not.
	 *
	 * @param class-string      $class  The guard.
	 * @param string            $method Its private check.
	 * @param array<int,string> $list   Its group list.
	 *
	 * @return void
	 */
	#[DataProvider('guards')]
	public function testTheGroupList(string $class, string $method, array $list): void {
		foreach ($list as $group) {
			self::assertTrue($this->ask($class, $method, 'ann', ['staff', $group]), $group);
		}

		self::assertFalse($this->ask($class, $method, 'ann', ['staff', 'teachers', 'mentors', 'ADMIN']));
		self::assertFalse($this->ask($class, $method, 'ann', []));

		$this->calls = [];
		self::assertFalse($this->ask($class, $method, 'ghost', null));
		self::assertSame([['get', 'ghost']], $this->calls);

		$this->calls = [];
		$this->ask($class, $method, 'ann', ['x']);
		self::assertSame([['get', 'ann'], ['getUserGroupIds']], $this->calls);
	}//end testTheGroupList()
}//end class
