<?php

/**
 * Learniq ServiceAccount unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
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
 * @spec openspec/changes/credential-wallet-offer-for-the-learner/specs/certification/spec.md#requirement-the-holder-starts-the-wallet-offer
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Exception\ServiceAccountUnavailableException;
use OCA\Learniq\Service\ServiceAccount;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * When the account can act, and that the caller is restored.
 *
 * @covers \OCA\Learniq\Service\ServiceAccount
 * @uses   \OCA\Learniq\Exception\ServiceAccountUnavailableException
 */
class ServiceAccountTest extends TestCase {

	/**
	 * The user the session carries right now.
	 *
	 * @var IUser|null
	 */
	private ?IUser $current = null;

	/**
	 * A user with this uid.
	 *
	 * @param string $uid     The uid.
	 * @param bool   $enabled Whether the account is enabled.
	 *
	 * @return IUser
	 */
	private function user(string $uid, bool $enabled = true): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('isEnabled')->willReturn($enabled);
		return $user;
	}//end user()

	/**
	 * The account under test.
	 *
	 * @param string $configured The configured uid.
	 * @param bool   $enabled    Whether that account is enabled.
	 * @param bool   $member     Whether it is in the service group.
	 *
	 * @return ServiceAccount
	 */
	private function account(string $configured, bool $enabled = true, bool $member = true): ServiceAccount {
		$this->current = $this->user('f.dejong');
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key): string => ($app === 'learniq' && $key === ServiceAccount::CONFIG_KEY) ? $configured : ''
		);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(fn (string $uid): ?IUser => ($uid === 'learniq-bot') ? $this->user($uid, $enabled) : null);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $group): bool => $member === true && $group === ServiceAccount::GROUP
		);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->current);
		$session->method('setVolatileActiveUser')->willReturnCallback(
			function (?IUser $user): void {
				$this->current = $user;
			}
		);

		return new ServiceAccount(appConfig: $appConfig, userManager: $users, groupManager: $groups, userSession: $session, logger: new NullLogger());
	}//end account()

	/**
	 * Each reason the account cannot act, and none for a good one.
	 *
	 * @return void
	 */
	public function testProblemNamesWhyTheAccountCannotAct(): void {
		self::assertSame(ServiceAccount::REASON_UNSET, $this->account(configured: ' ')->problem());
		self::assertSame(ServiceAccount::REASON_UNKNOWN, $this->account(configured: 'nobody')->problem());
		self::assertSame(ServiceAccount::REASON_DISABLED, $this->account(configured: 'learniq-bot', enabled: false)->problem());
		self::assertSame(ServiceAccount::REASON_NOT_A_MEMBER, $this->account(configured: 'learniq-bot', member: false)->problem());
		self::assertNull($this->account(configured: 'learniq-bot')->problem());
	}//end testProblemNamesWhyTheAccountCannotAct()

	/**
	 * The operation runs as the account and the caller is back afterwards, also after a throw.
	 *
	 * @return void
	 */
	public function testRunAsActsAsTheAccountAndRestoresTheCaller(): void {
		$account = $this->account(configured: 'learniq-bot');

		self::assertSame('learniq-bot', $account->runAs(fn (): ?string => $this->current?->getUID()));
		self::assertSame('f.dejong', $this->current?->getUID());

		try {
			$account->runAs(static function (): never {
				throw new RuntimeException('guard said no');
			});
			self::fail('The throw must reach the caller.');
		} catch (RuntimeException $e) {
			self::assertSame('guard said no', $e->getMessage());
		}

		self::assertSame('f.dejong', $this->current?->getUID());
	}//end testRunAsActsAsTheAccountAndRestoresTheCaller()

	/**
	 * Without a usable account nothing runs.
	 *
	 * @return void
	 */
	public function testRunAsRefusesBeforeAnythingRuns(): void {
		$ran = false;
		$this->expectException(ServiceAccountUnavailableException::class);

		try {
			$this->account(configured: '')->runAs(static function () use (&$ran): void {
				$ran = true;
			});
		} finally {
			self::assertFalse($ran);
			self::assertSame('f.dejong', $this->current?->getUID());
		}
	}//end testRunAsRefusesBeforeAnythingRuns()
}//end class
