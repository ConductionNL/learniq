<?php

/**
 * The admin overview names the missing integriq token for wallet offers.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\SetupCheck
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

namespace OCA\Learniq\Tests\Unit\SetupCheck;

use OCA\Learniq\Service\ConnectionReportService;
use OCA\Learniq\SetupCheck\WalletOfferTokenCheck;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\SetupCheck\SetupResult;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Learniq\SetupCheck\WalletOfferTokenCheck
 * @uses   \OCA\Learniq\Service\ConnectionReportService
 */
class WalletOfferTokenCheckTest extends TestCase {

	/**
	 * With the token set, offers can go out.
	 *
	 * @return void
	 */
	public function testATokenIsASuccess(): void {
		$result = $this->check(token: 'token-abc', observation: '')->run();

		self::assertSame(SetupResult::SUCCESS, $result->getSeverity());
	}//end testATokenIsASuccess()

	/**
	 * Without the token, after an offer failed on it, the check warns and names the key.
	 *
	 * @return void
	 */
	public function testAFailedOfferWithoutATokenWarnsAndNamesTheKey(): void {
		$observation = json_encode(['status' => 'unconfigured', 'reason' => 'No integriq API token is set.', 'since' => '2026-10-15 12:00']);
		$result = $this->check(token: '', observation: (string)$observation)->run();

		self::assertSame(SetupResult::WARNING, $result->getSeverity());
		self::assertStringContainsString('learniq.openconnector_api_token', (string)$result->getDescription());
	}//end testAFailedOfferWithoutATokenWarnsAndNamesTheKey()

	/**
	 * Without the token and before any offer, the check only notes the key.
	 *
	 * @return void
	 */
	public function testNoTokenBeforeAnyOfferIsANote(): void {
		$result = $this->check(token: '', observation: '')->run();

		self::assertSame(SetupResult::INFO, $result->getSeverity());
		self::assertStringContainsString('learniq.openconnector_api_token', (string)$result->getDescription());
	}//end testNoTokenBeforeAnyOfferIsANote()

	/**
	 * The check over an app config holding the token and the wallet observation.
	 *
	 * @param string $token       The stored token.
	 * @param string $observation The stored wallet observation, JSON or ''.
	 *
	 * @return WalletOfferTokenCheck
	 */
	private function check(string $token, string $observation): WalletOfferTokenCheck {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key): string => match ($key) {
				WalletOfferTokenCheck::TOKEN_KEY => $token,
				ConnectionReportService::OBSERVATION_KEY_PREFIX . ConnectionReportService::WALLET_KEY => $observation,
				default => '',
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters=[]): string => vsprintf($text, $parameters));
		$reports = new ConnectionReportService(
			$appConfig,
			$this->createMock(IEventDispatcher::class),
			$this->createMock(ITimeFactory::class),
			new NullLogger(),
			$this->createMock(IAppManager::class)
		);

		return new WalletOfferTokenCheck(appConfig: $appConfig, connectionReports: $reports, l10n: $l10n);
	}//end check()
}//end class
