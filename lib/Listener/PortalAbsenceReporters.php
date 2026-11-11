<?php

/**
 * Learniq portal absence reporters
 *
 * The pupil a portal absence report is about, and the guardian who files it
 * when a guardian does, as ExcuseRequestOwnerStamp looks them up.
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
 * @spec openspec/specs/attendance/spec.md#requirement-the-server-stamps-who-an-excuse-request-is-about-and-who-filed-it
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

/**
 * The pupil's profile and, when a guardian files the report, the guardian's.
 *
 * @spec openspec/specs/attendance/spec.md#requirement-the-server-stamps-who-an-excuse-request-is-about-and-who-filed-it
 */
final class PortalAbsenceReporters {

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed>      $child    The pupil's LearnerProfile.
	 * @param array<string,mixed>|null $guardian The guardian's LearnerProfile, or null when none was named or found.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly array $child,
		public readonly ?array $guardian,
	) {
	}//end __construct()
}//end class
