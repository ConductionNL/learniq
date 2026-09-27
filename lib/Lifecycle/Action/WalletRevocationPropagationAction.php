<?php

/**
 * Learniq Wallet Revocation Propagation Action
 *
 * Transition action for the Credential schema's `revoke` transition. Propagates
 * the revocation to any outstanding EUDI wallet offer, best-effort, and returns
 * the Credential with `walletOfferStatus=revoked` or `walletOfferError` set.
 * Never throws: revoking is the compliance action of record and MUST NOT be
 * blocked by the wallet rail (fail-soft by spec). The outcome it records on the
 * Credential is its declared work, so a failure is visible, not a silent no-op.
 *
 * The write used to happen inside the guard, but OpenRegister calls a guard by
 * value and only merges back what an action returns (learniq#983).
 *
 * Referenced from the Credential schema's
 * x-openregister-lifecycle.transitions.revoke.actions in learniq_register.json.
 *
 * @category Lifecycle
 * @package  OCA\Learniq\Lifecycle\Action
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
 * @spec openspec/specs/certification/spec.md#requirement-revoking-a-credential-propagates-to-any-outstanding-wallet-offer-fail-soft
 */

declare(strict_types=1);

namespace OCA\Learniq\Lifecycle\Action;

use OCA\Learniq\Service\WalletRevocationPropagationService;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;

/**
 * Records the wallet revocation outcome on a revoked Credential.
 *
 * @spec openspec/specs/certification/spec.md#requirement-revoking-a-credential-propagates-to-any-outstanding-wallet-offer-fail-soft
 */
class WalletRevocationPropagationAction implements LifecycleActionInterface {

	/**
	 * Constructor.
	 *
	 * @param WalletRevocationPropagationService $propagationService The wallet revocation bridge.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly WalletRevocationPropagationService $propagationService,
	) {
	}//end __construct()

	/**
	 * Return the Credential with the wallet revocation outcome applied.
	 *
	 * @param array<string,mixed> $objectData The Credential after the lifecycle moved to `revoked`.
	 * @param array<string,mixed> $previousData The Credential before the transition.
	 * @param array<string,mixed> $parameters The declared actionParameters (unused).
	 * @param string $actionName The declared action name.
	 *
	 * @return array<string,mixed> The Credential to save.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The interface fixes the signature.
	 *
	 * @spec openspec/specs/certification/spec.md#requirement-revoking-a-credential-propagates-to-any-outstanding-wallet-offer-fail-soft
	 */
	public function execute(array $objectData, array $previousData, array $parameters, string $actionName): array {
		return $this->propagationService->propagate(credential: $objectData);
	}//end execute()
}//end class
