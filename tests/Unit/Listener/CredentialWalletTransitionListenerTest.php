<?php

/**
 * Tests for the listener that writes the Credential wallet self-loop results.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Listener
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

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\Listener\CredentialWalletTransitionListener;
use OCA\Learniq\Service\WalletClaimSyncService;
use OCA\Learniq\Service\WalletOfferDelegationService;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * offerToWallet and recordWalletClaim are self-loops (issued -> issued), and
 * OpenRegister runs neither guards nor actions on a self-loop. Their writes
 * therefore run here, after the save, on ObjectTransitionedEvent (learniq#983).
 */
class CredentialWalletTransitionListenerTest extends TestCase {

	/**
	 * ObjectService mock that records the save.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objectService;

	/**
	 * HTTP client-service mock for the wallet offer call.
	 *
	 * @var IClientService&MockObject
	 */
	private IClientService&MockObject $clientService;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectService::class);
		$this->clientService = $this->createMock(IClientService::class);
	}//end setUp()

	/**
	 * Build the listener under test.
	 *
	 * @return CredentialWalletTransitionListener
	 */
	private function listener(): CredentialWalletTransitionListener {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('token-abc');
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getAbsoluteURL')->willReturnArgument(0);

		return new CredentialWalletTransitionListener(
			objectService: $this->objectService,
			claimService: new WalletClaimSyncService(),
			offerService: new WalletOfferDelegationService(
				clientService: $this->clientService,
				urlGenerator: $urlGenerator,
				appConfig: $appConfig,
				appManager: $this->createMock(IAppManager::class),
				logger: new NullLogger()
			),
			logger: new NullLogger()
		);
	}//end listener()

	/**
	 * A transitioned event for a Credential.
	 *
	 * @param string $action The transition that ran.
	 * @param array<string,mixed> $credential The saved Credential.
	 * @param string $schema The schema slug on the event.
	 *
	 * @return ObjectTransitionedEvent
	 */
	private function event(string $action, array $credential, string $schema = 'credential'): ObjectTransitionedEvent {
		$event = $this->createMock(ObjectTransitionedEvent::class);
		$event->method('getObject')->willReturn(OrEntityFactory::make($credential, $schema));
		$event->method('getAction')->willReturn($action);
		$event->method('getRegister')->willReturn('learniq');
		$event->method('getSchema')->willReturn($schema);
		$event->method('getFrom')->willReturn('issued');
		$event->method('getTo')->willReturn('issued');

		return $event;
	}//end event()

	/**
	 * Capture the object handed to saveObject().
	 *
	 * @return \ArrayObject<string,mixed> Filled with the saved object and uuid once saved.
	 */
	private function captureSave(): \ArrayObject {
		$captured = new \ArrayObject();
		$this->objectService->expects($this->once())->method('saveObject')->willReturnCallback(
			static function (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null) use ($captured) {
				$captured['object'] = $object;
				$captured['register'] = $register;
				$captured['schema'] = $schema;
				$captured['uuid'] = $uuid;

				return OrEntityFactory::make($object, 'credential');
			}
		);

		return $captured;
	}//end captureSave()

	/**
	 * recordWalletClaim saves the claim status and timestamp on the Credential.
	 *
	 * @return void
	 */
	public function testRecordWalletClaimSavesTheClaim(): void {
		$captured = $this->captureSave();

		$this->listener()->handle($this->event('recordWalletClaim', ['id' => 'cred-1', 'lifecycle' => 'issued', 'walletOfferStatus' => 'offered']));

		self::assertSame('claimed', $captured['object']['walletOfferStatus']);
		self::assertNotEmpty($captured['object']['walletClaimedAt']);
		self::assertSame('cred-1', $captured['uuid']);
		self::assertSame('learniq', $captured['register']);
		self::assertSame('credential', $captured['schema']);
	}//end testRecordWalletClaimSavesTheClaim()

	/**
	 * Another schema or another transition saves nothing.
	 *
	 * @return void
	 */
	public function testIgnoresOtherSchemasAndTransitions(): void {
		$this->objectService->expects($this->never())->method('saveObject');

		$this->listener()->handle($this->event('recordWalletClaim', ['id' => 'x-1'], 'report-card'));
		$this->listener()->handle($this->event('revoke', ['id' => 'cred-1', 'walletOfferStatus' => 'offered']));
	}//end testIgnoresOtherSchemasAndTransitions()
	/**
	 * offerToWallet saves the wallet offer fields on the Credential.
	 *
	 * @return void
	 */
	public function testOfferToWalletSavesTheOffer(): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn((string)json_encode(['credentialOfferUri' => 'https://integriq.example/api/eudi/credential-offers/offer-uuid-9']));
		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturn($response);
		$this->clientService->method('newClient')->willReturn($client);
		$captured = $this->captureSave();

		$this->listener()->handle(
			$this->event(
				'offerToWallet',
				['id' => 'cred-2', 'lifecycle' => 'issued', 'kind' => 'badge', 'learnerId' => 'learner-2', 'openbadges3Payload' => ['credentialSubject' => ['id' => 'urn:learner-2']]]
			)
		);

		self::assertSame('offered', $captured['object']['walletOfferStatus']);
		self::assertSame('offer-uuid-9', $captured['object']['walletAttestationRef']);
		self::assertNotEmpty($captured['object']['walletOfferedAt']);
		self::assertNull($captured['object']['walletOfferError']);
		self::assertSame('cred-2', $captured['uuid']);
	}//end testOfferToWalletSavesTheOffer()

	/**
	 * A failed offer saves the reason on the Credential, so the failure is visible.
	 *
	 * @return void
	 */
	public function testFailedOfferSavesTheError(): void {
		$client = $this->createMock(IClient::class);
		$client->method('post')->willThrowException(new \Exception('Connection refused'));
		$this->clientService->method('newClient')->willReturn($client);
		$captured = $this->captureSave();

		$this->listener()->handle(
			$this->event(
				'offerToWallet',
				['id' => 'cred-3', 'lifecycle' => 'issued', 'kind' => 'badge', 'learnerId' => 'learner-3', 'walletOfferStatus' => null, 'openbadges3Payload' => ['credentialSubject' => ['id' => 'urn:learner-3']]]
			)
		);

		self::assertNull($captured['object']['walletOfferStatus']);
		self::assertNotEmpty($captured['object']['walletOfferError']);
	}//end testFailedOfferSavesTheError()
}//end class
