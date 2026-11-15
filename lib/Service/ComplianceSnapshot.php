<?php

/**
 * Learniq Compliance Snapshot
 *
 * What one compliance roll-up counts over: the published regulations, the
 * granted exemptions, the mandatory enrolments and the credentials, read
 * once, and the evaluation instant with the end of its upcoming window.
 *
 * @category Service
 * @package  OCA\Learniq\Service
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
 * @spec openspec/parity/capabilities.json#comp-roll-up-by-department
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use DateInterval;
use DateTimeImmutable;

/**
 * The rows and the instant one roll-up evaluates every learner against.
 *
 * @spec openspec/parity/capabilities.json#comp-roll-up-by-department
 */
final class ComplianceSnapshot {

	private const UPCOMING_WINDOW_DAYS = 30;

	/**
	 * Published, enforced regulations.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public readonly array $regulations;

	/**
	 * Granted regulation exemptions.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public readonly array $exemptions;

	/**
	 * End of the upcoming-deadline window.
	 *
	 * @var DateTimeImmutable
	 */
	public readonly DateTimeImmutable $horizon;

	/**
	 * Constructor.
	 *
	 * @param array{regulations:array<int,array<string,mixed>>,exemptions:array<int,array<string,mixed>>} $population  The roll-up population.
	 * @param array<int,array<string,mixed>>                                                           $enrolments  Mandatory enrolments.
	 * @param array<int,array<string,mixed>>                                                           $credentials All credentials.
	 * @param DateTimeImmutable                                                                        $now         Evaluation instant.
	 *
	 * @return void
	 */
	public function __construct(
		array $population,
		public readonly array $enrolments,
		public readonly array $credentials,
		public readonly DateTimeImmutable $now,
	) {
		$this->regulations = $population['regulations'];
		$this->exemptions  = $population['exemptions'];
		$this->horizon     = $now->add(new DateInterval('P'.self::UPCOMING_WINDOW_DAYS.'D'));
	}//end __construct()
}//end class
