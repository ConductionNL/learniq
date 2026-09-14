<?php

/**
 * Learniq connection report service.
 *
 * Tells integriq's connection registry what only learniq can see about its
 * outside connections: whether the last EUDI wallet offer reached integriq.
 * Integriq owns the rows the Integrations page lists and works out each
 * status itself (hydra change connection-registry, design D4).
 *
 * An observation is recorded where it happens and sent later, from the daily
 * job or a settings save. Recording is one config read, and a write only when
 * the outcome changed. Nothing is sent from inside a lifecycle guard, and
 * nothing is sent per request (ADR-076).
 *
 * @category Service
 * @package  OCA\Learniq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/adopt-connection-registry/specs/integrations/spec.md#requirement-req-int-conn-002-learniq-reports-what-the-last-wallet-offer-met
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\Learniq\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Records connection observations and sends them to integriq.
 *
 * @spec openspec/changes/adopt-connection-registry/specs/integrations/spec.md#requirement-req-int-conn-002-learniq-reports-what-the-last-wallet-offer-met
 */
class ConnectionReportService {

	/**
	 * Integriq's report event (ADR-041). Named by string so learniq stays
	 * installable without integriq: the class is only there when integriq is.
	 *
	 * @var string
	 */
	public const STATUS_EVENT = 'OCA\Integriq\Event\ConnectionStatusReportedEvent';

	/**
	 * The connection key of the EUDI wallet row in lib/Settings/connections.json.
	 *
	 * @var string
	 */
	public const WALLET_KEY = 'eudi-wallet';

	/**
	 * The connections learniq reports on. A unit test keeps every key declared.
	 *
	 * @var array<int, string>
	 */
	public const REPORTED_KEYS = [self::WALLET_KEY];

	/**
	 * The statuses an observation may carry: the call got through, it was not
	 * sent for want of settings, or it failed. Learniq observes nothing else.
	 *
	 * @var array<int, string>
	 */
	public const OBSERVABLE_STATUSES = ['configured', 'unconfigured', 'error'];

	/**
	 * App-config key prefix for a stored observation, followed by the connection key.
	 *
	 * @var string
	 */
	public const OBSERVATION_KEY_PREFIX = 'connection_observation_';

	/**
	 * The longest reason kept, so an exception text cannot flood a row.
	 *
	 * @var int
	 */
	private const MAX_REASON_LENGTH = 300;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig       $appConfig       Stores the pending observation.
	 * @param IEventDispatcher $eventDispatcher Sends the integriq event (ADR-041).
	 * @param ITimeFactory     $timeFactory     Stamps when an outcome was first seen.
	 * @param LoggerInterface  $logger          Records what could not be sent.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IEventDispatcher $eventDispatcher,
		private readonly ITimeFactory $timeFactory,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record what a call to an outside connection met.
	 *
	 * The same status and reason as the stored one change nothing, so a run of
	 * a hundred offers costs a hundred config reads and no write. Never throws:
	 * an observation must not change the outcome of the call it describes.
	 *
	 * @param string $key    The connection key.
	 * @param string $status One of OBSERVABLE_STATUSES.
	 * @param string $reason What was met, in one or two sentences.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/integrations/spec.md#requirement-req-int-conn-002-learniq-reports-what-the-last-wallet-offer-met
	 */
	public function observe(string $key, string $status, string $reason): void {
		if (in_array($key, self::REPORTED_KEYS, true) === false
			|| in_array($status, self::OBSERVABLE_STATUSES, true) === false
		) {
			$this->logger->warning(
				'Learniq: refused a connection observation it does not report',
				['key' => $key, 'status' => $status]
			);
			return;
		}

		$reason = mb_substr(trim($reason), 0, self::MAX_REASON_LENGTH);

		try {
			$stored = $this->readObservation(key: $key);
			if ($stored !== null && $stored['status'] === $status && $stored['reason'] === $reason) {
				return;
			}

			$this->appConfig->setValueString(
				app: Application::APP_ID,
				key: self::OBSERVATION_KEY_PREFIX . $key,
				value: json_encode(
					[
						'status' => $status,
						'reason' => $reason,
						'since' => $this->timeFactory->getDateTime('now', new \DateTimeZone('UTC'))->format('Y-m-d H:i'),
					],
					JSON_THROW_ON_ERROR
				),
				lazy: true
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Learniq: could not record a connection observation',
				['key' => $key, 'exception' => $e->getMessage()]
			);
		}//end try
	}//end observe()

	/**
	 * Send the stored observation of every reported connection to integriq.
	 *
	 * It sends again on every run. Integriq refuses a report for a row it has
	 * not synced yet, and a resend is one event a day, so the next run lands.
	 * The message says since when the outcome holds, so a resend claims no
	 * fresh call. Without integriq nothing is sent or logged. A listener that
	 * throws is logged per connection and never escapes.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/integrations/spec.md#requirement-req-int-conn-002-learniq-reports-what-the-last-wallet-offer-met
	 */
	public function reportObservations(): void {
		$eventClass = $this->resolveEventClass(eventClass: self::STATUS_EVENT);
		if ($eventClass === null) {
			return;
		}

		foreach (self::REPORTED_KEYS as $key) {
			try {
				$stored = $this->readObservation(key: $key);
				if ($stored === null) {
					continue;
				}

				$event = new $eventClass(
					app: Application::APP_ID,
					key: $key,
					status: $stored['status'],
					message: $stored['reason'] . ' Unchanged since ' . $stored['since'] . ' UTC.',
				);
				if (($event instanceof Event) === false) {
					continue;
				}

				$this->eventDispatcher->dispatchTyped($event);
			} catch (Throwable $e) {
				$this->logger->warning(
					'Learniq: could not send a connection report to integriq',
					['key' => $key, 'exception' => $e->getMessage()]
				);
			}//end try
		}//end foreach
	}//end reportObservations()

	/**
	 * The stored observation for a connection, or null when none is usable.
	 *
	 * @param string $key The connection key.
	 *
	 * @return array{status: string, reason: string, since: string}|null
	 */
	private function readObservation(string $key): ?array {
		$raw = $this->appConfig->getValueString(
			app: Application::APP_ID,
			key: self::OBSERVATION_KEY_PREFIX . $key,
			default: '',
			lazy: true
		);
		if ($raw === '') {
			return null;
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false
			|| is_string($decoded['status'] ?? null) === false
			|| is_string($decoded['reason'] ?? null) === false
			|| is_string($decoded['since'] ?? null) === false
		) {
			return null;
		}

		return [
			'status' => $decoded['status'],
			'reason' => $decoded['reason'],
			'since' => $decoded['since'],
		];
	}//end readObservation()

	/**
	 * The event class to instantiate, or null when integriq does not ship it.
	 *
	 * @param string $eventClass The fully qualified class name, without a leading backslash.
	 *
	 * @return string|null The class name to instantiate, or null when absent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/integrations/spec.md#requirement-req-int-conn-002-learniq-reports-what-the-last-wallet-offer-met
	 */
	protected function resolveEventClass(string $eventClass): ?string {
		$qualified = '\\' . $eventClass;
		if (class_exists($qualified) === false) {
			return null;
		}

		return $qualified;
	}//end resolveEventClass()
}//end class
