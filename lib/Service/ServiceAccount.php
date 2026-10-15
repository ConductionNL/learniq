<?php

/**
 * Learniq Service Account
 *
 * The Nextcloud account learniq writes as when the person asking holds no
 * right on the object. A learner may start a wallet offer for their own
 * certificate, but only staff may update a certificate. Learniq checks the
 * learner itself, then runs the write as this account with OpenRegister's
 * checks on; the schemas it writes grant the `learniq-service` group.
 *
 * An admin picks the account (`learniq.service_account`) and puts it in the
 * group. A missing, unknown, disabled or ungrouped account refuses before
 * anything runs. The identity is set with
 * `IUserSession::setVolatileActiveUser()` and the previous user is restored
 * in a `finally`; it never reaches the PHP session.
 *
 * @category Service
 * @package  OCA\Learniq\Service
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

namespace OCA\Learniq\Service;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Exception\ServiceAccountUnavailableException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Resolves the configured service account and runs operations as it.
 *
 * @spec openspec/changes/credential-wallet-offer-for-the-learner/specs/certification/spec.md#requirement-the-holder-starts-the-wallet-offer
 */
class ServiceAccount {

	/**
	 * App-config key holding the uid of the service account.
	 *
	 * @var string
	 */
	public const CONFIG_KEY = 'service_account';

	/**
	 * The group the written schemas grant read and update to.
	 *
	 * @var string
	 */
	public const GROUP = 'learniq-service';

	/**
	 * Why the account cannot be used: none is set.
	 *
	 * @var string
	 */
	public const REASON_UNSET = 'unset';

	/**
	 * Why the account cannot be used: the uid names no account.
	 *
	 * @var string
	 */
	public const REASON_UNKNOWN = 'unknown';

	/**
	 * Why the account cannot be used: the account is disabled.
	 *
	 * @var string
	 */
	public const REASON_DISABLED = 'disabled';

	/**
	 * Why the account cannot be used: it is not in the service group.
	 *
	 * @var string
	 */
	public const REASON_NOT_A_MEMBER = 'not-in-group';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig      $appConfig    Holds the chosen uid.
	 * @param IUserManager    $userManager  Resolves the uid to an account.
	 * @param IGroupManager   $groupManager Checks the group membership.
	 * @param IUserSession    $userSession  Carries the acting user for one operation.
	 * @param LoggerInterface $logger       Logs every refusal.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * What stops the account from acting, or null when nothing does.
	 *
	 * @return string|null One of the REASON_* constants, or null.
	 *
	 * @spec openspec/changes/credential-wallet-offer-for-the-learner/specs/certification/spec.md#requirement-the-holder-starts-the-wallet-offer
	 */
	public function problem(): ?string {
		$userId = $this->configuredUserId();
		if ($userId === '') {
			return self::REASON_UNSET;
		}

		$account = $this->userManager->get($userId);
		if ($account === null) {
			return self::REASON_UNKNOWN;
		}

		if ($account->isEnabled() === false) {
			return self::REASON_DISABLED;
		}

		if ($this->groupManager->isInGroup($userId, self::GROUP) === false) {
			return self::REASON_NOT_A_MEMBER;
		}

		return null;
	}//end problem()

	/**
	 * Run an operation as the account, restoring the previous user afterwards.
	 *
	 * @param callable $operation The operation.
	 *
	 * @return mixed Whatever the operation returns.
	 *
	 * @throws ServiceAccountUnavailableException When the account cannot be used; nothing ran.
	 *
	 * @spec openspec/changes/credential-wallet-offer-for-the-learner/specs/certification/spec.md#requirement-the-holder-starts-the-wallet-offer
	 */
	public function runAs(callable $operation): mixed {
		$account = $this->account();
		$previous = $this->userSession->getUser();
		$this->userSession->setVolatileActiveUser($account);

		try {
			return $operation();
		} finally {
			$this->userSession->setVolatileActiveUser($previous);
		}
	}//end runAs()

	/**
	 * The usable account, or a logged refusal.
	 *
	 * @return IUser The enabled account, a member of the service group.
	 *
	 * @throws ServiceAccountUnavailableException When the account cannot be used.
	 */
	private function account(): IUser {
		$reason = $this->problem();
		$account = null;
		if ($reason === null) {
			$account = $this->userManager->get($this->configuredUserId());
		}

		if ($account === null) {
			$reason = ($reason ?? self::REASON_UNKNOWN);
			$this->logger->error(
				'Learniq: no usable service account ({reason}); set learniq.{key} to an enabled account in the group {group}.',
				['app' => Application::APP_ID, 'reason' => $reason, 'key' => self::CONFIG_KEY, 'group' => self::GROUP]
			);
			throw new ServiceAccountUnavailableException('No usable service account (' . $reason . ').');
		}

		return $account;
	}//end account()

	/**
	 * The configured uid, or an empty string.
	 *
	 * @return string
	 */
	private function configuredUserId(): string {
		return trim($this->appConfig->getValueString(Application::APP_ID, self::CONFIG_KEY, ''));
	}//end configuredUserId()
}//end class
