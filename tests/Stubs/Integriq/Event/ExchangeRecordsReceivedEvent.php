<?php

/**
 * TEST STUB: a verbatim copy of integriq's ExchangeRecordsReceivedEvent (ConductionNL/integriq#2251,
 * openspec/changes/exchange-import-landing/design.md). Learniq has no class dependency on integriq;
 * its unit tests construct the real contract, never a double, so a wrong getter fails.
 * The code is verbatim; the @spec tags point at the learniq requirement that consumes it,
 * because integriq's spec path does not exist in this repository.
 */

/**
 * Integriq Exchange Records Received Event.
 *
 * Integriq hands the records an import job received back to the app that
 * owns the job, and waits for that app's answer: how many it accepted and
 * which it rejected, with reason codes.
 *
 * @category Event
 * @package  OCA\Integriq\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/data-exchange/spec.md#requirement-records-integriq-hands-back-for-an-import-land-in-learniq
 */

declare(strict_types=1);

namespace OCA\Integriq\Event;

use OCP\EventDispatcher\Event;

/**
 * "Here are the records job X received; which did you take?", answered by the owning app.
 *
 * The mirror of {@see ExchangeGateRequestedEvent}: the gate asks what may
 * leave, this event delivers what arrived. Exactly one answer counts: the
 * first `accept()` wins, so a second listener cannot rewrite the outcome.
 *
 * A rejection carries a record id, a reason code and optional field names,
 * never a value: rejections are stored as dead letters and shown to people.
 *
 * @spec openspec/specs/data-exchange/spec.md#requirement-records-integriq-hands-back-for-an-import-land-in-learniq
 */
class ExchangeRecordsReceivedEvent extends Event {

	/**
	 * Whether an answer was given.
	 *
	 * @var bool
	 */
	private bool $answered = false;

	/**
	 * Accepted record count.
	 *
	 * @var int
	 */
	private int $acceptedCount = 0;

	/**
	 * Rejected records, normalised.
	 *
	 * @var array<int, array{recordId: string, sourceKind: string, errorCode: string, offendingFields: array<int, string>}>
	 */
	private array $rejected = [];

