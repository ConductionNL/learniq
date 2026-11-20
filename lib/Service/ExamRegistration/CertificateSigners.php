<?php

/**
 * Learniq CertificateSigners
 *
 * What a certificate passes through before it is saved: the credential
 * signature and the signed Europass form.
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
 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#requirement-the-certificates-of-a-finished-course-day-are-issued-in-one-action
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\ExamRegistration;

use OCA\Learniq\Kernel\Contracts\CredentialSigningServiceInterface;
use OCA\Learniq\Kernel\Contracts\EuropassIssuerInterface;

/**
 * The credential signature and the signed Europass form of a certificate.
 *
 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#requirement-the-certificates-of-a-finished-course-day-are-issued-in-one-action
 */
final class CertificateSigners {
	/**
	 * Constructor.
	 *
	 * @param CredentialSigningServiceInterface $signing  Signs each certificate before it is saved.
	 * @param EuropassIssuerInterface           $europass Adds the signed Europass form.
	 */
	public function __construct(
		public readonly CredentialSigningServiceInterface $signing,
		public readonly EuropassIssuerInterface $europass,
	) {
	}//end __construct()
}//end class
