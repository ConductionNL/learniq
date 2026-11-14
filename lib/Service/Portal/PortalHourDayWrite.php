<?php

/**
 * Learniq PortalHourDayWrite
 *
 * A student writes the hours of one day from her phone: when she started and
 * stopped, her break, what she did and the work processes it belongs to. The
 * day becomes a line of the week of hours that holds its date, on her own
 * placement; the week is created as a draft when she has none for it yet.
 * "Keep for later" leaves the week a draft, "Send" hands it to her workplace
 * trainer.
 *
 * A week that waits for her trainer or is already approved does not change.
 * A week her trainer sent back does: she changes the day the question is
 * about and sends the week again.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Portal
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
 * @spec openspec/changes/bpv-hours-per-day/specs/portal-contribution/spec.md#requirement-a-student-writes-hours-per-day-and-keeps-a-draft
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Portal;

use DateTimeImmutable;
use OCA\Learniq\Kernel\Contracts\PortalHourDayWriteInterface;
use OCA\Learniq\Service\HourDayLines;
use OCA\Learniq\Support\SerialisedRow;
use OCA\OpenRegister\Service\ObjectService;

/**
 * Adds or replaces one day line of the student's own week, and keeps or sends the week.
 *
 * @spec openspec/changes/bpv-hours-per-day/specs/portal-contribution/spec.md#requirement-a-student-writes-hours-per-day-and-keeps-a-draft
 */
class PortalHourDayWrite implements PortalHourDayWriteInterface {

	private const REGISTER = 'learniq';

	private const WEEK_SCHEMA = 'bpv-hour-week';

	private const PLACEMENT_SCHEMA = 'bpv-placement';

	/**
	 * The `send` value that hands the week to the trainer; anything else keeps it.
	 */
	public const SEND = 'send';

	/**
	 * The states in which a week takes a changed day: kept for later, or sent back.
	 */
	private const OPEN_STATES = ['draft', 'rejected'];

	/**
	 * The most work process codes one day carries.
	 */
	private const MAX_CODES = 20;

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService Reads the placement and the week; writes the week.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * Add or replace one day of the student's own week of hours, and keep or send the week.
	 *
	 * @param string               $learnerRef The student's `learnerRef` claim.
	 * @param array<string, mixed> $body       What the form sent.
	 *
	 * @return PortalOutcome 200 with the week, or 403 / 409 / 422.
	 *
	 * @spec openspec/changes/bpv-hours-per-day/specs/portal-contribution/spec.md#requirement-a-student-writes-hours-per-day-and-keeps-a-draft
	 */
	public function write(string $learnerRef, array $body): PortalOutcome {
		$request = self::requestOf(body: $body);
		$refusal = $this->requestRefusal(learnerRef: $learnerRef, request: $request);
		if ($refusal !== null) {
			return $refusal;
		}

		$week = $this->weekOrDraft(learnerRef: $learnerRef, request: $request);
		if (in_array(($week['lifecycle'] ?? 'submitted'), self::OPEN_STATES, true) === false) {
			return new PortalOutcome(status: 409, body: ['error' => 'week_with_trainer'], reason: 'hour-week-with-trainer');
		}

		$written = self::withDay(week: $week, line: self::lineOf(body: $body), send: $request['send']);
		if ($written instanceof PortalOutcome) {
			return $written;
		}

		return $this->saved(week: $written);
	}//end write()

	/**
	 * The placement, the week the day falls in, and whether she sends the week.
	 *
	 * @param array<string, mixed> $body What the form sent.
	 *
	 * @return array{placementId: string, isoWeek: string|null, send: bool}
	 */
	private static function requestOf(array $body): array {
		return [
			'placementId' => self::text(value: ($body['bpvPlacementId'] ?? null)),
			'isoWeek' => self::isoWeekOf(date: self::text(value: ($body['date'] ?? null))),
			'send' => self::text(value: ($body['send'] ?? null)) === self::SEND,
		];
	}//end requestOf()

