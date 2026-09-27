<?php

/**
 * Learniq Lifecycle Write Listener Registrar
 *
 * One of the domain-scoped registrars `Application::register()` delegates its
 * event-listener wiring to. This one wires the listeners that write the
 * results of self-loop lifecycle transitions. OpenRegister runs neither a
 * `requires` guard nor a transition action when the lifecycle value does not
 * change, so those writes run on ObjectTransitionedEvent instead (learniq#983).
 *
 * @category AppInfo
 * @package  OCA\Learniq\AppInfo\Registrar
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
 */

declare(strict_types=1);

namespace OCA\Learniq\AppInfo\Registrar;

use OCA\Learniq\Listener\CredentialWalletTransitionListener;
use OCA\Learniq\Listener\ReportCardPdfTransitionListener;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Wires the self-loop transition writers.
 *
 * @spec openspec/specs/certification/spec.md#requirement-offertowallet-transition-pushes-an-issued-credential-to-the-eudi-wallet
 */
class LifecycleWriteListenerRegistrar {
	/**
	 * Register every self-loop transition writer.
	 *
	 * @param IRegistrationContext $context Nextcloud registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/certification/spec.md#requirement-offertowallet-transition-pushes-an-issued-credential-to-the-eudi-wallet
	 */
	public function register(IRegistrationContext $context): void {
		// Credential.offerToWallet and Credential.recordWalletClaim (issued -> issued).
		$context->registerEventListener(
			event: ObjectTransitionedEvent::class,
			listener: CredentialWalletTransitionListener::class
		);

		// ReportCard.renderToPdf (finalised) and ReportCard.rerenderToPdf
		// (published-to-parents), both self-loops.
		$context->registerEventListener(
			event: ObjectTransitionedEvent::class,
			listener: ReportCardPdfTransitionListener::class
		);
	}//end register()
}//end class
