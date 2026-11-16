<?php

/**
 * Learniq CertificateIssueService
 *
 * "Certificaten uitgeven" for a course day in rounding off: issues the
 * certificate to every participant with a passing result in one action,
 * records who issued and when on each, and names who was skipped and why.
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

use DateTimeImmutable;
use OCA\Learniq\Kernel\Contracts\CertificateIssueServiceInterface;
use OCA\Learniq\Kernel\Contracts\CredentialSigningServiceInterface;
use OCA\Learniq\Kernel\Contracts\EuropassIssuerInterface;
use OCA\Learniq\Service\CourseDay\CourseDayException;
use OCA\Learniq\Service\CourseDay\CourseDayRows;
use OCA\Learniq\Service\CourseDay\CourseDayStatus;
use OCA\Learniq\Support\SerialisedRow;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * Issues the certificates of a finished course day.
 *
 * A participant passed when the examining body's result on the day's
 * registration list is `pass`; on a day without a result on any list, when
 * the enrolment is completed. A result `fail` and no result at all are both
 * skipped and named. A participant who already holds a certificate for the
 * enrolment is not issued a second one.
 *
 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#requirement-the-certificates-of-a-finished-course-day-are-issued-in-one-action
 */
class CertificateIssueService implements CertificateIssueServiceInterface {
	use CourseDayRows;

	/**
	 * Months of validity a rule has when it sets none, as the Regulation schema defaults it.
	 *
	 * @var int
	 */
	private const DEFAULT_RENEWAL_CYCLE_MONTHS = 12;

	/**
	 * Why a participant is skipped, in a few words.
	 *
	 * @var array<string, string>
	 */
	private const REASONS = [
		'no-result' => 'geen uitslag',
		'failed' => 'niet geslaagd',
		'unsigned' => 'kon niet worden ondertekend',
	];

	/**
	 * Constructor.
	 *
	 * @param ObjectService                     $objectService The OpenRegister object service.
	 * @param ITimeFactory                      $time          The clock.
	 * @param CredentialSigningServiceInterface $signing       Signs each certificate before it is saved.
	 * @param EuropassIssuerInterface           $europass      Adds the signed Europass form.
	 * @param LoggerInterface                   $logger        Records a certificate that could not be signed.
	 *
	 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#requirement-the-certificates-of-a-finished-course-day-are-issued-in-one-action
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly ITimeFactory $time,
		private readonly CredentialSigningServiceInterface $signing,
		private readonly EuropassIssuerInterface $europass,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Issue the certificates of a course day in rounding off.
	 *
	 * @param string $cohortId The course day.
	 * @param string $userId   Who issues them.
	 *
	 * @return array<string, mixed> `issued` (enrolmentId, learnerName, credentialId), `skipped`
	 *                              (enrolmentId, learnerName, reason, sentence), `alreadyIssued` and `summary`.
	 *
	 * @throws CourseDayException When the day is no course day or not in rounding off.
	 * @throws \Throwable         When OpenRegister cannot be read or written.
	 *
	 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#scenario-thursdays-f-gassen-day
	 */
	public function issue(string $cohortId, string $userId): array {
		$cohort = $this->one(schema: 'cohort', id: $cohortId);
		if ($cohort === null || (string)($cohort['courseId'] ?? '') === '') {
			throw new CourseDayException(message: 'This is not a course day.', status: 404);
		}

		if (($cohort['dayStatus'] ?? null) !== CourseDayStatus::ROUNDING_OFF) {
			throw new CourseDayException(message: 'Certificates are issued for a course day in rounding off.', status: 409);
		}

		$course  = ($this->one(schema: 'course', id: (string)$cohort['courseId']) ?? []);
		$lists   = $this->many(schema: ExamRegistrationService::SCHEMA, filters: ['cohortId' => $cohortId]);
		$results = self::results(lists: $lists);
		$now     = $this->now();
		$issued  = [];
		$skipped = [];
		$already = 0;
		foreach ($this->participants(cohortId: $cohortId) as $enrolment) {
			$enrolmentId = (string)($enrolment['id'] ?? ($enrolment['uuid'] ?? ''));
			$name        = self::nameOf(enrolment: $enrolment, line: ($results[$enrolmentId] ?? null));
			$reason      = self::refusal(enrolment: $enrolment, line: ($results[$enrolmentId] ?? null), resultsKept: $results !== []);
			if ($reason !== null) {
				$skipped[] = self::skip(enrolmentId: $enrolmentId, name: $name, reason: $reason);
				continue;
			}

			if ($this->hasCertificate(enrolmentId: $enrolmentId) === true) {
				$already++;
				continue;
			}

			$credentialId = $this->issueOne(enrolment: $enrolment, course: $course, cohort: $cohort, userId: $userId, now: $now);
			if ($credentialId === null) {
				$skipped[] = self::skip(enrolmentId: $enrolmentId, name: $name, reason: 'unsigned');
				continue;
			}

			$issued[] = ['enrolmentId' => $enrolmentId, 'learnerName' => $name, 'credentialId' => $credentialId];
		}

		$this->linkCertificates(lists: $lists, issued: $issued);

		return [
			'issued' => $issued,
			'skipped' => $skipped,
			'alreadyIssued' => $already,
			'summary' => self::summary(issued: count($issued), skipped: $skipped, already: $already),
		];
	}//end issue()

