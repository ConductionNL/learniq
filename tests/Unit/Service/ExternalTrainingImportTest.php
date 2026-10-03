<?php

/**
 * Tests for ExternalTrainingImport: preview, per-row reasons, tenant scoping,
 * duplicates, and records that pass the shipped ExternalTrainingRecord schema.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
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
 * @spec openspec/changes/compliance-external-training-spreadsheet-upload/specs/external-training-upload/spec.md#requirement-spreadsheet-import-of-external-training
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Learniq\Service\ExternalTrainingImport;
use OCA\Learniq\Service\ExternalTrainingLearnerMatch;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\Learniq\Tests\Support\RegisterSchemaPayloads;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Learniq\Service\ExternalTrainingImport
 * @covers \OCA\Learniq\Service\ExternalTrainingLearnerMatch
 * @covers \OCA\Learniq\Service\ExternalTrainingRowCheck
 */
class ExternalTrainingImportTest extends TestCase {
	use RegisterSchemaPayloads;

	private const TENANT = 'tenant-a';
	private const OTHER = 'tenant-b';

	/**
	 * Every save the import made.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $saved = [];

	/**
	 * The fixed evaluation instant.
	 *
	 * @return DateTimeImmutable
	 */
	private static function now(): DateTimeImmutable {
		return new DateTimeImmutable('2026-10-01T12:00:00+00:00');
	}//end now()

	/**
	 * A learner profile uuid for a number.
	 *
	 * @param int $n The number.
	 *
	 * @return string
	 */
	private static function uuid(int $n): string {
		return sprintf('00000000-0000-4000-8000-%012d', $n);
	}//end uuid()

	/**
	 * The import over a store of profiles and records.
	 *
	 * @param array<int,array<string,mixed>> $profiles LearnerProfile payloads (any tenant).
	 * @param array<int,array<string,mixed>> $records Existing ExternalTrainingRecord payloads.
	 * @param bool $saveFails Whether saveObject throws.
	 *
	 * @return ExternalTrainingImport
	 */
	private function import(array $profiles, array $records = [], bool $saveFails = false): ExternalTrainingImport {
		$objectService = $this->createMock(ObjectService::class);
		// The store ignores every filter on purpose: the import must check tenant and match itself.
		$objectService->method('findAll')->willReturnCallback(
			static function (array $config) use ($profiles, $records): array {
				$schema = $config['filters']['schema'] ?? '';
				if ($schema === 'learner-profile') {
					return OrEntityFactory::makeMany($profiles, 'learner-profile');
				}

				return $schema === 'external-training-record' ? OrEntityFactory::makeMany($records, 'external-training-record') : [];
			}
		);
		$objectService->method('find')->willReturnCallback(
			static function (string $id) use ($profiles) {
				foreach ($profiles as $profile) {
					if ($profile['id'] === $id) {
						return OrEntityFactory::make($profile, 'learner-profile');
					}
				}

				throw new DoesNotExistException('no such profile');
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend = [], $register = null, $schema = null) use ($saveFails) {
				if ($saveFails === true) {
					throw new RuntimeException('store down');
				}

				$this->saved[] = ['schema' => (string)$schema, 'object' => $object];
				return OrEntityFactory::make($object, (string)$schema);
			}
		);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('getByEmail')->willReturnCallback(
			function (string $email) use ($profiles): array {
				$users = [];
				foreach ($profiles as $profile) {
					if (($profile['email'] ?? null) === $email) {
						$user = $this->createMock(IUser::class);
						$user->method('getUID')->willReturn($profile['ncUserId']);
						$users[] = $user;
					}
				}

				return $users;
			}
		);

		return new ExternalTrainingImport($objectService, new ExternalTrainingLearnerMatch($objectService, $userManager), new NullLogger());
	}//end import()

	/**
	 * A profile of the caller's tenant (the `email` key only feeds the user manager double).
	 *
	 * @param int $n The number.
	 * @param string $tenant The tenant.
	 *
	 * @return array<string,mixed>
	 */
	private static function profile(int $n, string $tenant = self::TENANT): array {
		return [
			'id' => self::uuid($n),
			'ncUserId' => 'user' . $n,
			'email' => 'learner' . $n . '@school.example',
			'personalNumber' => 'P' . $n,
			'tenant_id' => $tenant,
		];
	}//end profile()

	/**
	 * A row of the provider's list.
	 *
	 * @param string $learner The learner column.
	 * @param array<string,string> $extra Overrides.
	 *
	 * @return array<string,string>
	 */
	private static function row(string $learner, array $extra = []): array {
		return array_merge(
			[
				'learner' => $learner,
				'title' => 'BHV herhaling',
				'provider' => 'Oranje Kruis',
				'completedAt' => '2026-09-15',
				'validUntil' => '2027-09-15',
				'regulationSlug' => 'bhv',
			],
			$extra
		);
	}//end row()

