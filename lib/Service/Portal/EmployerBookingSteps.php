<?php

/**
 * Learniq EmployerBookingSteps
 *
 * The five steps of a company's booking, as the employer reads them on the
 * booking (board Detail: "Waar staat deze inschrijving?"): booked, confirmed,
 * details complete, the course day, the result and certificate. Portaliq asks
 * the provider for them with the booking's id, after it checked that the
 * employer may see that booking (portaliq REQ-SMO-022).
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
 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-a-booking-tells-the-employer-what-still-waits-for-her
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Portal;

use OCP\IL10N;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Answers a booking's steps.
 *
 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-a-booking-tells-the-employer-what-still-waits-for-her
 */
class EmployerBookingSteps {

	/**
	 * Constructor.
	 *
	 * @param EmployerBookingProjection $rows   Reads the booking.
	 * @param IFactory                  $l10n   The labels in the reader's language.
	 * @param LoggerInterface           $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly EmployerBookingProjection $rows,
		private readonly IFactory $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The steps of one booking, or none when it cannot be read.
	 *
	 * @param string $bookingId The booking's uuid.
	 *
	 * @return array<int, array{label: string, state: string, description?: string, date?: string}>
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-a-booking-tells-the-employer-what-still-waits-for-her
	 */
	public function forBooking(string $bookingId): array {
		try {
			$booking = $this->rows->one(schema: 'course-booking', id: $bookingId);
		} catch (Throwable $exception) {
			$this->logger->warning('[EmployerBookingSteps] Could not read a booking: {msg}', ['msg' => $exception->getMessage()]);
			return [];
		}

		if ($booking === null) {
			return [];
		}

		return $this->stepsOf(booking: $booking, l10n: $this->l10n->get('learniq'));
	}//end forBooking()

	/**
	 * The steps a booking row is at.
	 *
	 * @param array<string, mixed> $booking The booking.
	 * @param IL10N|null           $l10n    The labels' language, or null for English.
	 *
	 * @return array<int, array{label: string, state: string, description?: string, date?: string}>
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-a-booking-tells-the-employer-what-still-waits-for-her
	 */
	public function stepsOf(array $booking, ?IL10N $l10n): array {
		$note = (string)($booking['statusNote'] ?? '');
		$places = (int)($booking['participantCount'] ?? 0);
		$placesText = $this->text(l10n: $l10n, text: '%s places', parameters: [$places]);
		if ($places === 1) {
			$placesText = $this->text(l10n: $l10n, text: '%s place', parameters: [$places]);
		}

		$stage = $this->stage(booking: $booking);
		$confirmedText = $note;
		$detailsText = $note;
		if ($stage > 1) {
			$confirmedText = $placesText;
		}

		if ($stage > 2) {
			$detailsText = '';
		}

		$steps = [
			['Booked', (string)($booking['requestedByName'] ?? ''), ($booking['requestedAt'] ?? null)],
			['Confirmed', $confirmedText, ($booking['confirmedAt'] ?? null)],
			['Details complete', $detailsText, null],
			['Course day', (string)($booking['dayLabel'] ?? ''), null],
			['Result and certificate', $this->text(l10n: $l10n, text: 'Within 10 working days after the course'), null],
		];

		$out = [];
		foreach ($steps as $index => [$label, $description, $date]) {
			$out[] = $this->step(
				label: $this->text(l10n: $l10n, text: $label),
				state: $this->state(index: $index, stage: $stage),
				description: $description,
				date: $date
			);
		}

		return $out;
	}//end stepsOf()

	/**
	 * Which step a booking is at: 1 waiting for the planner, 2 waiting for
	 * the employer's details, 3 waiting for the course day, 4 after it.
	 *
	 * @param array<string, mixed> $booking The booking.
	 *
	 * @return int
	 */
	private function stage(array $booking): int {
		$lifecycle = (string)($booking['lifecycle'] ?? 'received');
		if ($lifecycle === 'completed') {
			return 4;
		}

		if ($lifecycle !== 'confirmed') {
			return 1;
		}

		if (($booking['employerStatus'] ?? '') === 'waiting-for-you') {
			return 2;
		}

		return 3;
	}//end stage()

	/**
	 * A step's state: done before the stage, current at it, todo after it.
	 *
	 * @param int $index The step.
	 * @param int $stage The stage.
	 *
	 * @return string
	 */
	private function state(int $index, int $stage): string {
		if ($index < $stage) {
			return 'done';
		}

		if ($index === $stage) {
			return 'current';
		}

		return 'todo';
	}//end state()

	/**
	 * A text in the reader's language, or English.
	 *
	 * @param IL10N|null          $l10n       The language.
	 * @param string              $text       The English text.
	 * @param array<int, mixed>   $parameters Its parameters.
	 *
	 * @return string
	 */
	private function text(?IL10N $l10n, string $text, array $parameters=[]): string {
		if ($l10n === null) {
			return vsprintf($text, $parameters);
		}

		return $l10n->t($text, $parameters);
	}//end text()

	/**
	 * One step, without empty parts.
	 *
	 * @param string $label       The step.
	 * @param string $state       done, current or todo.
	 * @param string $description The line under it.
	 * @param mixed  $date        A date, if any.
	 *
	 * @return array{label: string, state: string, description?: string, date?: string}
	 */
	private function step(string $label, string $state, string $description='', mixed $date=null): array {
		$step = ['label' => $label, 'state' => $state];
		if (trim($description) !== '') {
			$step['description'] = trim($description);
		}

		if (is_string($date) === true && $date !== '') {
			$step['date'] = $date;
		}

		return $step;
	}//end step()
}//end class