	/**
	 * "10 certificaten uitgegeven. Niet uitgegeven: Sanne de Wit (geen uitslag)."
	 *
	 * @param int                                       $issued  How many were issued.
	 * @param list<array{learnerName: string|null, reason: string}> $skipped Who was skipped.
	 * @param int                                       $already How many already held one.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/exam-registration-and-certificate-issue/specs/certification/spec.md#scenario-thursdays-f-gassen-day
	 */
	public static function summary(int $issued, array $skipped, int $already): string {
		$noun = 'certificaten';
		if ($issued === 1) {
			$noun = 'certificaat';
		}

		$line = $issued . ' ' . $noun . ' uitgegeven.';
		if ($already > 0) {
			$line .= ' ' . $already . ' had al een certificaat.';
		}

		if ($skipped !== []) {
			$names = array_map(
				static fn (array $row): string => (($row['learnerName'] ?? null) ?? 'Een deelnemer') . ' (' . self::REASONS[$row['reason']] . ')',
				$skipped
			);
			$line .= ' Niet uitgegeven: ' . implode(', ', $names) . '.';
		}

		return $line;
	}//end summary()

	/**
	 * The result line per enrolment, from the day's lists; a line with a result wins.
	 *
	 * @param list<array<string, mixed>> $lists The day's registration lists.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function results(array $lists): array {
		$results = [];
		foreach ($lists as $list) {
			foreach ((array)($list['lines'] ?? []) as $line) {
				$id = (string)($line['enrolmentId'] ?? '');
				if ($id === '' || ($line['result'] ?? null) === null) {
					continue;
				}

				$results[$id] = $line;
			}
		}

		return $results;
	}//end results()

	/**
	 * Why the participant gets no certificate, or null when they passed.
	 *
	 * With results kept on a list, only `pass` passes. A day whose lists hold
	 * no result at all reads the enrolment: completed passes.
	 *
	 * @param array<string, mixed>      $enrolment   The enrolment.
	 * @param array<string, mixed>|null $line        The participant's result line.
	 * @param bool                      $resultsKept Whether any list of the day holds a result.
	 *
	 * @return string|null
	 */
	private static function refusal(array $enrolment, ?array $line, bool $resultsKept): ?string {
		if ($line !== null) {
			if ($line['result'] === 'pass') {
				return null;
			}

			return 'failed';
		}

		if ($resultsKept === false && ($enrolment['lifecycle'] ?? null) === 'completed') {
			return null;
		}

		if (($enrolment['lifecycle'] ?? null) === 'failed') {
			return 'failed';
		}

		return 'no-result';
	}//end refusal()

