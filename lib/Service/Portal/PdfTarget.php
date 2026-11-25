<?php

/**
 * Learniq PdfTarget
 *
 * Which document to render and where to keep it: the render template, the
 * learniq record the PDF belongs to, and the file's name on that record.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Portal
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
 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-each-school-audience-offers-its-own-documents-grouped-per-record
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Portal;

/**
 * One rendered document and the record it is kept on.
 *
 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-each-school-audience-offers-its-own-documents-grouped-per-record
 */
final class PdfTarget {

	/**
	 * Constructor.
	 *
	 * @param string $templateSlug The render template's slug.
	 * @param string $schema       The learniq schema of the record.
	 * @param string $recordId     The record's uuid.
	 * @param string $fileName     The file's name on the record; the same name replaces the earlier file.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $templateSlug,
		public readonly string $schema,
		public readonly string $recordId,
		public readonly string $fileName,
	) {
	}//end __construct()
}//end class
