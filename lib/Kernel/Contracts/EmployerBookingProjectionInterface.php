<?php

/**
 * Contract of EmployerBookingProjection
 *
 * The public surface of EmployerBookingProjection that other components call, so they depend
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

/**
 * Contract of EmployerBookingProjection.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface EmployerBookingProjectionInterface {

	/**
	 * The rows of a schema whose fields equal the filters.
	 *
	 * @param string                $schema  The schema slug.
	 * @param array<string, string> $filters Field equals value.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @throws \Throwable When OpenRegister cannot be read.
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-a-booking-tells-the-employer-what-still-waits-for-her
	 */
	public function many(string $schema, array $filters): array;

	/**
	 * Re-derive one booking; answers the stored booking, or null when it does not exist.
	 *
	 * @param string $bookingId The booking's uuid.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @throws \Throwable When OpenRegister cannot be read or written.
	 *
	 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-a-booking-tells-the-employer-what-still-waits-for-her
	 */
	public function project(string $bookingId): ?array;
}//end interface
