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
		$t = static fn (string $text, array $parameters=[]): string => $l10n === null ? vsprintf($text, $parameters) : $l10n->t($text, $parameters);
		$lifecycle = (string)($booking['lifecycle'] ?? 'received');
		$status = (string)($booking['employerStatus'] ?? $lifecycle);
		$confirmed = in_array($lifecycle, ['confirmed', 'completed'], true);
		$completed = $lifecycle === 'completed';
		$detailsDone = $confirmed === true && $status !== 'waiting-for-you';
		$places = (int)($booking['participantCount'] ?? 0);

		return [
			$this->step(label: $t('Booked'), state: 'done', description: (string)($booking['requestedByName'] ?? ''), date: ($booking['requestedAt'] ?? null)),
			$this->step(
				label: $t('Confirmed'),
				state: $confirmed === true ? 'done' : 'current',
				description: $confirmed === true ? $t($places === 1 ? '%s place' : '%s places', [$places]) : (string)($booking['statusNote'] ?? ''),
				date: ($booking['confirmedAt'] ?? null)
			),
			$this->step(
				label: $t('Details complete'),
				state: $detailsDone === true ? 'done' : ($confirmed === true ? 'current' : 'todo'),
				description: $detailsDone === true ? '' : (string)($booking['statusNote'] ?? '')
			),
			$this->step(label: $t('Course day'), state: $completed === true ? 'done' : ($detailsDone === true ? 'current' : 'todo'), description: (string)($booking['dayLabel'] ?? '')),
			$this->step(label: $t('Result and certificate'), state: $completed === true ? 'current' : 'todo', description: $t('Within 10 working days after the course')),
		];
	}//end stepsOf()

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
