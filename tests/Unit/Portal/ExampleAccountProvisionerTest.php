<?php

/**
 * Learniq ExampleAccountProvisioner unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Portal
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/example-portal-declares-its-site/specs/example-sets/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Portal;

use OCA\Learniq\Portal\ExampleAccountProvisioner;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Creating and naming the staff accounts a portal names.
 */
class ExampleAccountProvisionerTest extends TestCase {

	/**
	 * A user double with a display name that can be changed.
	 *
	 * @param string $uid  The user id.
	 * @param string $name The display name.
	 *
	 * @return IUser
	 */
	private function user(string $uid, string $name): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturnCallback(static function () use (&$name): string {
			return $name;
		});
		$user->method('setDisplayName')->willReturnCallback(static function (string $next) use (&$name): bool {
			$name = $next;
			return true;
		});
		return $user;
	}//end user()

	/**
	 * A missing account is created with the name; an unnamed one is named; a chosen name is kept.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-portal-declares-its-site/specs/example-sets/spec.md#requirement-the-staff-a-portal-names-have-accounts-with-those-names
	 */
	public function testAccountsAreCreatedOrNamedAndAChosenNameIsKept(): void {
		$unnamed = $this->user(uid: 'po-leerkracht-07', name: 'po-leerkracht-07');
		$chosen  = $this->user(uid: 'po-ib-01', name: 'Marieke (IB)');
		$created = $this->user(uid: 'po-leerkracht-09', name: 'po-leerkracht-09');

		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnMap([
			['po-leerkracht-09', null],
			['po-leerkracht-07', $unnamed],
			['po-ib-01', $chosen],
		]);
		$users->expects(self::once())->method('createUser')
			->with('po-leerkracht-09', self::callback(static fn (string $password): bool => strlen($password) === 72))
			->willReturn($created);

		$instructors = $this->createMock(IGroup::class);
		$instructors->method('inGroup')->willReturn(false);
		$instructors->expects(self::exactly(2))->method('addUser');
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturnCallback(static fn (string $id): ?IGroup => ($id === 'instructors' ? $instructors : null));

		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn(str_repeat('x', 72));

		$counts = (new ExampleAccountProvisioner($users, $groups, $random, $this->createMock(LoggerInterface::class)))->provision([
			['userId' => 'po-leerkracht-09', 'displayName' => 'Meester Daan', 'groups' => ['instructors']],
			['userId' => 'po-leerkracht-07', 'displayName' => 'Juf Esra', 'groups' => ['instructors']],
			['userId' => 'po-ib-01', 'displayName' => 'Marieke de Wit', 'groups' => ['coordinators']],
			['userId' => '', 'displayName' => 'Nobody'],
		]);

		self::assertSame(['created' => 1, 'named' => 1, 'kept' => 1, 'failed' => 0], $counts);
		self::assertSame('Meester Daan', $created->getDisplayName());
		self::assertSame('Juf Esra', $unnamed->getDisplayName());
		self::assertSame('Marieke (IB)', $chosen->getDisplayName());
	}//end testAccountsAreCreatedOrNamedAndAChosenNameIsKept()

	/**
	 * A backend that refuses is counted as failed, not thrown.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-portal-declares-its-site/specs/example-sets/spec.md#requirement-the-staff-a-portal-names-have-accounts-with-those-names
	 */
	public function testARefusedAccountIsCountedAsFailed(): void {
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn(null);
		$users->method('createUser')->willReturn(false);
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn(str_repeat('x', 72));

		$counts = (new ExampleAccountProvisioner($users, $this->createMock(IGroupManager::class), $random, $this->createMock(LoggerInterface::class)))
			->provision([['userId' => 'po-directeur-01', 'displayName' => 'Hanneke Postma']]);

		self::assertSame(['created' => 0, 'named' => 0, 'kept' => 0, 'failed' => 1], $counts);
	}//end testARefusedAccountIsCountedAsFailed()
}//end class
