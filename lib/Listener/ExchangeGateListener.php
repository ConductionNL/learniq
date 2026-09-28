<?php

/**
 * Learniq Exchange Gate Listener
 *
 * Answers integriq's ExchangeGateRequestedEvent for exchange jobs learniq owns:
 * allow with what may leave, or refuse with a reason.
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
 * @spec openspec/changes/data-exchange-to-integriq/specs/data-exchange/spec.md#requirement-the-gate-enforces-partner-approval-teldatum-confirmation-and-flag-handling
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Service\ExchangeGateService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The in-process binding of learniq's exchange gate (ADR-041).
 *
 * Learniq has no class dependency on integriq: the event is recognised by its
 * class name and read through its contract getters. A listener failure is
 * answered as a refusal, never thrown back into integriq's runner.
 *
 * @spec openspec/changes/data-exchange-to-integriq/specs/data-exchange/spec.md#requirement-the-gate-enforces-partner-approval-teldatum-confirmation-and-flag-handling
 *
 * @template-implements IEventListener<Event>
 */
class ExchangeGateListener implements IEventListener {

	public const GATE_EVENT = 'OCA\\Integriq\\Event\\ExchangeGateRequestedEvent';
	private const OWNER_APP = 'learniq';

	/**
	 * Constructor.
	 *
	 * @param ExchangeGateService $gate   Decides for the job.
	 * @param LoggerInterface     $logger Logger.
	 */
	public function __construct(
		private readonly ExchangeGateService $gate,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Answer one gate request.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/data-exchange-to-integriq/specs/data-exchange/spec.md#requirement-the-gate-enforces-partner-approval-teldatum-confirmation-and-flag-handling
	 */
	public function handle(Event $event): void {
		if (is_a($event, self::GATE_EVENT) === false
			|| method_exists($event, 'allow') === false
			|| method_exists($event, 'refuse') === false
			|| (string)$this->read(event: $event, getter: 'getOwnerApp') !== self::OWNER_APP
		) {
			return;
		}

		$scope = $this->read(event: $event, getter: 'getScope');
		if (is_array($scope) === false) {
			$scope = [];
		}

		try {
			$decision = $this->gate->evaluate(
				jobId: (string)$this->read(event: $event, getter: 'getJobId'),
				target: (string)$this->read(event: $event, getter: 'getTarget'),
				direction: (string)$this->read(event: $event, getter: 'getDirection'),
				ownerRef: (string)$this->read(event: $event, getter: 'getOwnerRef'),
				scope: $scope
			);
		} catch (Throwable $exception) {
			$this->logger->error('[ExchangeGateListener] the gate failed: ' . $exception->getMessage());
			$event->refuse('gate-error', 'Learniq could not decide on this exchange: ' . $exception->getMessage());
			return;
		}

		if (($decision['decision'] ?? '') === ExchangeGateService::DECISION_ALLOW) {
			$event->allow((array)($decision['records'] ?? []));
			return;
		}

		// Anything that is not an explicit allow is a refusal, with a code even
		// when the decision came back incomplete.
		$code = (string)($decision['code'] ?? '');
		if ($code === '') {
			$code = 'gate-error';
		}

		$event->refuse($code, (string)($decision['reason'] ?? 'Learniq could not decide on this exchange.'));

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
