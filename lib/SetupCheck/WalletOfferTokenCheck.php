<?php

/**
 * Learniq Wallet Offer Token Check
 *
 * The admin overview names the missing integriq token that makes every
 * wallet offer fail.
 *
 * @category SetupCheck
 * @package  OCA\Learniq\SetupCheck
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
 * @spec openspec/changes/credential-wallet-offer-for-the-learner/specs/certification/spec.md#requirement-the-setup-check-names-the-missing-wallet-token
 */

declare(strict_types=1);

namespace OCA\Learniq\SetupCheck;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Service\ConnectionReportService;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

/**
 * Names `learniq.openconnector_api_token` when it is empty: a warning once a
 * wallet offer has failed on it, a note while no offer was tried.
 *
 * @spec openspec/changes/credential-wallet-offer-for-the-learner/specs/certification/spec.md#requirement-the-setup-check-names-the-missing-wallet-token
 */
class WalletOfferTokenCheck implements ISetupCheck {

	/**
	 * The app config key of the integriq bearer token.
	 *
	 * @var string
	 */
	public const TOKEN_KEY = 'openconnector_api_token';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig              $appConfig         App config, for the token.
	 * @param ConnectionReportService $connectionReports What the last wallet offer met.
	 * @param IL10N                   $l10n              Translations.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ConnectionReportService $connectionReports,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The admin overview section this check is listed under.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/credential-wallet-offer-for-the-learner/specs/certification/spec.md#requirement-the-setup-check-names-the-missing-wallet-token
	 */
	public function getCategory(): string {
		return 'system';
	}//end getCategory()

	/**
	 * The check's name in the admin overview.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/credential-wallet-offer-for-the-learner/specs/certification/spec.md#requirement-the-setup-check-names-the-missing-wallet-token
	 */
	public function getName(): string {
		return $this->l10n->t('Learniq wallet offers');
	}//end getName()

	/**
	 * Success with the token set; without it, a warning once an offer failed
	 * for it, else a note naming the key.
	 *
	 * @return SetupResult
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) SetupResult is built only through its static constructors.
	 *
	 * @spec openspec/changes/credential-wallet-offer-for-the-learner/specs/certification/spec.md#requirement-the-setup-check-names-the-missing-wallet-token
	 */
	public function run(): SetupResult {
		$token = $this->appConfig->getValueString(app: Application::APP_ID, key: self::TOKEN_KEY, default: '');
		if ($token !== '') {
			return SetupResult::success($this->l10n->t('Certificates can be offered to a wallet through integriq.'));
		}

		$message = $this->l10n->t(
			'No integriq API token is set (%s), so a certificate cannot be offered to a wallet.',
			[Application::APP_ID . '.' . self::TOKEN_KEY]
		);
		if ($this->connectionReports->lastStatus(key: ConnectionReportService::WALLET_KEY) === 'unconfigured') {
			return SetupResult::warning($message);
		}

		return SetupResult::info($message);
	}//end run()
}//end class