	/**
	 * Forty rows, three unknown emails: the preview shows 37 ready and 3 unmatched; confirm creates 37.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/compliance-external-training-spreadsheet-upload/specs/external-training-upload/spec.md#scenario-an-officer-uploads-a-providers-attendance-list
	 */
	public function testAProvidersAttendanceListPreviewsThenCreatesTheReadyRows(): void {
		$profiles = [];
		$rows = [];
		for ($n = 1; $n <= 40; $n++) {
			$profiles[] = self::profile($n);
			$rows[] = self::row(learner: $n <= 37 ? 'learner' . $n . '@school.example' : 'stranger' . $n . '@elsewhere.example');
		}

		$preview = $this->import(profiles: $profiles)->import(rows: $rows, tenantId: self::TENANT, submittedBy: 'officer', dryRun: true, now: self::now());
		self::assertSame(['ready' => 37, 'created' => 0, 'skipped' => 0, 'failed' => 3, 'total' => 40], $preview['summary']);
		self::assertNull($preview['batchId']);
		self::assertSame([], $this->saved);
		self::assertSame('unmatched', $preview['rows'][39]['status']);
		self::assertSame('No learner in your organisation has this email address.', $preview['rows'][39]['reason']);
		self::assertSame(self::uuid(1), $preview['rows'][0]['learnerId']);
		self::assertSame(40, $preview['rows'][39]['row']);

		$result = $this->import(profiles: $profiles)->import(rows: $rows, tenantId: self::TENANT, submittedBy: 'officer', dryRun: false, now: self::now());
		self::assertSame(['ready' => 0, 'created' => 37, 'skipped' => 0, 'failed' => 3, 'total' => 40], $result['summary']);
		self::assertCount(37, $this->saved);
		self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string)$result['batchId']);

		foreach ($this->saved as $save) {
			self::assertSame('external-training-record', $save['schema']);
			self::assertSame($result['batchId'], $save['object']['batchId']);
			self::assertSame(self::TENANT, $save['object']['tenant_id']);
			self::assertSame('officer', $save['object']['submittedBy']);
			self::assertArrayNotHasKey('lifecycle', $save['object']);
			self::assertNull(self::schemaError('external-training-record', $save['object']), json_encode($save['object']));
		}

		self::assertSame('2026-09-15T00:00:00+00:00', $this->saved[0]['object']['completedAt']);
		self::assertSame('user1', $this->saved[0]['object']['learnerUserId']);
	}//end testAProvidersAttendanceListPreviewsThenCreatesTheReadyRows()

	/**
	 * A future completion date is refused on that row only.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/compliance-external-training-spreadsheet-upload/specs/external-training-upload/spec.md#scenario-an-invalid-date-is-refused-per-row
	 */
	public function testAFutureDateIsRefusedPerRow(): void {
		$report = $this->import(profiles: [self::profile(1), self::profile(2)])->import(
			rows: [self::row(learner: 'P1', extra: ['completedAt' => '2026-12-01']), self::row(learner: 'P2')],
			tenantId: self::TENANT,
			submittedBy: 'officer',
			dryRun: true,
			now: self::now()
		);

		self::assertSame('invalid', $report['rows'][0]['status']);
		self::assertSame('The completed on date is in the future.', $report['rows'][0]['reason']);
		self::assertSame('ready', $report['rows'][1]['status']);
	}//end testAFutureDateIsRefusedPerRow()

	/**
	 * Each invalid field names its reason; Dutch day-month-year dates are read.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/compliance-external-training-spreadsheet-upload/specs/external-training-upload/spec.md#scenario-an-invalid-date-is-refused-per-row
	 */
	public function testEachInvalidFieldNamesItsReason(): void {
		$cases = [
			['', [], 'The learner is missing.'],
			['P1', ['title' => ' '], 'The title is missing.'],
			['P1', ['provider' => ''], 'The provider is missing.'],
			['P1', ['kind' => 'webinar'], 'The kind must be one of: {kinds}.'],
			['P1', ['completedAt' => '31-02-2026'], 'The completed on date is missing or not a date.'],
			['P1', ['validUntil' => 'soon'], 'The valid until date is not a date.'],
			['P1', ['validUntil' => '2026-09-01'], 'The valid until date must be after the completed on date.'],
		];
		$rows = array_map(static fn (array $case): array => self::row(learner: $case[0], extra: $case[1]), $cases);
		$rows[] = self::row(learner: 'P1', extra: ['completedAt' => '15-09-2026', 'validUntil' => '15/09/2027', 'kind' => 'conference']);

		$report = $this->import(profiles: [self::profile(1)])->import(rows: $rows, tenantId: self::TENANT, submittedBy: 'officer', dryRun: false, now: self::now());

		foreach ($cases as $index => $case) {
			self::assertSame(['invalid', $case[2]], [$report['rows'][$index]['status'], $report['rows'][$index]['reason']], (string)$index);
		}

		self::assertSame(['kinds' => 'classroom, external-elearning, conference, on-the-job, other'], $report['rows'][3]['reasonParams']);
		self::assertSame([], $report['rows'][4]['reasonParams']);
		self::assertSame('created', $report['rows'][7]['status']);
		self::assertSame('2027-09-15T00:00:00+00:00', $this->saved[0]['object']['validUntil']);
		self::assertSame('conference', $this->saved[0]['object']['kind']);
		self::assertNull(self::schemaError('external-training-record', $this->saved[0]['object']));
	}//end testEachInvalidFieldNamesItsReason()

	/**
	 * A learner of another tenant reads like an unknown one, by uuid, personal number or email.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/compliance-external-training-spreadsheet-upload/specs/external-training-upload/spec.md#requirement-spreadsheet-import-of-external-training
	 */
	public function testALearnerOfAnotherTenantIsNotMatched(): void {
		$profiles = [self::profile(1, self::OTHER), self::profile(2)];
		$report = $this->import(profiles: $profiles)->import(
			rows: [
				self::row(learner: self::uuid(1)),
				self::row(learner: 'P1'),
				self::row(learner: 'learner1@school.example'),
				self::row(learner: self::uuid(2)),
				self::row(learner: 'P2', extra: ['title' => 'EHBO']),
			],
			tenantId: self::TENANT,
			submittedBy: 'officer',
			dryRun: true,
			now: self::now()
		);

		self::assertSame(['unmatched', 'unmatched', 'unmatched', 'ready', 'ready'], array_column($report['rows'], 'status'));
		self::assertSame('No learner in your organisation has this reference.', $report['rows'][0]['reason']);
		self::assertSame(self::uuid(2), $report['rows'][4]['learnerId']);
	}//end testALearnerOfAnotherTenantIsNotMatched()

	/**
	 * Two accounts with one email in the tenant is ambiguous, not a guess.
	 *
	 * @return void
	 */
	public function testAnAmbiguousEmailIsNotGuessed(): void {
		$twin = array_merge(self::profile(2), ['email' => 'learner1@school.example']);
		$report = $this->import(profiles: [self::profile(1), $twin])->import(
			rows: [self::row(learner: 'learner1@school.example')],
			tenantId: self::TENANT,
			submittedBy: 'officer',
			dryRun: true,
			now: self::now()
		);

		self::assertSame('More than one learner matches; use the learner reference instead.', $report['rows'][0]['reason']);
	}//end testAnAmbiguousEmailIsNotGuessed()

	/**
	 * Re-uploading the corrected failed rows creates them and duplicates nothing earlier.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/compliance-external-training-spreadsheet-upload/specs/external-training-upload/spec.md#scenario-the-officer-fixes-the-failed-rows
	 */
	public function testTheFixedFailedRowsAreCreatedAndNothingIsDuplicated(): void {
		$earlier = [
			['id' => 'rec-1', 'learnerId' => self::uuid(1), 'title' => 'BHV Herhaling', 'completedAt' => '2026-09-15T00:00:00+00:00', 'tenant_id' => self::TENANT],
		];
		$report = $this->import(profiles: [self::profile(1), self::profile(2), self::profile(3)], records: $earlier)->import(
			rows: [
				self::row(learner: 'P1'),
				self::row(learner: 'P2'),
				self::row(learner: 'P3'),
				self::row(learner: 'learner3@school.example'),
			],
			tenantId: self::TENANT,
			submittedBy: 'officer',
			dryRun: false,
			now: self::now()
		);

		self::assertSame(['skipped', 'created', 'created', 'duplicate'], array_column($report['rows'], 'status'));
		self::assertSame('The same learner, training and date are on row {row}.', $report['rows'][3]['reason']);
		self::assertSame(['row' => 3], $report['rows'][3]['reasonParams']);
		self::assertSame(['ready' => 0, 'created' => 2, 'skipped' => 2, 'failed' => 0, 'total' => 4], $report['summary']);
		self::assertCount(2, $this->saved);
	}//end testTheFixedFailedRowsAreCreatedAndNothingIsDuplicated()

	/**
	 * A save that fails marks that row failed; with nothing created there is no batch.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/compliance-external-training-spreadsheet-upload/specs/external-training-upload/spec.md#requirement-import-result-report
	 */
	public function testAFailedSaveIsReportedOnItsRow(): void {
		$report = $this->import(profiles: [self::profile(1)], saveFails: true)->import(
			rows: [self::row(learner: 'P1'), 'not a row'],
			tenantId: self::TENANT,
			submittedBy: 'officer',
			dryRun: false,
			now: self::now()
		);

		self::assertSame(['invalid', 'invalid'], array_column($report['rows'], 'status'));
		self::assertSame('The record could not be saved.', $report['rows'][0]['reason']);
		self::assertSame('The learner is missing.', $report['rows'][1]['reason']);
		self::assertNull($report['batchId']);
		self::assertSame(2, $report['summary']['failed']);
	}//end testAFailedSaveIsReportedOnItsRow()
}//end class
