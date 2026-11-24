<?php

/**
 * Learniq Integriq Leerbedrijf Verification
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The one leerbedrijf-verification provider learniq ships. It asks integriq
 * to look a company up in its SBB source and maps the answer to `verified`,
 * `rejected`, `expired` or `pending`. No SBB wire protocol lives here: the
 * lookup is integriq's (ADR-022, design D1).
 *
 * Contract (workplace-leerbedrijf-check-through-integriq, design D4):
 * dispatch `TrainingCompanyLookupRequestedEvent(sourceApp, request)` with
 * request `{source: "sbb", number}`, then read off the same instance
 * `isHandled()`, `getAnswer()` `{found, erkenningNumber, endDate}` or
 * `getRefusal()` `{code, reason}`. The event class is named by string, so
 * learniq has no class dependency on integriq.
 *
 * Fails closed (design D2): no integriq, no SBB source or no answer gives
 * `pending` with a reason, and BpvConfirmationGuard accepts only `verified`.
 * Every lookup also records what it met on the `sbb` connection row.
 *
 * @category Bpv
 * @package  OCA\Learniq\Bpv
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/workplace-leerbedrijf-check-through-integriq/specs/bpv/spec.md#requirement-a-missing-integration-fails-closed
 */

declare(strict_types=1);

namespace OCA\Learniq\Bpv;

use DateTimeZone;
use OCA\Learniq\Service\ConnectionReportService;
use OCA\Learniq\Support\EventGetter;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;

/**
 * Looks a training company up in integriq's SBB source.
 *
 * @spec openspec/changes/workplace-leerbedrijf-check-through-integriq/specs/bpv/spec.md#requirement-leerbedrijf-verification-is-a-pluggable-provider
 */
class IntegriqLeerbedrijfVerification implements ProvidesLeerbedrijfVerification {

	public const INTEGRIQ_APP = 'integriq';
	public const OWNER_APP = 'learniq';
	public const SOURCE = 'sbb';
	public const REQUEST_EVENT = 'OCA\\Integriq\\Event\\TrainingCompanyLookupRequestedEvent';
	public const CONNECTION_KEY = ConnectionReportService::SBB_KEY;

	public const REASON_NO_INTEGRIQ = 'integriq is not installed or not enabled';
	public const REASON_NO_SOURCE = 'integriq has no SBB source configured';
	public const REASON_NO_ANSWER = 'the SBB source did not answer';

	/**
	 * Refusal codes that mean integriq has no usable SBB source.
	 *
	 * @var list<string>
	 */
	private const NO_SOURCE_CODES = ['source-not-available', 'source-disabled', 'unknown-source', 'unknown-operation'];

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher                 $dispatcher  Dispatches integriq's lookup event.
	 * @param IAppManager                      $appManager  Tells whether integriq is enabled.
	 * @param ITimeFactory                     $timeFactory Today, to tell an ended recognition.
	 * @param ConnectionReportService          $reports     Records what the lookup met on the `sbb` row.
	 */
	public function __construct(
		private readonly IEventDispatcher $dispatcher,
		private readonly IAppManager $appManager,
		private readonly ITimeFactory $timeFactory,
		private readonly ConnectionReportService $reports,
	) {
	}//end __construct()

	/**
	 * Look the company up and map integriq's answer.
	 *
	 * @param string $kvkOrErkenningNumber The KVK number or an erkenningsnummer.
	 *
	 * @return array{status: string, erkenningNumber: ?string, expiresAt: ?string, raw: array, reason: ?string}
	 *
	 * @spec openspec/changes/workplace-leerbedrijf-check-through-integriq/specs/bpv/spec.md#requirement-the-check-stores-sbbs-answer-on-the-placement
	 */
	public function verify(string $kvkOrErkenningNumber): array {
		if ($this->appManager->isEnabledForUser(self::INTEGRIQ_APP) === false || class_exists($this->eventClass()) === false) {
			$this->reports->observe(key: self::CONNECTION_KEY, status: 'unavailable', reason: 'Integriq is not installed or not enabled.');
			return self::pending(reason: self::REASON_NO_INTEGRIQ, raw: []);
		}

		$class = $this->eventClass();
		$event = new $class(self::OWNER_APP, ['source' => self::SOURCE, 'number' => trim($kvkOrErkenningNumber)]);
		if (($event instanceof Event) === false) {
			$this->reports->observe(key: self::CONNECTION_KEY, status: 'unavailable', reason: 'Integriq\'s lookup event is not an event.');
			return self::pending(reason: self::REASON_NO_INTEGRIQ, raw: []);
		}

		$this->dispatcher->dispatchTyped($event);

		return $this->answerOf(event: $event);
	}//end verify()

