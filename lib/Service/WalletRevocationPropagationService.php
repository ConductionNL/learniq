<?php

/**
 * Learniq Wallet Revocation Propagation Service
 *
 * Lifecycle guard for the Credential schema's `revoke` transition. When a
 * credential with an outstanding wallet offer is revoked, propagates the
 * revocation to openconnector's OpenID4VCI adapter so the wallet-held
 * attestation is flagged revoked (status-list bit flip).
 *
 * DEVIATION FROM THE ORIGINAL DESIGN — see
 * {@see WalletOfferDelegationService}'s docblock for the full rationale: the
 * ADR-041 typed-event contract this class's tasks.md entry specifies
 * (`WalletRevocationRequestedEvent`) does not exist and was never built by
 * openconnector's merged companion change. This class instead calls
 * openconnector's real, routed REST endpoint
 * (`POST /api/eudi/credential-offers/{id}/revoke`,
 * `EudiWalletController::revoke()`), reusing the same
 * `learniq.openconnector_api_token` bearer-credential convention as
 * {@see WalletOfferDelegationService} (same AUTH GAP applies: the endpoint
 * expects a consumer JWT, not a generic static token).
 *
 * Legitimate PHP per ADR-031: "Lifecycle guard — business rule that must run
 * before a state transition and cannot be expressed as a schema declaration."
 * Referenced from the Credential schema's
 * x-openregister-lifecycle.transitions.revoke.requires in
 * learniq_register.json, as a LifecycleGuardInterface guard; the write runs
 * in the transition's WalletRevocationPropagationAction (learniq#983).
 *
 * FAIL-SOFT BY DESIGN (per spec): revoking a credential is the compliance
 * action of record and MUST NOT be blocked by the wallet rail being
 * unavailable. This guard therefore always allows, and the propagation
 * catches every `Throwable` and records it rather than surfacing a
 * transition error.
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
 * @spec openspec/changes/eudi-wallet-credential-push/specs/certification/spec.md#requirement-revoking-a-credential-propagates-to-any-outstanding-wallet-offer-fail-soft
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\Learniq\Support\FleetAppId;
use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Guards the Credential `revoke` transition (as an additional `requires`
 * hook, fail-soft).
 *
 * The guard always allows. propagate(), run by the transition's
 * WalletRevocationPropagationAction, no-ops unless `walletOfferStatus` is
 * `offered` or `claimed` — nothing to revoke otherwise. Otherwise calls
 * openconnector's revoke endpoint best-effort and, on a confirmed success,
 * sets `walletOfferStatus=revoked` on the returned Credential. Any failure (missing
 * config, HTTP error, thrown exception) is logged and swallowed — the
 * `revoke` transition itself is never blocked.
 */
class WalletRevocationPropagationService implements LifecycleGuardInterface {

	/**
	 * OpenConnector REST endpoint template for consumer-gated offer
	 * revocation (openconnector `eudi-wallet-credential-issuance` REQ-EUDI-009,
	 * `appinfo/routes.php`: `eudiWallet#revoke`). `%s` is the offer uuid
	 * (Credential.walletAttestationRef).
	 *
	 * @var string
	 */
	/**
	 * Path AFTER the app segment; the segment is resolved at call time.
	 *
	 * @var string
	 */
	private const OPENCONNECTOR_REVOKE_PATH = 'api/eudi/credential-offers/%s/revoke';

	/**
	 * App-config key for the OpenConnector bearer credential. Same key
	 * {@see WalletOfferDelegationService} uses.
	 *
	 * @var string
	 */
	private const OPENCONNECTOR_TOKEN_KEY = 'openconnector_api_token';

	/**
	 * Wallet-offer statuses that still have something outstanding to revoke.
	 *
	 * @var string[]
	 */
	private const OUTSTANDING_STATUSES = ['offered', 'claimed'];

