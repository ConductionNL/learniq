<?php

/**
 * Learniq Programme Enrolment
 *
 * The programme a learner enrols in from the catalogue, with the id they asked for.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Catalogue
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
 * @spec exclude Value object of the catalogue enrolment flow, not a behavioural requirement.
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Catalogue;

/**
 * An open programme and the programme id each of its course enrolments names.
 *
 * @spec exclude Value object of the catalogue enrolment flow, not a behavioural requirement.
 */
final class ProgrammeEnrolment {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $programme   The open, published programme.
	 * @param string               $programmeId The programme uuid as asked for.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly array $programme,
		public readonly string $programmeId,
	) {
	}//end __construct()
}//end class
