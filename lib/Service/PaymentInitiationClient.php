<?php

/**
 * Scholiq Payment Initiation Client
 *
 * Outbound transport collaborator for `PaymentTransactionController`: performs
 * the authenticated call against OpenConnector's PSP launch-initiation endpoint
 * and hands the response back verbatim.
 *
 * Per the payments spec, scholiq implements NO PSP wire protocol: this client
 * forwards an opaque request and returns the opaque response without inspecting
 * a single PSP-specific claim. Keeping it out of the controller leaves the
 * controller with only the HTTP-boundary concerns (auth, validation, lifecycle).
 *
 * @category Service
 * @package  OCA\Scholiq\Service
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
 * @spec openspec/changes/school-payments/tasks.md#task-3.5
 */

declare(strict_types=1);

namespace OCA\Scholiq\Service;

use OCA\Scholiq\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Calls OpenConnector's PSP launch-initiation endpoint on scholiq's behalf.
 *
 * @psalm-api
 *
 * @spec openspec/changes/school-payments/specs/payments/spec.md#requirement-payment-initiation-and-status-delegate-entirely-to-openconnector-scholiq-implements-no-psp-wire-protocol
 */
class PaymentInitiationClient {

	/**
	 * ASSUMED OpenConnector REST endpoint for PSP launch-initiation
	 * (mirrors LtiToolPlacementController::OPENCONNECTOR_LAUNCH_PATH's
	 * "documented assumption" convention). OpenConnector's own
	 * mollie-stripe-payment-adapter does not exist yet at HEAD (see
	 * proposal.md "Why") — this constant names the path that adapter would
	 * need to expose, following the same path-shape convention as the
	 * existing lti/deployments and sources endpoints. Update once the real
	 * endpoint lands.
	 *
	 * Assumed request body: {orderId, amount, currency, pspProvider,
	 * callbackReference} where callbackReference is the PaymentTransaction's
	 * own scholiq-side id, echoed back on the callback() call.
	 * Assumed response body: {checkoutUrl: string, pspPaymentId?: string}.
	 *
	 * @var string
	 */
	private const OPENCONNECTOR_INITIATE_PATH = '/apps/openconnector/api/payments/initiate';

	/**
	 * App-config key for the outbound OpenConnector API token. Same key
	 * LtiToolPlacementController/DataExchangeRunHandler already use.
	 *
	 * @var string
	 */
	private const OPENCONNECTOR_TOKEN_KEY = 'openconnector_api_token';

	/**
	 * Constructor.
	 *
	 * @param IClientService $clientService NC HTTP client factory.
	 * @param IURLGenerator $urlGenerator NC URL generator for internal requests.
	 * @param IAppConfig $appConfig NC app config for token lookup.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private readonly IClientService $clientService,
		private readonly IURLGenerator $urlGenerator,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Call OpenConnector's (assumed, see {@see self::OPENCONNECTOR_INITIATE_PATH})
	 * PSP launch-initiation endpoint.
	 *
	 * @param string $paymentTransactionId UUID of the newly-created PaymentTransaction —
	 *                                     sent as the callback reference.
	 * @param string $orderId UUID of the Order being paid.
	 * @param float $amount Amount to charge.
	 * @param string $currency ISO 4217 currency code.
	 * @param string $pspProvider "mollie" or "stripe".
	 *
	 * @return array<string,mixed>|null The opaque launch response, or null on failure.
	 *
	 * @spec openspec/changes/school-payments/tasks.md#task-3.5
	 */
	public function initiate(
		string $paymentTransactionId,
		string $orderId,
		float $amount,
		string $currency,
		string $pspProvider,
	): ?array {
		$url = $this->urlGenerator->getAbsoluteURL('/index.php' . self::OPENCONNECTOR_INITIATE_PATH);

		$apiToken = $this->appConfig->getValueString(
			app: Application::APP_ID,
			key: self::OPENCONNECTOR_TOKEN_KEY,
			default: ''
		);

		$requestOptions = [
			'json' => [
				'orderId' => $orderId,
				'amount' => $amount,
				'currency' => $currency,
				'pspProvider' => $pspProvider,
				'callbackReference' => $paymentTransactionId,
			],
			'timeout' => 30,
		];

		if ($apiToken === '') {
			$this->logger->warning(
				'[PaymentInitiationClient] No OpenConnector API token configured ('
				. 'scholiq.openconnector_api_token); the initiate call may fail with 401/403.'
			);
		}

		if ($apiToken !== '') {
			$requestOptions['headers'] = [
				'Authorization' => 'Bearer ' . $apiToken,
			];
		}

		try {
			$client = $this->clientService->newClient();
			$response = $client->post($url, $requestOptions);

			$body = json_decode($response->getBody(), true);
			if (is_array($body) === false) {
				$this->logger->error('[PaymentInitiationClient] OpenConnector returned non-JSON for initiate.');
				return null;
			}

			return $body;
		} catch (Throwable $exception) {
			$this->logger->error(
				'[PaymentInitiationClient] OpenConnector initiate call failed: {msg}',
				['msg' => $exception->getMessage()]
			);
			return null;
		}//end try

	}//end initiate()
}//end class