	/**
	 * Constructor.
	 *
	 * @param IClientService $clientService NC HTTP client factory.
	 * @param IURLGenerator $urlGenerator NC URL generator for internal requests.
	 * @param IAppConfig $appConfig NC app config for token lookup.
	 * @param IAppManager $appManager NC app manager. Resolving the fleet app id
	 *                                needs it, and it arrives as a dependency now
	 *                                rather than out of the global server.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IClientService $clientService,
		private readonly IURLGenerator $urlGenerator,
		private readonly IAppConfig $appConfig,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * OpenRegister lifecycle guard entry-point for `revoke`.
	 *
	 * Always allows: revoking is the compliance action of record and MUST NOT
	 * be blocked by the wallet rail. The propagation runs in
	 * {@see \OCA\Learniq\Lifecycle\Action\WalletRevocationPropagationAction},
	 * because OpenRegister hands a guard the object by value (learniq#983).
	 *
	 * @param array<string,mixed> $object The Credential as it would be saved.
	 * @param string $action The transition, `revoke`.
	 * @param string $userId The caller, or '' without a session.
	 *
	 * @return GuardResult Always allow (fail-soft by design).
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The interface fixes the signature.
	 *
	 * @spec openspec/changes/eudi-wallet-credential-push/specs/certification/spec.md#requirement-revoking-a-credential-propagates-to-any-outstanding-wallet-offer-fail-soft
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		return GuardResult::allow();
	}//end check()

	/**
	 * Propagate a revocation to any outstanding wallet offer, best-effort.
	 *
	 * No-op (credential returned unchanged, no call made) unless there is an
	 * outstanding offer with a correlation key. On a confirmed revocation sets
	 * `walletOfferStatus=revoked`; on any failure records `walletOfferError`
	 * and never throws.
	 *
	 * @param array<string,mixed> $credential The Credential data array.
	 *
	 * @return array<string,mixed> The Credential with the propagation outcome applied.
	 *
	 * @spec openspec/changes/eudi-wallet-credential-push/specs/certification/spec.md#requirement-revoking-a-credential-propagates-to-any-outstanding-wallet-offer-fail-soft
	 */
	public function propagate(array $credential): array {
		$walletOfferStatus = ($credential['walletOfferStatus'] ?? null);
		if (in_array($walletOfferStatus, self::OUTSTANDING_STATUSES, true) === false) {
			// Nothing outstanding to revoke — no-op.
			return $credential;
		}

		$attestationRef = (string)($credential['walletAttestationRef'] ?? '');
		if ($attestationRef === '') {
			// No correlation key to revoke by — nothing we can propagate.
			return $credential;
		}

		try {
			$handled = $this->callOpenConnectorRevoke(attestationRef: $attestationRef);
			if ($handled === true) {
				$credential['walletOfferStatus'] = 'revoked';
				$this->logger->info(
					'[WalletRevocationPropagationService] Propagated revocation for wallet offer {ref}',
					['ref' => $attestationRef]
				);
			}

			if ($handled === false) {
				$credential['walletOfferError'] = 'Wallet revocation propagation failed or openconnector is unavailable.';
				$this->logger->warning(
					'[WalletRevocationPropagationService] Revocation propagation for wallet offer {ref} did not succeed',
					['ref' => $attestationRef]
				);
			}
		} catch (Throwable $exception) {
			// Fail-soft by design: never block revoke on the wallet rail.
			$credential['walletOfferError'] = 'Wallet revocation propagation error: ' . $exception->getMessage();
			$this->logger->warning(
				'[WalletRevocationPropagationService] Revocation propagation threw: {msg}',
				['msg' => $exception->getMessage()]
			);
		}//end try

		return $credential;
	}//end propagate()

	/**
	 * Call openconnector's consumer-gated offer-revocation endpoint.
	 *
	 * @param string $attestationRef The offer uuid to revoke.
	 *
	 * @return bool True when openconnector confirmed the revocation (or it was already revoked).
	 */
	private function callOpenConnectorRevoke(string $attestationRef): bool {
		$path = FleetAppId::path($this->appManager, 'integriq', sprintf(self::OPENCONNECTOR_REVOKE_PATH, rawurlencode($attestationRef)));
		$url = $this->urlGenerator->getAbsoluteURL('/index.php' . $path);

		$apiToken = $this->appConfig->getValueString(
			app: 'learniq',
			key: self::OPENCONNECTOR_TOKEN_KEY,
			default: ''
		);

		if ($apiToken === '') {
			$this->logger->warning(
				'[WalletRevocationPropagationService] No OpenConnector API token configured ('
				. 'learniq.openconnector_api_token); the revocation call will fail with 401.'
			);
			return false;
		}

		$requestOptions = [
			'timeout' => 30,
			'headers' => [
				'Authorization' => 'Bearer ' . $apiToken,
			],
		];

		$client = $this->clientService->newClient();
		$response = $client->post($url, $requestOptions);

		$body = json_decode($response->getBody(), true);

		return (is_array($body) === true && ($body['status'] ?? null) === 'revoked');
	}//end callOpenConnectorRevoke()
}//end class
