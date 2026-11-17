<?php

/**
 * Learniq DocumentRow
 *
 * The one row shape every learniq documents provider hands portaliq: a group
 * label, a title, a meta line in words, whether it is new, an optional status
 * pill and where the download lives.
 *
 * @category Portal
 * @package  OCA\Learniq\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-each-school-audience-offers-its-own-documents-grouped-per-record
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

/**
 * Completes a documents entry with the fields every provider shares.
 *
 * The meta line is words, not codes: the context the provider knows (a
 * child's group, a school year) first, then the kind of file and its size,
 * so "Groep 6, PDF, 84 KB" reads the same on every school's page.
 *
 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-each-school-audience-offers-its-own-documents-grouped-per-record
 */
final class DocumentRow {

	/**
	 * The words for a file's kind, by mime type.
	 *
	 * @var array<string, string>
	 */
	private const KINDS = [
		'application/pdf' => 'PDF',
		'application/zip' => 'ZIP',
		'application/msword' => 'Word',
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'Word',
		'application/vnd.oasis.opendocument.text' => 'Document',
		'application/vnd.ms-excel' => 'Excel',
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'Excel',
		'text/plain' => 'Text',
	];

	/**
	 * Complete one entry.
	 *
	 * Keeps every field the entry already carries and adds `group`, `meta`
	 * and `status`; `isNew` is kept when present and false otherwise.
	 *
	 * @param array<string, mixed> $entry   The entry (title, mimeType, size, isNew, file, ...).
	 * @param string               $group   The group label, e.g. a child's group; empty for none.
	 * @param string               $status  The status pill, e.g. "Wacht op leerbedrijf"; empty for none.
	 * @param array<int, string>   $context Leading meta words, e.g. ["Groep 6"].
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-each-school-audience-offers-its-own-documents-grouped-per-record
	 */
	public static function complete(array $entry, string $group='', string $status='', array $context=[]): array {
		$words = array_values(array_filter(array_map('trim', $context), static fn (string $word): bool => $word !== ''));
		$kind  = self::kind(mimeType: (string)($entry['mimeType'] ?? ''));
		if ($kind !== '') {
			$words[] = $kind;
		}

		$size = self::size(bytes: (int)($entry['size'] ?? 0));
		if ($size !== '') {
			$words[] = $size;
		}

		return array_merge(
			$entry,
			[
				'title' => trim((string)($entry['title'] ?? '')),
				'group' => trim($group),
				'meta' => implode(', ', $words),
				'isNew' => (($entry['isNew'] ?? false) === true),
				'status' => trim($status),
			]
		);
	}//end complete()

	/**
	 * A file's kind in words; empty when the type says nothing a reader knows.
	 *
	 * @param string $mimeType The mime type.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-each-school-audience-offers-its-own-documents-grouped-per-record
	 */
	public static function kind(string $mimeType): string {
		$type = strtolower(trim(explode(';', $mimeType)[0]));
		if (isset(self::KINDS[$type]) === true) {
			return self::KINDS[$type];
		}

		if (str_starts_with($type, 'image/') === true) {
			return 'Image';
		}

		return '';
	}//end kind()

	/**
	 * A size in words: bytes under 1 KB, KB under 1 MB, then MB with one decimal.
	 *
	 * @param int $bytes The size.
	 *
	 * @return string Empty for an unknown or zero size.
	 *
	 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-each-school-audience-offers-its-own-documents-grouped-per-record
	 */
	public static function size(int $bytes): string {
		if ($bytes <= 0) {
			return '';
		}

		if ($bytes < 1024) {
			return $bytes.' bytes';
		}

		if ($bytes < 1048576) {
			return (string)max(1, (int)round($bytes / 1024)).' KB';
		}

		return number_format($bytes / 1048576, 1, '.', '').' MB';
	}//end size()
}//end class
