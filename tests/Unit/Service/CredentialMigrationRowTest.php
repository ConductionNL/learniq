<?php

/**
 * Unit tests for CredentialMigrationRow.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/credentials-migrate-with-validity/tasks.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\CredentialMigrationRow;
use PHPUnit\Framework\TestCase;

/**
 * Reading a row's fields, the first reason it cannot be used, and the record it writes.
 *
 * @covers \OCA\Learniq\Service\CredentialMigrationRow
 */
final class CredentialMigrationRowTest extends TestCase {

	/**
	 * A complete row.
	 *
	 * @return array<string, mixed>
	 */
	private static function row(): array {
		return ['learner' => ' p-1 ', 'course' => 'VCA', 'legacyNumber' => 'OLD-1', 'issuedAt' => '2024-03-01', 'expiresAt' => '2027-03-01'];
	}

	public function testACompleteRowIsReadWithTrimmedTextIsoDatesAndTheDefaultKind(): void {
		$fields = CredentialMigrationRow::fields(row: self::row());

		$this->assertNull($fields['reason']);
		$this->assertSame('p-1', $fields['learner']);
		$this->assertSame('certificate', $fields['kind']);
		$this->assertSame('2024-03-01T00:00:00+00:00', $fields['issuedAt']);
		$this->assertSame('2027-03-01T00:00:00+00:00', $fields['expiresAt']);
	}

	public function testARowWithoutAnEndDateHasANullEndDate(): void {
		$row = self::row();
		unset($row['expiresAt']);

		$fields = CredentialMigrationRow::fields(row: $row);

		$this->assertNull($fields['reason']);
		$this->assertNull($fields['expiresAt']);
	}

	public function testTheFirstIdentityReasonWinsOverADateReason(): void {
		$fields = CredentialMigrationRow::fields(row: ['learner' => '', 'course' => 'VCA', 'legacyNumber' => 'OLD-1', 'issuedAt' => 'yesterday']);

		$this->assertSame('The learner is missing.', $fields['reason']);
	}

	public function testAnUnknownKindIsRefused(): void {
		$fields = CredentialMigrationRow::fields(row: ['kind' => 'trophy'] + self::row());

		$this->assertSame('The kind is not one of diploma, certificate, badge or microcredential.', $fields['reason']);
	}

	public function testAnImpossibleIssueDateIsRefused(): void {
		$fields = CredentialMigrationRow::fields(row: ['issuedAt' => '2024-02-30'] + self::row());

		$this->assertSame('The issue date is not a date (YYYY-MM-DD).', $fields['reason']);
	}

	public function testAnEndDateBeforeTheIssueDateIsRefused(): void {
		$fields = CredentialMigrationRow::fields(row: ['expiresAt' => '2023-01-01'] + self::row());

		$this->assertSame('The end date is before the issue date.', $fields['reason']);
	}

	public function testANonScalarValueReadsAsMissing(): void {
		$fields = CredentialMigrationRow::fields(row: ['legacyNumber' => ['OLD-1']] + self::row());

		$this->assertSame('The old certificate number is missing.', $fields['reason']);
	}

	public function testTheRecordCarriesTheMigrationSourceAndTheLearnerUserWhenKnown(): void {
		$fields = CredentialMigrationRow::fields(row: self::row());
		$target = ['courseId' => 'c-1', 'regulationSlug' => 'vca'];

		$record = CredentialMigrationRow::recordFor(fields: $fields, match: ['id' => 'l-1', 'userId' => 'u-1'], target: $target, tenantId: 't-1');
		$this->assertSame('migrated', $record['source']);
		$this->assertSame('u-1', $record['learnerUserId']);
		$this->assertSame('t-1', $record['tenant_id']);
		$this->assertSame('l-1|c-1|OLD-1', CredentialMigrationRow::keyOf(record: $record));

		$record = CredentialMigrationRow::recordFor(fields: $fields, match: ['id' => 'l-1', 'userId' => null], target: ['courseId' => null, 'regulationSlug' => 'vca'], tenantId: 't-1');
		$this->assertArrayNotHasKey('learnerUserId', $record);
		$this->assertSame('l-1|regulation:vca|OLD-1', CredentialMigrationRow::keyOf(record: $record));
	}
}
