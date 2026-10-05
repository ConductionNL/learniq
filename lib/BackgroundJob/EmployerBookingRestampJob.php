<?php

/**
 * Learniq EmployerBookingRestampJob
 *
 * Re-derives the bookings EmployerBookingCascade buffered during a request:
 * by booking, or every booking a person is on (employer-portal-audience).
 *
 * @category BackgroundJob
 * @package  OCA\Learniq\BackgroundJob
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

namespace OCA\Learniq\BackgroundJob;

use OCA\Learniq\Service\Portal\EmployerBookingProjection;
use OCA\OpenRegister\BackgroundJob\ActorForwardedJob;
use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Re-derives the buffered bookings once each.
 *
 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-a-booking-tells-the-employer-what-still-waits-for-her
 */
class EmployerBookingRestampJob extends ActorForwardedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory              $time         Clock.
	 * @param IUserSession              $userSession  The acting user, forwarded.
	 * @param IUserManager              $userManager  Resolves the forwarded user.
	 * @param OrganisationService       $organisation The acting organisation, forwarded.
	 * @param LoggerInterface           $logger       Logger.
	 * @param EmployerBookingProjection $projection   Re-derives one booking.
	 *
	 * @return void
	 */
	public function __construct(
		ITimeFactory $time,
		IUserSession $userSession,
		IUserManager $userManager,
		OrganisationService $organisation,
		private readonly LoggerInterface $log,
		private readonly EmployerBookingProjection $projection,
	) {
		parent::__construct(
			time: $time,
			userSession: $userSession,
			userManager: $userManager,
			organisation: $organisation,
			logger: $log
		);
	}//end __construct()

	/**
	 * Re-derive each buffered booking once.
	 *
	 * @param DeferredListenerContext $context The buffered entries.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-a-booking-tells-the-employer-what-still-waits-for-her
	 */
	protected function runDeferred(DeferredListenerContext $context): void {
		try {
			foreach (array_keys($this->bookings(entries: $context->getEntries())) as $bookingId) {
				$this->projection->project(bookingId: (string)$bookingId);
			}
		} catch (Throwable $exception) {
			$this->log->warning('[EmployerBookingRestampJob] A booking could not be re-derived: {msg}', ['msg' => $exception->getMessage()]);
		}
	}//end runDeferred()

	/**
	 * The booking ids the entries name, directly or through a person's enrolments.
	 *
	 * @param array<int, array<string, mixed>> $entries The buffered entries.
	 *
	 * @return array<string, true>
	 *
	 * @throws \Throwable When OpenRegister cannot be read.
	 */
	private function bookings(array $entries): array {
		$ids = [];
		foreach ($entries as $entry) {
			$booking = (string)($entry['bookingRef'] ?? '');
			if ($booking !== '') {
				$ids[$booking] = true;
				continue;
			}

			$learner = (string)($entry['learnerRef'] ?? '');
			if ($learner === '') {
				continue;
			}

			foreach ($this->projection->many(schema: 'enrolment', filters: ['learnerRef' => $learner]) as $enrolment) {
				$ref = (string)($enrolment['bookingRef'] ?? '');
				if ($ref !== '') {
					$ids[$ref] = true;
				}
			}
		}

		return $ids;
	}//end bookings()
}//end class