	/**
	 * Constructor.
	 *
	 * @param string                           $jobId     The exchange job's uuid.
	 * @param string                           $ownerApp  The owning app's id.
	 * @param string                           $target    The exchange target id.
	 * @param string                           $direction Always `import` today.
	 * @param string                           $ownerRef  The owning app's reference.
	 * @param array<string, mixed>             $scope     The job's selectors and parameters.
	 * @param array<int, array<string, mixed>> $records   The received records, each
	 *                                                    `{recordId, sourceKind, data}`.
	 */
	public function __construct(
		private readonly string $jobId,
		private readonly string $ownerApp,
		private readonly string $target,
		private readonly string $direction,
		private readonly string $ownerRef = '',
		private readonly array $scope = [],
		private readonly array $records = [],
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * The job that received the records.
	 *
	 * @return string The job uuid.
	 *
	 * @spec openspec/specs/data-exchange/spec.md#requirement-records-integriq-hands-back-for-an-import-land-in-learniq
	 */
	public function getJobId(): string {
		return $this->jobId;

	}//end getJobId()

	/**
	 * The owning app a listener must match before answering.
	 *
	 * @return string The app id.
	 *
	 * @spec openspec/specs/data-exchange/spec.md#requirement-records-integriq-hands-back-for-an-import-land-in-learniq
	 */
	public function getOwnerApp(): string {
		return $this->ownerApp;

	}//end getOwnerApp()

	/**
	 * The exchange target.
	 *
	 * @return string The target id.
	 *
	 * @spec openspec/specs/data-exchange/spec.md#requirement-records-integriq-hands-back-for-an-import-land-in-learniq
	 */
	public function getTarget(): string {
		return $this->target;

	}//end getTarget()

	/**
	 * The direction.
	 *
	 * @return string `import`.
	 *
	 * @spec openspec/specs/data-exchange/spec.md#requirement-records-integriq-hands-back-for-an-import-land-in-learniq
	 */
	public function getDirection(): string {
		return $this->direction;

	}//end getDirection()

	/**
	 * The owning app's reference for what the job is about.
	 *
	 * @return string The reference.
	 *
	 * @spec openspec/specs/data-exchange/spec.md#requirement-records-integriq-hands-back-for-an-import-land-in-learniq
	 */
	public function getOwnerRef(): string {
		return $this->ownerRef;

	}//end getOwnerRef()

	/**
	 * The job's selectors and target parameters.
	 *
	 * @return array<string, mixed> The scope.
	 *
	 * @spec openspec/specs/data-exchange/spec.md#requirement-records-integriq-hands-back-for-an-import-land-in-learniq
	 */
	public function getScope(): array {
		return $this->scope;

	}//end getScope()

	/**
	 * The received records, after the job's mapping row.
	 *
	 * @return array<int, array<string, mixed>> Each `{recordId, sourceKind, data}`.
	 *
	 * @spec openspec/specs/data-exchange/spec.md#requirement-records-integriq-hands-back-for-an-import-land-in-learniq
	 */
	public function getRecords(): array {
		return $this->records;

	}//end getRecords()

	/**
	 * Answer: how many records were taken, and which were rejected and why.
	 *
	 * Each rejection is `{recordId: string, errorCode: string, offendingFields?: string[]}`;
	 * `sourceKind` is copied from the received record. A rejection for an unknown
	 * record id, or without a code, is dropped. Never pass a value.
	 *
	 * @param int                              $acceptedCount The number of records taken.
	 * @param array<int, array<string, mixed>> $rejected      The rejected records.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-exchange/spec.md#requirement-records-integriq-hands-back-for-an-import-land-in-learniq
	 */
	public function accept(int $acceptedCount, array $rejected=[]): void {
		if ($this->answered === true) {
			return;
		}

		$kinds = [];
		foreach ($this->records as $record) {
			$kinds[(string) ($record['recordId'] ?? '')] = (string) ($record['sourceKind'] ?? '');
		}

		$clean = [];
		foreach ($rejected as $rejection) {
			$recordId = (string) ($rejection['recordId'] ?? '');
			$code     = (string) ($rejection['errorCode'] ?? '');
			if ($code === '' || array_key_exists($recordId, $kinds) === false) {
				continue;
			}

			$fields = [];
			foreach ((array) ($rejection['offendingFields'] ?? []) as $field) {
				if (is_string($field) === true && $field !== '') {
					$fields[] = $field;
				}
			}

			$clean[$recordId] = [
				'recordId' => $recordId,
				'sourceKind' => $kinds[$recordId],
				'errorCode' => $code,
				'offendingFields' => $fields,
			];
		}//end foreach

		$this->answered      = true;
		$this->rejected      = array_values($clean);
		$this->acceptedCount = max(0, min($acceptedCount, (count($this->records) - count($this->rejected))));

	}//end accept()

	/**
	 * Whether the owning app answered.
	 *
	 * @return bool True once accept() ran.
	 *
	 * @spec openspec/specs/data-exchange/spec.md#requirement-records-integriq-hands-back-for-an-import-land-in-learniq
	 */
	public function isAnswered(): bool {
		return $this->answered;

	}//end isAnswered()

	/**
	 * The accepted count, clamped to the records not rejected.
	 *
	 * @return int The count.
	 *
	 * @spec openspec/specs/data-exchange/spec.md#requirement-records-integriq-hands-back-for-an-import-land-in-learniq
	 */
	public function getAcceptedCount(): int {
		return $this->acceptedCount;

	}//end getAcceptedCount()

	/**
	 * The rejected records.
	 *
	 * @return array<int, array{recordId: string, sourceKind: string, errorCode: string, offendingFields: array<int, string>}>
	 *
	 * @spec openspec/specs/data-exchange/spec.md#requirement-records-integriq-hands-back-for-an-import-land-in-learniq
	 */
	public function getRejected(): array {
		return $this->rejected;

	}//end getRejected()
}//end class
