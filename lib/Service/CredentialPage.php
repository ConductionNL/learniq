<?php

/**
 * Learniq credential page.
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
 * @spec openspec/specs/certification/spec.md#requirement-staff-reissue-every-certificate-of-a-course-in-one-action
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

/**
 * Which page of a course's credentials CredentialReissueService reads: from
 * where, and how many.
 *
 * @spec openspec/specs/certification/spec.md#requirement-staff-reissue-every-certificate-of-a-course-in-one-action
 */
final class CredentialPage {

	/**
	 * Constructor.
	 *
	 * @param int $offset The offset.
	 * @param int $limit  The page size.
	 */
	public function __construct(
		public readonly int $offset,
		public readonly int $limit,
	) {
	}//end __construct()
}//end class
