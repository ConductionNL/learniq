<?php

/**
 * Unit tests for CredentialMigrationService.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/credentials-migrate-with-validity/tasks.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Learniq\Service\CredentialMigrationService;
use OCA\Learniq\Service\ExternalTrainingLearnerMatch;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Clean rows, unknown learners and courses, duplicates, rows without an end
 * date, and nothing written in a preview.
 *
 * @covers \OCA\Learniq\Service\CredentialMigrationService
 */
final class CredentialMigrationServiceTest extends TestCase {

	private const TENANT = 'tenant-1';

	/**
	 * Credentials already stored.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $credentials = [];

	/**
	 * Credentials written.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	private bool $saveFails = false;

	public function testACleanRowIsPreviewedThenWrittenAsAnUnsignedMigratedCertificate(): void {
		$row = ['learner' => 'p-100', 'course' => 'VCA', 'legacyNumber' => 'OLD-1', 'legacyIssuer' => 'Oude LMS', 'issuedAt' => '2024-03-01', 'expiresAt' => '2027-03-01'];

		$preview = $this->service()->migrate(rows: [$row], tenantId: self::TENANT, migratedBy: 'hr-1', commit: false);
		$this->assertSame('ready', $preview['rows'][0]['status']);
		$this->assertNull($preview['runId']);
		$this->assertSame([], $this->saved);

		$result = $this->service()->migrate(rows: [$row], tenantId: self::TENANT, migratedBy: 'hr-1', commit: true, now: new DateTimeImmutable('2026-11-13T10:00:00+00:00'));

		$this->assertSame('created', $result['rows'][0]['status']);
		$this->assertSame('cred-1', $result['rows'][0]['credentialId']);
		$this->assertSame(1, $result['summary']['created']);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string)$result['runId']);
		$this->assertSame(
			[
				'learnerId' => 'learner-uuid',
				'courseId' => 'course-vca',
				'regulationSlug' => 'vca',
				'kind' => 'certificate',
				'issuedAt' => '2024-03-01T00:00:00+00:00',
				'expiresAt' => '2027-03-01T00:00:00+00:00',
				'source' => 'migrated',
				'legacyNumber' => 'OLD-1',
				'legacyIssuer' => 'Oude LMS',
				'tenant_id' => self::TENANT,
				'learnerUserId' => 'jan',
				'migratedAt' => '2026-11-13T10:00:00+00:00',
				'migratedBy' => 'hr-1',
				'migrationRunId' => $result['runId'],
			],
			$this->saved[0]
		);
		$this->assertArrayNotHasKey('signature', $this->saved[0]);
	}

	public function testAnUnknownLearnerOrCourseIsUnmatchedAndNotWritten(): void {
		$result = $this->service()->migrate(
			rows: [
				['learner' => 'nobody', 'course' => 'VCA', 'legacyNumber' => '1', 'issuedAt' => '2024-01-01'],
				['learner' => 'p-100', 'course' => 'NOPE', 'legacyNumber' => '2', 'issuedAt' => '2024-01-01'],
				['learner' => 'p-100', 'regulation' => 'unknown-reg', 'legacyNumber' => '3', 'issuedAt' => '2024-01-01'],
			],
			tenantId: self::TENANT,
			migratedBy: 'hr-1',
			commit: true
		);

		$this->assertSame(['unmatched', 'unmatched', 'unmatched'], array_column($result['rows'], 'status'));
		$this->assertSame('No learner in your organisation has this reference.', $result['rows'][0]['reason']);
		$this->assertSame(3, $result['summary']['skipped']);
		$this->assertNull($result['runId']);
		$this->assertSame([], $this->saved);
	}

	public function testARegulationWithoutACourseIsAccepted(): void {
		$result = $this->service()->migrate(rows: [['learner' => 'p-100', 'regulation' => 'nis2', 'legacyNumber' => '9', 'issuedAt' => '2025-05-05']], tenantId: self::TENANT, migratedBy: 'hr-1', commit: true);

		$this->assertSame('created', $result['rows'][0]['status']);
		$this->assertNull($this->saved[0]['courseId']);
		$this->assertSame('nis2', $this->saved[0]['regulationSlug']);
	}

	public function testAnEarlierMigrationAndARepeatedRowAreDuplicates(): void {
		$this->credentials[] = ['id' => 'old', 'learnerId' => 'learner-uuid', 'courseId' => 'course-vca', 'legacyNumber' => 'OLD-1', 'source' => 'migrated', 'tenant_id' => self::TENANT];
		$row = ['learner' => 'p-100', 'course' => 'VCA', 'legacyNumber' => 'OLD-2', 'issuedAt' => '2024-03-01'];

		$rows = [['legacyNumber' => 'OLD-1'] + $row, $row, $row];

		$preview = $this->service()->migrate(rows: $rows, tenantId: self::TENANT, migratedBy: 'hr-1', commit: false);
		$this->assertSame(['duplicate', 'ready', 'duplicate'], array_column($preview['rows'], 'status'));
		$this->assertSame('This certificate was migrated before.', $preview['rows'][0]['reason']);
		$this->assertSame('The same learner, course and old number are on row 2.', $preview['rows'][2]['reason']);

		$result = $this->service()->migrate(rows: $rows, tenantId: self::TENANT, migratedBy: 'hr-1', commit: true);
		$this->assertSame(['duplicate', 'created', 'duplicate'], array_column($result['rows'], 'status'));
		$this->assertCount(1, $this->saved);

		$again = $this->service()->migrate(rows: [$row], tenantId: self::TENANT, migratedBy: 'hr-1', commit: true);
		$this->assertSame('duplicate', $again['rows'][0]['status']);
		$this->assertCount(1, $this->saved);
	}

	public function testARowWithoutAnEndDateIsImportedAndCounted(): void {
		$result = $this->service()->migrate(rows: [['learner' => 'p-100', 'course' => 'VCA', 'legacyNumber' => '5', 'issuedAt' => '2024-03-01']], tenantId: self::TENANT, migratedBy: 'hr-1', commit: false);

		$this->assertSame('ready', $result['rows'][0]['status']);
		$this->assertNull($result['rows'][0]['expiresAt']);
		$this->assertSame(1, $result['summary']['withoutEndDate']);
	}

	public function testAnUnreadableRowIsInvalid(): void {
		$base = ['learner' => 'p-100', 'course' => 'VCA', 'legacyNumber' => '5', 'issuedAt' => '2024-03-01'];
		$cases = [
			'The learner is missing.' => ['learner' => ''] + $base,
			'Give a course code or a regulation.' => ['course' => ''] + $base,
			'The old certificate number is missing.' => ['legacyNumber' => ' '] + $base,
			'The kind is not one of diploma, certificate, badge or microcredential.' => ['kind' => 'trophy'] + $base,
			'The issue date is not a date (YYYY-MM-DD).' => ['issuedAt' => '01-03-2024'] + $base,
			'The end date is not a date (YYYY-MM-DD).' => ['expiresAt' => '2027-02-30'] + $base,
			'The end date is before the issue date.' => ['expiresAt' => '2020-01-01'] + $base,
		];

		$result = $this->service()->migrate(rows: array_values($cases), tenantId: self::TENANT, migratedBy: 'hr-1', commit: true);

		$this->assertSame(array_fill(0, 7, 'invalid'), array_column($result['rows'], 'status'));
		$this->assertSame(array_keys($cases), array_column($result['rows'], 'reason'));
		$this->assertSame([], $this->saved);
	}

	public function testAFailedSaveIsReportedPerRow(): void {
		$this->saveFails = true;
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error');

		$result = $this->service(logger: $logger)->migrate(rows: [['learner' => 'p-100', 'course' => 'VCA', 'legacyNumber' => '5', 'issuedAt' => '2024-03-01']], tenantId: self::TENANT, migratedBy: 'hr-1', commit: true);

		$this->assertSame('failed', $result['rows'][0]['status']);
		$this->assertSame(1, $result['summary']['failed']);
		$this->assertNull($result['runId']);
	}

	/**
	 * The service over a fake store and learner match.
	 *
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return CredentialMigrationService
	 */
	private function service(?LoggerInterface $logger = null): CredentialMigrationService {
		$match = $this->createMock(ExternalTrainingLearnerMatch::class);
		$match->method('match')->willReturnCallback(
			static fn (string $reference, string $tenantId): array => ($reference === 'p-100' && $tenantId === self::TENANT)
				? ['id' => 'learner-uuid', 'userId' => 'jan', 'reason' => null]
				: ['id' => null, 'userId' => null, 'reason' => 'No learner in your organisation has this reference.']
		);

		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config): array {
				$filters = $config['filters'];
				$rows = match ($filters['schema']) {
					'course' => [['id' => 'course-vca', 'code' => 'VCA', 'regulationSlug' => 'vca', 'tenant_id' => self::TENANT]],
					'regulation' => [['id' => 'reg-nis2', 'slug' => 'nis2', 'tenant_id' => self::TENANT]],
					'credential' => array_merge($this->credentials, $this->saved),
					default => [],
				};
				unset($filters['schema'], $filters['register']);
				return array_values(
					array_filter(
						$rows,
						static function (array $row) use ($filters): bool {
							foreach ($filters as $key => $value) {
								if (($row[$key] ?? null) !== $value) {
									return false;
								}
							}
							return true;
						}
					)
				);
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, $extend = null, $register = null, $schema = null): ObjectEntity {
				if ($this->saveFails === true) {
					throw new RuntimeException('down');
				}
				$this->assertSame(['learniq', 'credential'], [$register, $schema]);
				$this->saved[] = $object;
				$entity = new ObjectEntity();
				$entity->setUuid('cred-' . count($this->saved));
				$entity->setObject($object);
				return $entity;
			}
		);

		return new CredentialMigrationService($objects, $match, ($logger ?? $this->createMock(LoggerInterface::class)));
	}
}
