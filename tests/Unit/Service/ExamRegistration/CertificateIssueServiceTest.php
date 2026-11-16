<?php

/**
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit
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

namespace OCA\Learniq\Tests\Unit\Service\ExamRegistration;

use DateTime;
use OCA\Learniq\Kernel\Contracts\CredentialSigningServiceInterface;
use OCA\Learniq\Kernel\Contracts\EuropassIssuerInterface;
use OCA\Learniq\Service\CourseDay\CourseDayException;
use OCA\Learniq\Service\ExamRegistration\CertificateIssueService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * "Certificaten uitgeven" for Thursday 8 October's F-gassen day.
 *
 * @covers \OCA\Learniq\Service\ExamRegistration\CertificateIssueService
 * @uses   \OCA\Learniq\Service\CourseDay\CourseDayException
 * @uses   \OCA\Learniq\Support\SerialisedRow
 */
class CertificateIssueServiceTest extends TestCase {

	/**
	 * Rows per schema.
	 *
	 * @var array<string, list<array<string, mixed>>>
	 */
	private array $rows = [];

	/**
	 * Every save as [schema, object, uuid].
	 *
	 * @var list<array{0: string, 1: array<string, mixed>, 2: string|null}>
	 */
	private array $saves = [];

	/**
	 * Enrolments whose certificate cannot be signed.
	 *
	 * @var list<string>
	 */
	private array $unsignable = [];

	protected function setUp(): void {
		$lines      = [];
		$enrolments = [];
		for ($i = 1; $i <= 10; $i++) {
			$enrolments[] = ['id' => 'e-' . $i, 'cohortId' => 'c-8', 'learnerId' => 'user' . $i, 'learnerRef' => 'p-' . $i, 'learnerName' => 'Deelnemer ' . sprintf('%02d', $i), 'courseId' => 'fgas', 'lifecycle' => 'active', 'tenant_id' => 't-1'];
			$lines[]      = ['enrolmentId' => 'e-' . $i, 'learnerName' => 'Deelnemer ' . sprintf('%02d', $i), 'result' => 'pass'];
		}

		$enrolments[] = ['id' => 'e-sanne', 'cohortId' => 'c-8', 'learnerRef' => 'p-sanne', 'learnerName' => 'Sanne de Wit', 'lifecycle' => 'active', 'tenant_id' => 't-1'];
		$lines[]      = ['enrolmentId' => 'e-sanne', 'learnerName' => 'Sanne de Wit', 'result' => null];
		$enrolments[] = ['id' => 'e-gone', 'cohortId' => 'c-8', 'learnerRef' => 'p-gone', 'learnerName' => 'Weg', 'lifecycle' => 'withdrawn', 'tenant_id' => 't-1'];

		$this->rows = [
			'cohort' => [
				['id' => 'c-8', 'name' => 'F-gassen 8 okt', 'courseId' => 'fgas', 'dayStatus' => 'rounding-off', 'tenant_id' => 't-1'],
				['id' => 'c-22', 'name' => 'Hybride 22 okt', 'courseId' => 'hybride', 'dayStatus' => 'goes-ahead', 'tenant_id' => 't-1'],
				['id' => 'c-old', 'name' => 'Old', 'courseId' => 'hybride', 'dayStatus' => 'rounding-off', 'tenant_id' => 't-1'],
			],
			'course' => [
				['id' => 'fgas', 'title' => 'F-gassen', 'defaultExpiresAfterDays' => 1825],
				['id' => 'hybride', 'title' => 'Hybride warmtepomp', 'regulationSlug' => 'brl-100'],
			],
			'regulation' => [['id' => 'r-1', 'slug' => 'brl-100', 'tenant_id' => 't-1', 'lifecycle' => 'published', 'renewalCycleMonths' => 36]],
			'school' => [['id' => 's-1', 'name' => 'Warmtepompacademie', 'tenant_id' => 't-1']],
			'enrolment' => $enrolments,
			'exam-registration' => [['id' => 'l-8', 'cohortId' => 'c-8', 'lifecycle' => 'sent', 'lines' => $lines]],
			'credential' => [],
		];
	}//end setUp()

