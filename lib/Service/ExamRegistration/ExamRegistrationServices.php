<?php

/**
 * Learniq ExamRegistrationServices
 *
 * The services the exam registration endpoints call: the registration lists,
 * the certificate issue and the administration's Today.
 *
 * @category Service
 * @package  OCA\Learniq\Service\ExamRegistration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/certification/spec.md#requirement-an-exam-registration-list-closes-at-its-moment-and-names-who-is-incomplete
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\ExamRegistration;

use OCA\Learniq\Kernel\Contracts\AdministrationTodayServiceInterface;
use OCA\Learniq\Kernel\Contracts\CertificateIssueServiceInterface;
use OCA\Learniq\Kernel\Contracts\ExamRegistrationServiceInterface;

/**
 * The registration lists, the certificate issue and the administration's Today.
 *
 * @spec openspec/specs/certification/spec.md#requirement-an-exam-registration-list-closes-at-its-moment-and-names-who-is-incomplete
 */
final class ExamRegistrationServices {
	/**
	 * Constructor.
	 *
	 * @param ExamRegistrationServiceInterface    $lists        The registration lists.
	 * @param CertificateIssueServiceInterface    $certificates Issues a course day's certificates.
	 * @param AdministrationTodayServiceInterface $today        The administration's Today.
	 */
	public function __construct(
		public readonly ExamRegistrationServiceInterface $lists,
		public readonly CertificateIssueServiceInterface $certificates,
		public readonly AdministrationTodayServiceInterface $today,
	) {
	}//end __construct()
}//end class