	/**
	 * A skipped participant.
	 *
	 * @param string      $enrolmentId The enrolment.
	 * @param string|null $name        The participant's name.
	 * @param string      $reason      The reason key.
	 *
	 * @return array{enrolmentId: string, learnerName: string|null, reason: string, sentence: string}
	 */
	private static function skip(string $enrolmentId, ?string $name, string $reason): array {
		return [
			'enrolmentId' => $enrolmentId,
			'learnerName' => $name,
			'reason' => $reason,
			'sentence' => (($name ?? 'Een deelnemer') . ': ' . self::REASONS[$reason] . '.'),
		];
	}//end skip()

	/**
	 * The participant's name, from the list or the enrolment's readable copy.
	 *
	 * @param array<string, mixed>      $enrolment The enrolment.
	 * @param array<string, mixed>|null $line      The result line.
	 *
	 * @return string|null
	 */
	private static function nameOf(array $enrolment, ?array $line): ?string {
		$name = trim((string)($line['learnerName'] ?? ($enrolment['learnerName'] ?? '')));
		if ($name === '') {
			return null;
		}

		return $name;
	}//end nameOf()

	/**
	 * Whether the enrolment already led to a certificate.
	 *
	 * @param string $enrolmentId The enrolment.
	 *
	 * @return bool
	 */
	private function hasCertificate(string $enrolmentId): bool {
		if ($enrolmentId === '') {
			return false;
		}

		foreach ($this->many(schema: 'credential', filters: ['enrolmentId' => $enrolmentId]) as $credential) {
			if (($credential['lifecycle'] ?? 'issued') !== 'revoked') {
				return true;
			}
		}

		return false;
	}//end hasCertificate()

