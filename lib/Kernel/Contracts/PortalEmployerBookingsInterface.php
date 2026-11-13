<?php

/**
 * Contract of PortalEmployerBookings
 *
 * The public surface of PortalEmployerBookings that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Learniq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Kernel\Contracts;

use OCA\Learniq\Service\Portal\PortalOutcome;

/**
 * Contract of PortalEmployerBookings.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalEmployerBookingsInterface {

	/**
	 * Name one of the company's people for an open place of its booking.
	 *
	 * @param string               $organisationRef The company, from the claim.
	 * @param array<string, mixed> $body            `bookingRef`, `learnerRef`.
	 *
	 * @return PortalOutcome 201 with the booking, or 403 / 404 / 409 / 422 / 502.
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-an-employer-books-places-and-names-her-participants
	 */
	public function addParticipant(string $organisationRef, array $body): PortalOutcome;

	/**
	 * Book a number of places on an edition of the company's location.
	 *
	 * @param string               $organisationRef The company, from the claim.
	 * @param array<string, mixed> $body            `cohortId`, `participantCount`.
	 *
	 * @return PortalOutcome 201 with the booking, or 403 / 404 / 409 / 422 / 502.
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-an-employer-books-places-and-names-her-participants
	 */
	public function book(string $organisationRef, array $body): PortalOutcome;

	/**
	 * Store a birth date for one of the company's participants.
	 *
	 * @param string               $organisationRef The company, from the claim.
	 * @param array<string, mixed> $body            `learnerRef`, `birthDate` (yyyy-mm-dd).
	 *
	 * @return PortalOutcome 200 `{learnerRef, detailsStatus}`, or 404 / 409 / 422 / 502.
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-an-employer-supplies-a-missing-birth-date-and-never-reads-it
	 */
	public function supplyBirthDate(string $organisationRef, array $body): PortalOutcome;
}//end interface
