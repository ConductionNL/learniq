<?php

/**
 * Tests for the Credential revoke action that records the wallet revocation.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Lifecycle\Action
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
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle\Action;

use OCA\Learniq\Lifecycle\Action\WalletRevocationPropagationAction;
use OCA\Learniq\Service\WalletRevocationPropagationService;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCP\App\IAppManager;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The wallet status the revoke guard used to write into its context now comes
 * back from this action (learniq#983). OpenRegister's executor is not loadable
 * here; the test drives the action directly against the copied interface.
 */
class WalletRevocationPropagationActionTest extends TestCase {

	/**
	 * Build the action over a wallet rail answering with the given body.
	 *
	 * @param string $body The revoke endpoint's response body.
	 *
	 * @return WalletRevocationPropagationAction
	 */
	private function makeAction(string $body): WalletRevocationPropagationAction {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn($body);
		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturn($response);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('token-abc');
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getAbsoluteURL')->willReturnArgument(0);

		return new WalletRevocationPropagationAction(
			new WalletRevocationPropagationService(
				clientService: $clientService,
				urlGenerator: $urlGenerator,
				appConfig: $appConfig,
				appManager: $this->createMock(IAppManager::class),
				logger: new NullLogger()
			)
		);
	}//end makeAction()

	/**
	 * The action is one OpenRegister's executor can run.
	 *
	 * @return void
	 */
	public function testImplementsTheActionInterface(): void {
		self::assertInstanceOf(LifecycleActionInterface::class, $this->makeAction('{}'));
	}//end testImplementsTheActionInterface()

	/**
	 * A confirmed revocation lands `walletOfferStatus=revoked` on the saved object.
	 *
	 * @return void
	 */
	public function testConfirmedRevocationLandsOnTheSavedObject(): void {
		$object = ['id' => 'credential-1', 'lifecycle' => 'revoked', 'walletOfferStatus' => 'offered', 'walletAttestationRef' => 'offer-1'];
		$previous = array_merge($object, ['lifecycle' => 'issued']);

		$saved = $this->makeAction('{"status":"revoked"}')->execute($object, $previous, [], WalletRevocationPropagationAction::class);

		self::assertSame('revoked', $saved['walletOfferStatus']);
		self::assertSame('revoked', $saved['lifecycle']);
	}//end testConfirmedRevocationLandsOnTheSavedObject()

	/**
	 * A failed propagation records the error on the saved object and does not throw:
	 * the revoke itself must go through (fail-soft by spec).
	 *
	 * @return void
	 */
	public function testFailedPropagationRecordsTheErrorOnTheSavedObject(): void {
		$object = ['id' => 'credential-2', 'lifecycle' => 'revoked', 'walletOfferStatus' => 'claimed', 'walletAttestationRef' => 'offer-2'];

		$saved = $this->makeAction('{"status":"pending"}')->execute($object, [], [], WalletRevocationPropagationAction::class);

		self::assertSame('claimed', $saved['walletOfferStatus']);
		self::assertNotEmpty($saved['walletOfferError']);
	}//end testFailedPropagationRecordsTheErrorOnTheSavedObject()
}//end class