	/**
	 * Sign and save one certificate; null when it cannot be signed.
	 *
	 * @param array<string, mixed> $enrolment The enrolment.
	 * @param array<string, mixed> $course    The course.
	 * @param array<string, mixed> $cohort    The course day.
	 * @param string               $userId    Who issues it.
	 * @param string               $now       Now (ISO 8601).
	 *
	 * @return string|null The certificate's uuid.
	 */
	private function issueOne(array $enrolment, array $course, array $cohort, string $userId, string $now): ?string {
		$tenantId   = (string)($enrolment['tenant_id'] ?? ($cohort['tenant_id'] ?? ''));
		$credential = [
			'learnerId' => (string)($enrolment['learnerRef'] ?? ''),
			'learnerUserId' => ($enrolment['learnerId'] ?? null),
			'courseId' => ($enrolment['courseId'] ?? ($cohort['courseId'] ?? null)),
			'enrolmentId' => (string)($enrolment['id'] ?? ($enrolment['uuid'] ?? '')),
			'kind' => 'certificate',
			'issuedAt' => $now,
			'expiresAt' => $this->expiresAt(course: $course, issuedAt: $now, tenantId: $tenantId),
			'issuedBy' => $this->issuerName(tenantId: $tenantId),
			'issuedByUserId' => $userId,
			'source' => 'manual',
			'regulationSlug' => ($course['regulationSlug'] ?? null),
			'tenant_id' => $tenantId,
		];

		$signed = $this->signing->sign(credential: $credential);
		if ($signed === null) {
			$this->logger->error(
				'[CertificateIssueService] No certificate issued for enrolment {enrolment}: it could not be signed.',
				['enrolment' => $credential['enrolmentId'], 'tenant' => $tenantId]
			);
			return null;
		}

		$signed = $this->europass->withEuropass(credential: $signed);
		$id     = (string)($signed['id'] ?? '');
		$uuid   = null;
		if ($id !== '') {
			$uuid = $id;
		}

		unset($signed['id']);
		$saved = $this->objectService->saveObject(
			object: $signed,
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: 'learniq', schema: 'credential'), uuid: $uuid),
			_rbac: false,
			_multitenancy: false
		);
		$row = (new SerialisedRow())->asArray(object: $saved);

		return (string)($row['id'] ?? ($row['uuid'] ?? $id));
	}//end issueOne()

	/**
	 * Write each issued certificate onto its line of the day's lists.
	 *
	 * @param list<array<string, mixed>>                       $lists  The day's lists.
	 * @param list<array{enrolmentId: string, credentialId: string}> $issued What was issued.
	 *
	 * @return void
	 */
	private function linkCertificates(array $lists, array $issued): void {
		$byEnrolment = array_column($issued, 'credentialId', 'enrolmentId');
		if ($byEnrolment === []) {
			return;
		}

		foreach ($lists as $list) {
			$moved = false;
			$lines = [];
			foreach ((array)($list['lines'] ?? []) as $line) {
				$credentialId = ($byEnrolment[(string)($line['enrolmentId'] ?? '')] ?? null);
				if ($credentialId !== null && ($line['result'] ?? null) === 'pass') {
					$line['credentialId'] = $credentialId;
					$moved = true;
				}

				$lines[] = $line;
			}

			if ($moved === false) {
				continue;
			}

			$this->objectService->saveObject(
				object: array_merge($list, ['lines' => $lines]),
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(register: 'learniq', schema: ExamRegistrationService::SCHEMA),
					uuid: (string)($list['id'] ?? ($list['uuid'] ?? ''))
				),
				_rbac: false,
				_multitenancy: false
			);
		}
	}//end linkCertificates()

	/**
	 * The participants still on the day.
	 *
	 * @param string $cohortId The course day.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function participants(string $cohortId): array {
		$rows = array_filter(
			$this->many(schema: 'enrolment', filters: ['cohortId' => $cohortId]),
			static fn (array $row): bool => ($row['lifecycle'] ?? 'pending') !== 'withdrawn'
		);
		usort($rows, static fn (array $one, array $two): int => strcmp((string)($one['learnerName'] ?? ''), (string)($two['learnerName'] ?? '')));

		return $rows;
	}//end participants()

	/**
	 * The certificate's expiry: the course's own validity, else the renewal
	 * cycle of its published rule, counted from the issue; null when neither applies.
	 *
	 * @param array<string, mixed> $course   The course.
	 * @param string               $issuedAt The issue moment.
	 * @param string               $tenantId The tenant.
	 *
	 * @return string|null
	 */
	private function expiresAt(array $course, string $issuedAt, string $tenantId): ?string {
		$from = new DateTimeImmutable($issuedAt);
		if (empty($course['defaultExpiresAfterDays']) === false) {
			return $from->modify('+' . (int)$course['defaultExpiresAfterDays'] . ' days')->format(DATE_ATOM);
		}

		$slug = (string)($course['regulationSlug'] ?? '');
		if ($slug === '' || $tenantId === '') {
			return null;
		}

		foreach ($this->many(schema: 'regulation', filters: ['slug' => $slug, 'tenant_id' => $tenantId]) as $rule) {
			$months = (int)($rule['renewalCycleMonths'] ?? self::DEFAULT_RENEWAL_CYCLE_MONTHS);
			if (($rule['lifecycle'] ?? '') === 'published' && $months > 0) {
				$first = $from->modify('first day of this month')->modify('+' . $months . ' months');
				$day   = min((int)$from->format('j'), (int)$first->format('t'));

				return $first->setDate((int)$first->format('Y'), (int)$first->format('n'), $day)->format(DATE_ATOM);
			}
		}

		return null;
	}//end expiresAt()

	/**
	 * The issuing organisation's name: the tenant's school.
	 *
	 * @param string $tenantId The tenant.
	 *
	 * @return string
	 */
	private function issuerName(string $tenantId): string {
		if ($tenantId === '') {
			return '';
		}

		foreach ($this->many(schema: 'school', filters: ['tenant_id' => $tenantId]) as $school) {
			return (string)($school['name'] ?? '');
		}

		return '';
	}//end issuerName()

	/**
	 * Now, as an ISO 8601 timestamp.
	 *
	 * @return string
	 */
	private function now(): string {
		return $this->time->getDateTime()->format(DATE_ATOM);
	}//end now()
}//end class
