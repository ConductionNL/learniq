<?php

/**
 * Learniq Exchange Import Landing Listener
 *
 * Answers integriq's ExchangeRecordsReceivedEvent for learniq's import jobs
 * (`lvs-results`, `oso`, `migration-import`): lands the records through
 * ExchangeImportLanding, then calls `accept()` with the count taken and the
 * rejections. Duck-typed like ExchangeGateListener: learniq has no class
 * dependency on integriq, and the listener is only registered when the event
 * class exists. An import learniq does not answer ends `no-owner-answer` in
 * integriq, so every path through here answers, a failure included.
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
 * @spec openspec/changes/import-landing-answer/specs/data-exchange/spec.md#requirement-records-integriq-hands-back-for-an-import-land-in-learniq
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Service\ExchangeImportLanding;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Lands integriq's received import records and answers.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/import-landing-answer/specs/data-exchange/spec.md#requirement-records-integriq-hands-back-for-an-import-land-in-learniq
 */
class ExchangeImportLandingListener implements IEventListener {

	/**
	 * Integriq's event, named by string so learniq needs no integriq classes.
	 */
	public const RECEIVED_EVENT = 'OCA\\Integriq\\Event\\ExchangeRecordsReceivedEvent';

	private const OWNER_APP = 'learniq';

	/**
	 * Constructor.
	 *
	 * @param ExchangeImportLanding $landing Writes the records.
	 * @param LoggerInterface $logger Logger (never record content).
	 */
	public function __construct(
		private readonly ExchangeImportLanding $landing,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle the event.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/import-landing-answer/specs/data-exchange/spec.md#requirement-records-integriq-hands-back-for-an-import-land-in-learniq
	 */
	public function handle(Event $event): void {
		if (is_a($event, self::RECEIVED_EVENT) === false
			|| (string)$this->read(event: $event, getter: 'getOwnerApp') !== self::OWNER_APP
			|| in_array((string)$this->read(event: $event, getter: 'getTarget'), ExchangeImportLanding::TARGETS, true) === false
		) {
			return;
		}

		$records = $this->read(event: $event, getter: 'getRecords');
		if (is_array($records) === false) {
			$records = [];
		}

		$scope = $this->read(event: $event, getter: 'getScope');
		if (is_array($scope) === false) {
			$scope = [];
		}

		try {
			$outcome = $this->landing->land(
				target: (string)$this->read(event: $event, getter: 'getTarget'),
				jobId: (string)$this->read(event: $event, getter: 'getJobId'),
				scope: $scope,
				records: $records
			);
		} catch (Throwable $exception) {
			// The class only: a message can quote a record.
			$this->logger->error('[ExchangeImportLandingListener] landing failed: ' . get_class($exception));
			$rejected = [];
			foreach ($records as $record) {
				$rejected[] = ['recordId' => (string)($record['recordId'] ?? ''), 'errorCode' => 'IMPORT-WRITE-FAILED'];
			}

			$this->answer(event: $event, accepted: 0, rejected: $rejected);
			return;
		}

		$this->answer(event: $event, accepted: (int)($outcome['accepted'] ?? 0), rejected: (array)($outcome['rejected'] ?? []));
	}//end handle()

	/**
	 * Answer through the event's accept(), when it has one.
	 *
	 * @param Event $event The event.
	 * @param int $accepted The number of records taken.
	 * @param array<int, array<string, mixed>> $rejected The rejections.
	 *
	 * @return void
	 */
	private function answer(Event $event, int $accepted, array $rejected): void {
		if (method_exists($event, 'accept') === false) {
			$this->logger->error('[ExchangeImportLandingListener] integriq\'s event has no accept(); the job stays unanswered.');
			return;
		}

		$event->accept($accepted, $rejected);
	}//end answer()

	/**
	 * Read a getter when the event has it.
	 *
	 * @param Event $event The event.
	 * @param string $getter The getter.
	 *
	 * @return mixed
	 */
	private function read(Event $event, string $getter): mixed {
		if (method_exists($event, $getter) === false) {
			return null;
		}

		return $event->$getter();
	}//end read()
}//end class