	/**
	 * Why the request is refused before the week is read: something missing,
	 * a day that is not a date, or a placement that is not hers.
	 *
	 * @param string                                                     $learnerRef The student.
	 * @param array{placementId: string, isoWeek: string|null, send: bool} $request    The request.
	 *
	 * @return PortalOutcome|null Null when the request may proceed.
	 */
	private function requestRefusal(string $learnerRef, array $request): ?PortalOutcome {
		if ($learnerRef === '' || $request['placementId'] === '' || $request['isoWeek'] === null) {
			return new PortalOutcome(status: 422, body: ['error' => 'incomplete'], reason: 'hour-day-incomplete');
		}

		if ($this->ownsPlacement(learnerRef: $learnerRef, placementId: $request['placementId']) === false) {
			return new PortalOutcome(status: 403, body: ['error' => 'not_your_placement'], reason: 'hour-day-not-your-placement');
		}

		return null;
	}//end requestRefusal()

	/**
	 * The placement's stored week for the day, or a new draft week when she has none for it yet.
	 *
	 * @param string                                                     $learnerRef The student.
	 * @param array{placementId: string, isoWeek: string|null, send: bool} $request    The admitted request.
	 *
	 * @return array<string, mixed>
	 */
	private function weekOrDraft(string $learnerRef, array $request): array {
		$isoWeek = (string)$request['isoWeek'];

		return ($this->weekOf(placementId: $request['placementId'], isoWeek: $isoWeek)
			?? ['bpvPlacementId' => $request['placementId'], 'learnerRef' => $learnerRef, 'isoWeek' => $isoWeek, 'lifecycle' => 'draft']);
	}//end weekOrDraft()

	/**
	 * The week with the day line added, or put in place of the line of the same
	 * date, its hours summed, and its state; or the refusal when a line cannot
	 * be read.
	 *
	 * @param array<string, mixed> $week The stored week, or the new draft.
	 * @param array<string, mixed> $line The day line as sent.
	 * @param bool                 $send Whether she sends the week to her trainer.
	 *
	 * @return array<string, mixed>|PortalOutcome
	 *
	 * @spec openspec/changes/bpv-hours-per-day/specs/bpv/spec.md#requirement-a-week-of-bpv-hours-holds-its-days
	 */
	private static function withDay(array $week, array $line, bool $send): array|PortalOutcome {
		$others = array_filter(
			(array)($week['days'] ?? []),
			static fn (mixed $day): bool => is_array($day) === true && ($day['date'] ?? null) !== $line['date']
		);
		$lines = (new HourDayLines())->normalise(days: [...array_values($others), $line], isoWeek: (string)$week['isoWeek']);
		if ($lines['refusal'] !== null) {
			return new PortalOutcome(status: 422, body: ['error' => 'day_refused'], reason: $lines['refusal']);
		}

		$week['days'] = $lines['days'];
		$week['hoursSubmitted'] = $lines['total'];
		if ($send === true) {
			$week['lifecycle'] = 'submitted';
			$week['submittedAt'] = (new DateTimeImmutable())->format('Y-m-d\TH:i:sP');
		}

		return $week;
	}//end withDay()

	/**
	 * The day line from the form: only the fields a student writes. The hours
	 * are worked out by HourDayLines, a question is her trainer's to write, and
	 * a photo arrives through portaliq's own upload, never as a reference typed
	 * into the form.
	 *
	 * @param array<string, mixed> $body What the form sent.
	 *
	 * @return array<string, mixed>
	 */
	private static function lineOf(array $body): array {
		return [
			'date' => self::text(value: ($body['date'] ?? null)),
			'startTime' => self::text(value: ($body['startTime'] ?? null)),
			'endTime' => self::text(value: ($body['endTime'] ?? null)),
			'breakMinutes' => self::breakOf(value: ($body['breakMinutes'] ?? null)),
			'description' => self::text(value: ($body['description'] ?? null)),
			'werkprocesCodes' => self::codesOf(value: ($body['werkprocesCodes'] ?? null)),
		];
	}//end lineOf()

	/**
	 * The break in whole minutes: an absent or empty break is none, a number
	 * sent as text is read as that number, and anything else is passed on for
	 * HourDayLines to refuse.
	 *
	 * @param mixed $value The break as sent.
	 *
	 * @return mixed
	 */
	private static function breakOf(mixed $value): mixed {
		if ($value === null || $value === '') {
			return 0;
		}

		if (is_string($value) === true && ctype_digit($value) === true) {
			return (int)$value;
		}

		return $value;
	}//end breakOf()

