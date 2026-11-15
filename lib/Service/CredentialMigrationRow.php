<?php

/**
 * Learniq Credential Migration Row
 *
 * Reads one migration row into its fields, says why it cannot be used, and
 * builds the certificate record it writes.
 *
 * @category Service
 * @package  OCA\Learniq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/credentials-migrate-with-validity/tasks.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * The fields, reasons and record of one migrated certificate row.
 *
 * @spec openspec/changes/credentials-migrate-with-validity/tasks.md
 */
final class CredentialMigrationRow {

	/**
	 * The credential kinds a migrated row may carry.
	 */
	private const KINDS = ['diploma', 'certificate', 'badge', 'microcredential'];

	/**
	 * The row's fields, or why it cannot be read.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/credentials-migrate-with-validity/tasks.md
	 */
	public static function fields(array $row): array {
		$text = static fn (string $key): string => self::text(row: $row, key: $key);
		$fields = [
			'learner' => $text('learner'),
			'course' => $text('course'),
			'regulation' => $text('regulation'),
			'legacyNumber' => $text('legacyNumber'),
			'legacyIssuer' => $text('legacyIssuer'),
			'kind' => $text('kind'),
			'issuedAt' => self::date(value: $text('issuedAt')),
			'expiresAt' => null,
			'reason' => null,
		];

		if ($fields['kind'] === '') {
			$fields['kind'] = 'certificate';
		}

		$expires = $text('expiresAt');
		if ($expires !== '') {
			$fields['expiresAt'] = self::date(value: $expires);
		}

		$fields['reason'] = (self::identityReason(fields: $fields) ?? self::dateReason(fields: $fields, expires: $expires));

		return $fields;
	}//end fields()

	/**
	 * A row's value as trimmed text; empty when it is missing or not a scalar.
	 *
	 * @param array<string, mixed> $row The row.
	 * @param string               $key The column.
	 *
	 * @return string
	 */
	private static function text(array $row, string $key): string {
		$value = ($row[$key] ?? null);
		if (is_scalar($value) === false) {
			return '';
		}

		return trim((string)$value);
	}//end text()

	/**
	 * Why the row's learner, target, old number or kind cannot be used, or null.
	 *
	 * @param array<string, mixed> $fields The row's fields.
	 *
	 * @return string|null
	 */
	private static function identityReason(array $fields): ?string {
		return self::firstFailed(
			checks: [
				'The learner is missing.' => $fields['learner'] === '',
				'Give a course code or a regulation.' => ($fields['course'] === '' && $fields['regulation'] === ''),
				'The old certificate number is missing.' => $fields['legacyNumber'] === '',
				'The kind is not one of diploma, certificate, badge or microcredential.' => in_array($fields['kind'], self::KINDS, true) === false,
			]
		);
	}//end identityReason()

	/**
	 * Why the row's issue or end date cannot be used, or null.
	 *
	 * @param array<string, mixed> $fields  The row's fields.
	 * @param string               $expires The end date as given.
	 *
	 * @return string|null
	 */
	private static function dateReason(array $fields, string $expires): ?string {
		return self::firstFailed(
			checks: [
				'The issue date is not a date (YYYY-MM-DD).' => $fields['issuedAt'] === null,
				'The end date is not a date (YYYY-MM-DD).' => ($expires !== '' && $fields['expiresAt'] === null),
				'The end date is before the issue date.' => ($fields['expiresAt'] !== null && $fields['expiresAt'] < $fields['issuedAt']),
			]
		);
	}//end dateReason()

	/**
	 * The message of the first failed check, in order, or null when all pass.
	 *
	 * @param array<string, bool> $checks Each message with whether its check failed.
	 *
	 * @return string|null
	 */
	private static function firstFailed(array $checks): ?string {
		foreach ($checks as $message => $failed) {
			if ($failed === true) {
				return $message;
			}
		}

		return null;
	}//end firstFailed()

	/**
	 * A `YYYY-MM-DD` date as an ISO 8601 timestamp at midnight UTC, or null.
	 *
	 * @param string $value The value.
	 *
	 * @return string|null
	 */
	private static function date(string $value): ?string {
		$date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
		if ($date === false || $date->format('Y-m-d') !== $value) {
			return null;
		}

		return $date->format(DateTimeInterface::ATOM);
	}//end date()

	/**
	 * The certificate record a checked row writes.
	 *
	 * @param array<string, mixed>                                   $fields   The row's fields.
	 * @param array<string, mixed>                                   $match    The matched learner.
	 * @param array{courseId: string|null, regulationSlug: string|null} $target   The course or regulation.
	 * @param string                                                 $tenantId The organisation.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/credentials-migrate-with-validity/tasks.md
	 */
	public static function recordFor(array $fields, array $match, array $target, string $tenantId): array {
		$record = [
			'learnerId' => $match['id'],
			'courseId' => $target['courseId'],
			'regulationSlug' => $target['regulationSlug'],
			'kind' => $fields['kind'],
			'issuedAt' => $fields['issuedAt'],
			'expiresAt' => $fields['expiresAt'],
			'source' => 'migrated',
			'legacyNumber' => $fields['legacyNumber'],
			'legacyIssuer' => $fields['legacyIssuer'],
			'tenant_id' => $tenantId,
		];
		if ($match['userId'] !== null) {
			$record['learnerUserId'] = $match['userId'];
		}

		return $record;
	}//end recordFor()

	/**
	 * The key that makes a record a repeat: learner, course or regulation, and old number.
	 *
	 * @param array<string, mixed> $record The record.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/credentials-migrate-with-validity/tasks.md
	 */
	public static function keyOf(array $record): string {
		return $record['learnerId'] . '|' . ($record['courseId'] ?? 'regulation:' . $record['regulationSlug']) . '|' . $record['legacyNumber'];
	}//end keyOf()
}//end class