	private function service(): CertificateIssueService {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config): array {
				$filters = $config['filters'];
				$schema  = $filters['schema'];
				unset($filters['register'], $filters['schema']);

				return array_values(
					array_filter(
						($this->rows[$schema] ?? []),
						static fn (array $row): bool => (isset($config['ids']) === false || in_array($row['id'], $config['ids'], true) === true)
							&& array_intersect_assoc($filters, $row) === $filters
					)
				);
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ObjectSaveOptions $options): ObjectEntity {
				$this->saves[] = [$options->target->schema, $object, $options->uuid];
				$object['id']  = $options->uuid;
				$this->rows[$options->target->schema][] = $object;
				$entity = $this->createMock(ObjectEntity::class);
				$entity->method('jsonSerialize')->willReturn($object);

				return $entity;
			}
		);
		$signing = $this->createMock(CredentialSigningServiceInterface::class);
		$signing->method('sign')->willReturnCallback(
			fn (array $credential): ?array => in_array($credential['enrolmentId'], $this->unsignable, true) === true
				? null
				: array_merge($credential, ['id' => 'cred-' . $credential['enrolmentId'], 'signature' => 'jws'])
		);
		$europass = $this->createMock(EuropassIssuerInterface::class);
		$europass->method('withEuropass')->willReturnCallback(static fn (array $credential): array => array_merge($credential, ['edciPayload' => ['ok' => true]]));
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(static fn (): DateTime => new DateTime('2026-10-12T10:00:00+02:00'));

		return new CertificateIssueService(objectService: $objects, time: $time, signing: $signing, europass: $europass, logger: $this->createMock(LoggerInterface::class));
	}//end service()

	/**
	 * The credentials saved.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function credentials(): array {
		return array_values(array_map(static fn (array $save): array => $save[1], array_filter($this->saves, static fn (array $save): bool => $save[0] === 'credential')));
	}//end credentials()

	public function testRobIssuesTenCertificatesAndTheParticipantWithoutAResultIsNamed(): void {
		$outcome = $this->service()->issue(cohortId: 'c-8', userId: 'rob');

		self::assertCount(10, $outcome['issued']);
		self::assertSame(0, $outcome['alreadyIssued']);
		self::assertSame([['enrolmentId' => 'e-sanne', 'learnerName' => 'Sanne de Wit', 'reason' => 'no-result', 'sentence' => 'Sanne de Wit: geen uitslag.']], $outcome['skipped']);
		self::assertSame('10 certificaten uitgegeven. Niet uitgegeven: Sanne de Wit (geen uitslag).', $outcome['summary']);

		$credentials = $this->credentials();
		self::assertCount(10, $credentials);
		$first = $credentials[0];
		self::assertSame('p-1', $first['learnerId']);
		self::assertSame('user1', $first['learnerUserId']);
		self::assertSame('e-1', $first['enrolmentId']);
		self::assertSame('certificate', $first['kind']);
		self::assertSame('manual', $first['source']);
		self::assertSame('rob', $first['issuedByUserId'], 'who issued');
		self::assertSame('2026-10-12T10:00:00+02:00', $first['issuedAt'], 'and when');
		self::assertSame('2031-10-11T10:00:00+02:00', $first['expiresAt'], 'the course is valid 1825 days');
		self::assertSame('Warmtepompacademie', $first['issuedBy']);
		self::assertSame(['ok' => true], $first['edciPayload']);
		self::assertArrayNotHasKey('id', $first, 'saved under the signed uuid, not with an id field');
		self::assertSame('cred-e-1', $this->saves[0][2]);

		// The certificates are written onto their lines of the day's list.
		$list = array_values(array_filter($this->saves, static fn (array $save): bool => $save[0] === 'exam-registration'));
		self::assertCount(1, $list);
		self::assertSame('cred-e-1', $list[0][1]['lines'][0]['credentialId']);
		self::assertNull($list[0][1]['lines'][10]['credentialId'] ?? null);
	}//end testRobIssuesTenCertificatesAndTheParticipantWithoutAResultIsNamed()

	public function testASecondIssueIssuesNothingTwice(): void {
		$this->service()->issue(cohortId: 'c-8', userId: 'rob');
		$this->saves = [];

		$outcome = $this->service()->issue(cohortId: 'c-8', userId: 'rob');

		self::assertSame([], $outcome['issued']);
		self::assertSame(10, $outcome['alreadyIssued']);
		self::assertSame([], $this->credentials());
		self::assertSame('0 certificaten uitgegeven. 10 had al een certificaat. Niet uitgegeven: Sanne de Wit (geen uitslag).', $outcome['summary']);
	}//end testASecondIssueIssuesNothingTwice()

	public function testAFailedResultAndAnUnsignableCertificateAreSkipped(): void {
		$this->rows['exam-registration'][0]['lines'][1]['result'] = 'fail';
		$this->unsignable = ['e-3'];

		$outcome = $this->service()->issue(cohortId: 'c-8', userId: 'rob');

		self::assertCount(8, $outcome['issued']);
		self::assertSame(['e-2' => 'failed', 'e-3' => 'unsigned', 'e-sanne' => 'no-result'], array_column($outcome['skipped'], 'reason', 'enrolmentId'));
		self::assertCount(8, $this->credentials());
	}//end testAFailedResultAndAnUnsignableCertificateAreSkipped()

	public function testADayWithoutResultsOnAListReadsTheCompletedEnrolments(): void {
		$this->rows['exam-registration'] = [];
		$this->rows['enrolment'] = [
			['id' => 'h-1', 'cohortId' => 'c-old', 'learnerId' => 'u1', 'learnerRef' => 'p-1', 'learnerName' => 'Anna', 'courseId' => 'hybride', 'lifecycle' => 'completed', 'tenant_id' => 't-1'],
			['id' => 'h-2', 'cohortId' => 'c-old', 'learnerRef' => 'p-2', 'learnerName' => 'Bas', 'courseId' => 'hybride', 'lifecycle' => 'failed', 'tenant_id' => 't-1'],
			['id' => 'h-3', 'cohortId' => 'c-old', 'learnerRef' => 'p-3', 'learnerName' => 'Cor', 'courseId' => 'hybride', 'lifecycle' => 'active', 'tenant_id' => 't-1'],
		];

		$outcome = $this->service()->issue(cohortId: 'c-old', userId: 'rob');

		self::assertSame(['h-1'], array_column($outcome['issued'], 'enrolmentId'));
		self::assertSame(['h-2' => 'failed', 'h-3' => 'no-result'], array_column($outcome['skipped'], 'reason', 'enrolmentId'));
		self::assertSame('2029-10-12T10:00:00+02:00', $this->credentials()[0]['expiresAt'], 'the published rule renews every 36 months');
		self::assertSame('1 certificaat uitgegeven. Niet uitgegeven: Bas (niet geslaagd), Cor (geen uitslag).', $outcome['summary']);
	}//end testADayWithoutResultsOnAListReadsTheCompletedEnrolments()

	public function testOnlyACourseDayInRoundingOffIssues(): void {
		try {
			$this->service()->issue(cohortId: 'c-22', userId: 'rob');
			self::fail('a day still to come does not issue');
		} catch (CourseDayException $exception) {
			self::assertSame(409, $exception->getStatus());
		}

		self::assertSame([], $this->saves);
		$this->expectException(CourseDayException::class);
		$this->expectExceptionMessage('This is not a course day.');
		$this->service()->issue(cohortId: 'nope', userId: 'rob');
	}//end testOnlyACourseDayInRoundingOffIssues()
}//end class