	/**
	 * The work process codes: one code or a list, trimmed, without empties or repeats.
	 *
	 * @param mixed $value The codes as sent.
	 *
	 * @return array<int, string>
	 */
	private static function codesOf(mixed $value): array {
		$codes = array_map(static fn (mixed $code): string => self::text(value: $code), (array)$value);

		return array_slice(array_values(array_unique(array_filter($codes, static fn (string $code): bool => $code !== ''))), 0, self::MAX_CODES);
	}//end codesOf()

	/**
	 * The ISO week a date falls in, as 2026-W41, or null when the date is not a `YYYY-MM-DD` day.
	 *
	 * @param string $date The date.
	 *
	 * @return string|null
	 */
	private static function isoWeekOf(string $date): ?string {
		$day = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
		if ($day === false || $day->format('Y-m-d') !== $date) {
			return null;
		}

		return $day->format('o-\WW');
	}//end isoWeekOf()

	/**
	 * Whether the placement is this student's own.
	 *
	 * @param string $learnerRef  The student's learner profile uuid.
	 * @param string $placementId The placement's uuid.
	 *
	 * @return bool
	 */
	private function ownsPlacement(string $learnerRef, string $placementId): bool {
		$placement = $this->first(
			config: ['filters' => ['register' => self::REGISTER, 'schema' => self::PLACEMENT_SCHEMA], 'ids' => [$placementId], 'limit' => 1]
		);

		return $placement !== null
			&& self::idOf(row: $placement) === $placementId
			&& self::text(value: ($placement['learnerRef'] ?? null)) === $learnerRef;
	}//end ownsPlacement()

	/**
	 * The placement's week of hours for an ISO week, or null when there is none yet.
	 *
	 * @param string $placementId The placement's uuid.
	 * @param string $isoWeek     The week, as 2026-W41.
	 *
	 * @return array<string, mixed>|null
	 */
	private function weekOf(string $placementId, string $isoWeek): ?array {
		return $this->first(
			config: [
				'filters' => ['register' => self::REGISTER, 'schema' => self::WEEK_SCHEMA, 'bpvPlacementId' => $placementId, 'isoWeek' => $isoWeek],
				'limit' => 1,
			]
		);
	}//end weekOf()

	/**
	 * The first row a read finds, RBAC off (the receiver has no session), or null.
	 *
	 * @param array<string, mixed> $config The read.
	 *
	 * @return array<string, mixed>|null
	 */
	private function first(array $config): ?array {
		$found = $this->objectService->findAll(config: $config, _rbac: false, _multitenancy: false);
		foreach ($found as $object) {
			return (new SerialisedRow())->asArray(object: $object);
		}

		return null;
	}//end first()

	/**
	 * Store the week and answer with what she reads back.
	 *
	 * @param array<string, mixed> $week The week to store.
	 *
	 * @return PortalOutcome
	 */
	private function saved(array $week): PortalOutcome {
		$saved = (new SerialisedRow())->asArray(
			object: $this->objectService->saveObject(object: $week, register: self::REGISTER, schema: self::WEEK_SCHEMA, _rbac: false, _multitenancy: false)
		);

		return new PortalOutcome(
			status: 200,
			body: [
				'hourWeekId' => self::idOf(row: $saved),
				'isoWeek' => ($saved['isoWeek'] ?? $week['isoWeek']),
				'lifecycle' => ($saved['lifecycle'] ?? $week['lifecycle']),
				'hoursSubmitted' => ($saved['hoursSubmitted'] ?? $week['hoursSubmitted']),
			]
		);
	}//end saved()

	/**
	 * A row's id.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string
	 */
	private static function idOf(array $row): string {
		return self::text(value: ($row['id'] ?? ($row['uuid'] ?? null)));
	}//end idOf()

	/**
	 * A string value, trimmed, or '' for anything else.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private static function text(mixed $value): string {
		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end text()
}//end class