	/**
	 * Map the dispatched event's refusal or answer, recording what it met.
	 *
	 * @param Event $event The dispatched lookup.
	 *
	 * @return array{status: string, erkenningNumber: ?string, expiresAt: ?string, raw: array, reason: ?string}
	 *
	 * @spec openspec/changes/workplace-leerbedrijf-check-through-integriq/specs/bpv/spec.md#requirement-a-missing-integration-fails-closed
	 */
	private function answerOf(Event $event): array {
		$getter  = new EventGetter();
		$refusal = $getter->value(event: $event, getter: 'getRefusal');
		if (is_array($refusal) === true) {
			return $this->refused(refusal: $refusal);
		}

		$answer = $getter->value(event: $event, getter: 'getAnswer');
		if ($getter->value(event: $event, getter: 'isHandled') !== true || is_array($answer) === false) {
			$this->reports->observe(key: self::CONNECTION_KEY, status: 'unconfigured', reason: 'Integriq answered no SBB lookup.');
			return self::pending(reason: self::REASON_NO_SOURCE, raw: []);
		}

		$this->reports->observe(key: self::CONNECTION_KEY, status: 'configured', reason: 'The SBB source answered the last check.');

		return $this->mapped(answer: $answer, today: $this->timeFactory->getDateTime('now', new DateTimeZone('UTC'))->format('Y-m-d'));
	}//end answerOf()

	/**
	 * A refusal: no source reads as unconfigured, anything else as an error.
	 *
	 * @param array<string, mixed> $refusal The refusal, `{code, reason}`.
	 *
	 * @return array{status: string, erkenningNumber: ?string, expiresAt: ?string, raw: array, reason: ?string}
	 */
	private function refused(array $refusal): array {
		$code = (string)($refusal['code'] ?? 'failed');
		if (in_array($code, self::NO_SOURCE_CODES, true) === true) {
			$this->reports->observe(key: self::CONNECTION_KEY, status: 'unconfigured', reason: 'Integriq has no SBB source (' . $code . ').');
			return self::pending(reason: self::REASON_NO_SOURCE, raw: ['refusal' => $code]);
		}

		$this->reports->observe(key: self::CONNECTION_KEY, status: 'error', reason: 'The SBB source did not answer (' . $code . ').');

		return self::pending(reason: self::REASON_NO_ANSWER . ' (' . $code . ')', raw: ['refusal' => $code]);
	}//end refused()

	/**
	 * Map a lookup answer: not found is rejected, an ended recognition expired.
	 *
	 * @param array<string, mixed> $answer `{found, erkenningNumber, endDate}`.
	 * @param string               $today  Today as Y-m-d (UTC).
	 *
	 * @return array{status: string, erkenningNumber: ?string, expiresAt: ?string, raw: array, reason: ?string}
	 *
	 * @spec openspec/changes/workplace-leerbedrijf-check-through-integriq/specs/bpv/spec.md#requirement-the-check-stores-sbbs-answer-on-the-placement
	 */
	public static function mapped(array $answer, string $today): array {
		if (($answer['found'] ?? false) !== true) {
			return [
				'status' => 'rejected',
				'erkenningNumber' => null,
				'expiresAt' => null,
				'raw' => $answer,
				'reason' => 'SBB does not list this company as a leerbedrijf',
			];
		}

		$endDate = self::dateOrNull(value: ($answer['endDate'] ?? null));
		$status  = 'verified';
		$reason  = null;
		if ($endDate !== null && $endDate < $today) {
			$status = 'expired';
			$reason = 'the recognition ended on ' . $endDate;
		}

		$number = trim((string)($answer['erkenningNumber'] ?? ''));
		if ($number === '') {
			$number = null;
		}

		$expiresAt = null;
		if ($endDate !== null) {
			$expiresAt = $endDate . 'T00:00:00+00:00';
		}

		return [
			'status' => $status,
			'erkenningNumber' => $number,
			'expiresAt' => $expiresAt,
			'raw' => $answer,
			'reason' => $reason,
		];
	}//end mapped()

	/**
	 * A pending answer with the reason it is pending.
	 *
	 * @param string               $reason What is missing.
	 * @param array<string, mixed> $raw    What integriq answered, for the audit.
	 *
	 * @return array{status: string, erkenningNumber: ?string, expiresAt: ?string, raw: array, reason: ?string}
	 */
	private static function pending(string $reason, array $raw): array {
		return ['status' => 'pending', 'erkenningNumber' => null, 'expiresAt' => null, 'raw' => $raw, 'reason' => $reason];
	}//end pending()

	/**
	 * A valid Y-m-d date (the date part of a date-time is accepted), or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null
	 */
	private static function dateOrNull(mixed $value): ?string {
		if (is_string($value) === false || preg_match('/^\d{4}-\d{2}-\d{2}/', $value) !== 1) {
			return null;
		}

		$date = substr($value, 0, 10);
		if (checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4)) === false) {
			return null;
		}

		return $date;
	}//end dateOrNull()

	/**
	 * Integriq's lookup event class, kept a plain string for static analysis.
	 *
	 * @return string
	 */
	private function eventClass(): string {
		return self::REQUEST_EVENT;
	}//end eventClass()
}//end class
