<?php

/**
 * Learniq BPV Leerbedrijf Verification Handler
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * IEventListener for BpvPlacement lifecycle → `sbb-verification-pending`
 * (the OR ObjectTransitionedEvent with register=learniq, schema=bpv-placement,
 * to=sbb-verification-pending — the coordinator-triggered `checkLeerbedrijf`
 * self-transition action).
 *
 * Algorithm:
 * 1. Resolve the ProvidesLeerbedrijfVerification adapter named by
 *    BpvPlacement.trainingCompanyVerification.provider via the DI container;
 *    none (or `integriq`) resolves IntegriqLeerbedrijfVerification, the one
 *    provider learniq ships, which asks integriq's SBB source and answers
 *    `pending` with a reason when integriq or its source is missing. A name
 *    that does not resolve to an adapter → no-op; the placement stays in
 *    `sbb-verification-pending` and no exception is thrown.
 * 2. Call verify() with the placement's KVK number, else its erkenningsnummer.
 * 3. Write the result (`status`, `erkenningNumber`, `checkedAt`, `expiresAt`,
 *    `reason`, `raw`) back onto BpvPlacement.trainingCompanyVerification via
 *    ObjectService::saveObject. This is a field write only — it does NOT
 *    drive a lifecycle transition itself (BpvConfirmationGuard reads this
 *    stored result later, when a coordinator attempts `confirm`).
 *
 * This is the ADR-031 "external-system bridge" exception: single
 * responsibility — orchestrate the provider call and persist its result. No
 * SBB wire protocol lives here (that is integriq's SBB source).
 *
 * @category Listener
 * @package  OCA\Learniq\Listener
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bpv/spec.md#requirement-leerbedrijf-verification-is-a-pluggable-provider
 * @spec openspec/changes/workplace-leerbedrijf-check-through-integriq/specs/bpv/spec.md#requirement-leerbedrijf-verification-is-a-pluggable-provider
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Bpv\IntegriqLeerbedrijfVerification;
use OCA\Learniq\Bpv\ProvidesLeerbedrijfVerification;
use OCA\Learniq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Handles BpvPlacement lifecycle → sbb-verification-pending.
 *
 * Resolves the configured leerbedrijf-verification provider (if any), calls
 * it, and writes the result back onto the placement. Implements no SBB wire
 * protocol itself.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/bpv/spec.md#requirement-leerbedrijf-verification-is-a-pluggable-provider
 */
class BpvLeerbedrijfVerificationHandler implements IEventListener {

