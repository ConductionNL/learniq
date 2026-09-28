<?php

/**
 * Learniq Exchange Job Concluded Listener
 *
 * Projects the outcome of an integriq exchange job learniq owns onto learniq's
 * own records: a succeeded SWV hand-off routes its support request.
 *
 * @category Listener
 * @package  OCA\Learniq\Listener
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
 * @spec openspec/changes/data-exchange-to-integriq/specs/data-exchange/spec.md#requirement-a-succeeded-swv-exchange-routes-its-support-request
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * ADR-041 conclusion consumer: filters on learniq, idempotent, local.
 *
 * Replaces DataExchangeRunHandler::routeSupportRequestToSwv(): only a
 * `succeeded` swv job moves its support request, and only from `submitted`, so
 * a repeated conclusion changes nothing.
 *
 * @spec openspec/changes/data-exchange-to-integriq/specs/data-exchange/spec.md#requirement-a-succeeded-swv-exchange-routes-its-support-request
 *
 * @template-implements IEventListener<Event>
 */
class ExchangeJobConcludedListener implements IEventListener {

	public const CONCLUDED_EVENT = 'OCA\\Integriq\\Event\\ExchangeJobConcludedEvent';
	private const OWNER_APP = 'learniq';
	private const LEARNIQ_REGISTER = 'learniq';
	private const SUPPORT_REQUEST_SCHEMA = 'support-request';

	/**
	 * Constructor.
	 *
	 * @param ObjectService    $objectService    OR object access.
	 * @param TransitionEngine $transitionEngine OR lifecycle engine.
	 * @param LoggerInterface  $logger           Logger.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly TransitionEngine $transitionEngine,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle one conclusion.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/data-exchange-to-integriq/specs/data-exchange/spec.md#requirement-a-succeeded-swv-exchange-routes-its-support-request
	 */
	public function handle(Event $event): void {
		if (is_a($event, self::CONCLUDED_EVENT) === false
			|| (string)$this->read(event: $event, getter: 'getOwnerApp') !== self::OWNER_APP
			|| (string)$this->read(event: $event, getter: 'getTarget') !== 'swv'
			|| (string)$this->read(event: $event, getter: 'getStatus') !== 'succeeded'
		) {
			return;
		}

		$ownerRef = (string)$this->read(event: $event, getter: 'getOwnerRef');
		$prefix = self::SUPPORT_REQUEST_SCHEMA . '/';
		if (str_starts_with($ownerRef, $prefix) === false) {
			return;
		}

		$supportRequestId = substr($ownerRef, strlen($prefix));
		try {
			$request = $this->objectService->find(
				id: $supportRequestId,
				register: self::LEARNIQ_REGISTER,
				schema: self::SUPPORT_REQUEST_SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
			if ($request === null || ($request->jsonSerialize()['lifecycle'] ?? '') !== 'submitted') {
				return;
			}

			$this->transitionEngine->transition($supportRequestId, 'routeToSwv');
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[ExchangeJobConcludedListener] SupportRequest {id} could not be routed to the SWV: {msg}',
				['id' => $supportRequestId, 'msg' => $exception->getMessage()]
			);
		}

	}//end handle()

	/**
	 * Call a contract getter on the event.
	 *
	 * @param Event  $event  The event.
	 * @param string $getter The getter's name.
	 *
	 * @return mixed The value, or null when the getter does not exist.
	 */
	private function read(Event $event, string $getter): mixed {
		if (method_exists($event, $getter) === false) {
			return null;
		}

		return $event->$getter();
	}//end read()
}//end class
