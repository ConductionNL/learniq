<?php

/**
 * Learniq Credential Wallet Transition Listener
 *
 * Writes the results of the Credential schema's `offerToWallet` and
 * `recordWalletClaim` transitions. Both are self-loops (issued -> issued), and
 * OpenRegister's lifecycle listeners return early when the lifecycle value does
 * not change, so neither a `requires` guard nor a transition action runs on
 * them. TransitionEngine does dispatch ObjectTransitionedEvent after the save
 * for every transition, self-loops included, so the writes run here and are
 * saved back onto the Credential (learniq#983).
 *
 * The writes used to happen inside the guards, which OpenRegister calls by
 * value, so they never reached the saved object.
 *
 * @category Listener
 * @package  OCA\Learniq\Listener
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
 * @spec openspec/specs/certification/spec.md#requirement-offertowallet-transition-pushes-an-issued-credential-to-the-eudi-wallet
 * @spec openspec/specs/certification/spec.md#requirement-recordwalletclaim-transition-syncs-wallet-claim-status-back-onto-the-credential
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Service\WalletClaimSyncService;
use OCA\Learniq\Service\WalletOfferDelegationService;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Saves the wallet offer or claim onto a Credential after its self-loop transition.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/certification/spec.md#requirement-offertowallet-transition-pushes-an-issued-credential-to-the-eudi-wallet
 * @spec openspec/specs/certification/spec.md#requirement-recordwalletclaim-transition-syncs-wallet-claim-status-back-onto-the-credential
 */
class CredentialWalletTransitionListener implements IEventListener {

	private const LEARNIQ_REGISTER = 'learniq';
	private const CREDENTIAL_SCHEMA = 'credential';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR object service, to save the Credential.
	 * @param WalletClaimSyncService $claimService Writes the wallet claim.
	 * @param WalletOfferDelegationService $offerService Pushes the wallet offer.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly WalletClaimSyncService $claimService,
		private readonly WalletOfferDelegationService $offerService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Write and save the outcome of `offerToWallet` or `recordWalletClaim`.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/certification/spec.md#requirement-offertowallet-transition-pushes-an-issued-credential-to-the-eudi-wallet
	 * @spec openspec/specs/certification/spec.md#requirement-recordwalletclaim-transition-syncs-wallet-claim-status-back-onto-the-credential
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectTransitionedEvent) === false
			|| $event->getRegister() !== self::LEARNIQ_REGISTER
			|| $event->getSchema() !== self::CREDENTIAL_SCHEMA
		) {
			return;
		}

		$entity = $event->getObject();
		$credential = $entity->getObject();

		$updated = match ($event->getAction()) {
			'offerToWallet' => $this->offerService->offer(credential: $credential),
			'recordWalletClaim' => $this->claimService->claim(credential: $credential),
			default => null,
		};

		if ($updated === null) {
			return;
		}

		$this->objectService->saveObject(
			object: $updated,
			register: self::LEARNIQ_REGISTER,
			schema: self::CREDENTIAL_SCHEMA,
			uuid: $entity->getUuid()
		);

		$this->logger->info(
			'[CredentialWalletTransitionListener] Saved the {action} outcome on Credential {id}.',
			['action' => $event->getAction(), 'id' => $entity->getUuid()]
		);
	}//end handle()
}//end class