	private const LEARNIQ_REGISTER = 'learniq';
	private const PLACEMENT_SCHEMA = 'bpv-placement';
	private const TARGET_STATE = 'sbb-verification-pending';
	private const DEFAULT_PROVIDER = 'integriq';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR object access service.
	 * @param ContainerInterface $container DI container used to resolve the configured
	 *                                      ProvidesLeerbedrijfVerification adapter by FQCN.
	 * @param LoggerInterface $logger PSR logger.
	 * @param ListenerSchemaResolverInterface $schemas Resolves the transition event's register and schema ids to slugs.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly ListenerSchemaResolverInterface $schemas,
	) {
	}//end __construct()

	/**
	 * Handle an ObjectTransitionedEvent.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bpv/spec.md#requirement-leerbedrijf-verification-is-a-pluggable-provider
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectTransitionedEvent) === false) {
			return;
		}

		if ($this->schemas->eventRegister(event: $event) !== self::LEARNIQ_REGISTER) {
			return;
		}

		if ($this->schemas->eventSchema(event: $event) !== self::PLACEMENT_SCHEMA) {
			return;
		}

		if ($event->getTo() !== self::TARGET_STATE) {
			return;
		}

		$this->runVerification(event: $event);

	}//end handle()

	/**
	 * Resolve the configured provider (if any), verify, and persist the result.
	 *
	 * @param ObjectTransitionedEvent $event The sbb-verification-pending transition event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bpv/spec.md#requirement-leerbedrijf-verification-is-a-pluggable-provider
	 */
	private function runVerification(ObjectTransitionedEvent $event): void {
		$placement = $event->getObject()->jsonSerialize();
		$placementId = $placement['id'] ?? ($placement['uuid'] ?? '');

		if ($placementId === '') {
			$this->logger->error('[BpvLeerbedrijfVerificationHandler] BpvPlacement has no id — cannot verify.');
			return;
		}

		$verification = $placement['trainingCompanyVerification'] ?? [];
		if (is_array($verification) === false) {
			$verification = [];
		}

		$this->verifyWithProvider(placement: $placement, verification: $verification);

	}//end runVerification()

	/**
	 * Verify the placement's training company with the configured provider and
	 * record the result; logs and leaves it unresolved without a provider or KvK number.
	 *
	 * @param array<string,mixed> $placement    The BpvPlacement data, with an id.
	 * @param array<string,mixed> $verification Its trainingCompanyVerification block.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bpv/spec.md#requirement-leerbedrijf-verification-is-a-pluggable-provider
	 */
	private function verifyWithProvider(array $placement, array $verification): void {
		$placementId = $placement['id'] ?? ($placement['uuid'] ?? '');

		$providerClass = $verification['provider'] ?? null;
		$kvkNumber = self::numberToCheck(placement: $placement, verification: $verification);

		$provider = $this->resolveProvider(providerClass: $providerClass);

		if ($provider === null) {
			$this->logger->info(
				'[BpvLeerbedrijfVerificationHandler] No usable leerbedrijfVerification.provider for '
				. 'BpvPlacement {id} — leaving verification unresolved; the placement cannot confirm until '
				. 'one resolves.',
				['id' => $placementId]
			);
			return;
		}

		if ($kvkNumber === '') {
			$this->logger->warning(
				'[BpvLeerbedrijfVerificationHandler] BpvPlacement {id} has no leerbedrijfKvkNumber — cannot verify.',
				['id' => $placementId]
			);
			return;
		}

		$result = $this->callProvider(provider: $provider, subject: ['kvkNumber' => $kvkNumber, 'placementId' => $placementId]);
		if ($result === null) {
			return;
		}

		$this->recordVerification(
			placement: ['object' => $placement, 'id' => $placementId, 'verification' => $verification],
			result: $result
		);
	}//end verifyWithProvider()

	/**
	 * Ask the provider about the company; null when the call failed (and was logged).
	 *
	 * @param mixed                $provider The configured verification provider.
	 * @param array<string, mixed> $subject  kvkNumber: the company's KvK number; placementId: for the log.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/bpv/spec.md#requirement-leerbedrijf-verification-is-a-pluggable-provider
	 */
	private function callProvider(mixed $provider, array $subject): ?array {
		try {
			return $provider->verify($subject['kvkNumber']);
		} catch (\Throwable $e) {
			$this->logger->error(
				'[BpvLeerbedrijfVerificationHandler] Provider call failed for BpvPlacement {id}: {msg}',
				['id' => $subject['placementId'], 'msg' => $e->getMessage()]
			);
			return null;
		}
	}//end callProvider()

	/**
	 * Store the provider's answer on the placement and log the new status.
	 *
	 * @param array<string, mixed> $placement object: the placement as read; id: its id;
	 *                                        verification: its current trainingCompanyVerification block.
	 * @param array<string, mixed> $result    The provider's answer.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/workplace-leerbedrijf-check-through-integriq/specs/bpv/spec.md#requirement-the-check-stores-sbbs-answer-on-the-placement
	 */
	private function recordVerification(array $placement, array $result): void {
		$updatedVerification = array_merge(
			$placement['verification'],
			[
				'status' => $result['status'],
				'erkenningNumber' => $result['erkenningNumber'],
				'verifiedAt' => date('c'),
				'checkedAt' => date('c'),
				'expiresAt' => $result['expiresAt'],
				'reason' => ($result['reason'] ?? null),
				'raw' => $result['raw'],
			]
		);

		$this->objectService->saveObject(
			object: array_merge($placement['object'], ['trainingCompanyVerification' => $updatedVerification]),
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: self::LEARNIQ_REGISTER, schema: self::PLACEMENT_SCHEMA))
		);

		$this->logger->info(
			'[BpvLeerbedrijfVerificationHandler] BpvPlacement {id} leerbedrijfVerification.status → {status}.',
			['id' => $placement['id'], 'status' => $updatedVerification['status']]
		);
	}//end recordVerification()

	/**
	 * The number to look up: the company's KVK number, else an erkenningsnummer
	 * already stored or entered on the placement.
	 *
	 * @param array<string, mixed> $placement    The BpvPlacement data.
	 * @param array<string, mixed> $verification Its trainingCompanyVerification block.
	 *
	 * @return string The number, or '' when the placement names none.
	 *
	 * @spec openspec/changes/workplace-leerbedrijf-check-through-integriq/specs/bpv/spec.md#requirement-the-check-stores-sbbs-answer-on-the-placement
	 */
	private static function numberToCheck(array $placement, array $verification): string {
		$kvkNumber = trim((string)($placement['trainingCompanyKvkNumber'] ?? ''));
		if ($kvkNumber !== '') {
			return $kvkNumber;
		}

		return trim((string)($verification['erkenningNumber'] ?? ''));
	}//end numberToCheck()

	/**
	 * Resolve the configured provider FQCN through the DI container.
	 *
	 * No provider, or `integriq`, resolves IntegriqLeerbedrijfVerification, the
	 * provider learniq ships. Fails closed (returns null, never throws) when the
	 * value is not a class name, the class does not exist, the container cannot
	 * build it, or the resolved service does not implement
	 * ProvidesLeerbedrijfVerification.
	 *
	 * @param mixed $providerClass The `leerbedrijfVerification.provider` config value.
	 *
	 * @return ProvidesLeerbedrijfVerification|null The resolved adapter, or null.
	 *
	 * @spec openspec/changes/workplace-leerbedrijf-check-through-integriq/specs/bpv/spec.md#requirement-leerbedrijf-verification-is-a-pluggable-provider
	 */
	private function resolveProvider(mixed $providerClass): ?ProvidesLeerbedrijfVerification {
		if ($providerClass === null || $providerClass === '' || $providerClass === self::DEFAULT_PROVIDER) {
			$providerClass = IntegriqLeerbedrijfVerification::class;
		}

		if (is_string($providerClass) === false) {
			return null;
		}

		return $this->providerFrom(providerClass: $providerClass);
	}//end resolveProvider()

	/**
	 * Build the named provider through the DI container; null (logged) when it cannot be built.
	 *
	 * @param string $providerClass The provider FQCN.
	 *
	 * @return ProvidesLeerbedrijfVerification|null
	 */
	private function providerFrom(string $providerClass): ?ProvidesLeerbedrijfVerification {
		try {
			$service = $this->container->get($providerClass);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'[BpvLeerbedrijfVerificationHandler] Configured provider "{class}" could not be resolved: {msg}',
				['class' => $providerClass, 'msg' => $e->getMessage()]
			);
			return null;
		}

		return $this->asProvider(service: $service, providerClass: $providerClass);
	}//end providerFrom()

	/**
	 * The built service as a provider; null (logged) when it does not implement ProvidesLeerbedrijfVerification.
	 *
	 * @param mixed  $service       What the container built.
	 * @param string $providerClass The provider FQCN, for the log.
	 *
	 * @return ProvidesLeerbedrijfVerification|null
	 */
	private function asProvider(mixed $service, string $providerClass): ?ProvidesLeerbedrijfVerification {
		if (($service instanceof ProvidesLeerbedrijfVerification) === false) {
			$this->logger->warning(
				'[BpvLeerbedrijfVerificationHandler] Configured provider "{class}" does not implement '
				. 'ProvidesLeerbedrijfVerification.',
				['class' => $providerClass]
			);
			return null;
		}

		return $service;
	}//end asProvider()
}//end class
