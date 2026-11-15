<?php

/**
 * Learniq CertificateCopies
 *
 * The readable copies a certificate carries for the portals: the holder's
 * name and employer, the course's name, "Geldig tot 30 november 2026", and
 * the booked renewal ("Herhaling op 8 oktober"). The employer's certificate
 * list and the participant's read them from the row, because a portal list
 * joins nothing (portal-certificates).
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
 * @spec openspec/changes/portal-certificates/specs/portal-contribution/spec.md#requirement-a-certificate-names-its-holder-its-course-and-its-renewal
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\Learniq\Service\Portal\CourseDayLines;

/**
 * Derives a certificate's readable copies.
 *
 * @spec openspec/changes/portal-certificates/specs/portal-contribution/spec.md#requirement-a-certificate-names-its-holder-its-course-and-its-renewal
 */
class CertificateCopies {

	/**
	 * The renewal enrolment states that still lead to a course day.
	 *
	 * @var array<int, string>
	 */
	private const COMING = ['pending', 'active'];

	/**
	 * Constructor.
	 *
	 * @param \Closure $rows     Reads one row: fn (string $schema, mixed $id): ?array.
	 * @param \Closure $sessions Reads a cohort's sessions: fn (string $cohortId): array.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly \Closure $rows,
		private readonly \Closure $sessions,
	) {
	}//end __construct()

	/**
	 * The copies for one certificate.
	 *
	 * @param array<string, mixed> $credential The certificate as it will be stored.
	 *
	 * @return array<string, string|null>
	 *
	 * @throws \Throwable When OpenRegister cannot be read.
	 *
	 * @spec openspec/changes/portal-certificates/specs/portal-contribution/spec.md#requirement-a-certificate-names-its-holder-its-course-and-its-renewal
	 */
	public function derive(array $credential): array {
		$lines = new CourseDayLines();
		$holder = ($this->rows)('learner-profile', ($credential['learnerId'] ?? null));
		$course = ($this->rows)('course', ($credential['courseId'] ?? null));
		$expires = $lines->date(value: ($credential['expiresAt'] ?? null));

		$copies = [
			'learnerName' => null,
			'courseName' => self::orNull(value: (string)($course['name'] ?? '')),
			'organisationRef' => null,
			'validUntilLabel' => null,
			'renewalLine' => $this->renewalLine(enrolmentId: ($credential['renewalEnrolmentId'] ?? null), lines: $lines),
		];
		if ($holder !== null) {
			$copies['learnerName'] = self::orNull(value: trim((string)($holder['givenName'] ?? '')) . ' ' . trim((string)($holder['familyName'] ?? '')));
			$copies['organisationRef'] = self::orNull(value: (string)($holder['organisationRef'] ?? ''));
		}

		if ($expires !== null) {
			$copies['validUntilLabel'] = 'Geldig tot ' . $lines->longDate(day: $expires);
		}

		return $copies;
	}//end derive()

	/**
	 * "Herhaling op 8 oktober": the first day of the renewal edition, while it is still coming.
	 *
	 * @param mixed          $enrolmentId The renewal enrolment.
	 * @param CourseDayLines $lines       The day lines.
	 *
	 * @return string|null
	 *
	 * @throws \Throwable When OpenRegister cannot be read.
	 */
	private function renewalLine(mixed $enrolmentId, CourseDayLines $lines): ?string {
		$enrolment = ($this->rows)('enrolment', $enrolmentId);
		if ($enrolment === null || in_array(($enrolment['lifecycle'] ?? ''), self::COMING, true) === false) {
			return null;
		}

		$days = $lines->days(sessions: ($this->sessions)((string)($enrolment['cohortId'] ?? '')));
		if ($days === []) {
			return null;
		}

		return 'Herhaling op ' . $lines->dayMonth(day: $days[0]);
	}//end renewalLine()

	/**
	 * A text, or null when empty.
	 *
	 * @param string $value The text.
	 *
	 * @return string|null
	 */
	private static function orNull(string $value): ?string {
		$value = trim($value);
		if ($value === '') {
			return null;
		}

		return $value;
	}//end orNull()
}//end class
