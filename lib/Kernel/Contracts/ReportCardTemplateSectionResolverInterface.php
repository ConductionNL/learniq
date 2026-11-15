<?php

/**
 * Contract of ReportCardTemplateSectionResolver
 *
 * The public surface of ReportCardTemplateSectionResolver that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Learniq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Kernel\Contracts;

/**
 * Contract of ReportCardTemplateSectionResolver.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ReportCardTemplateSectionResolverInterface {

	/**
	 * Whether the attendance section should be populated: both the
	 * ReportPeriod's own `attendanceIncluded` gate AND the template's
	 * section gate (or "no template", per {@see self::sectionEnabled()})
	 * must hold. Combines the two predicates here, not at the call site, so
	 * `ReportCardComposer` carries one call instead of one more decision
	 * point per call site.
	 *
	 * @param bool $attendanceIncluded The governing ReportPeriod's `attendanceIncluded` value.
	 * @param array<int,string>|null $sectionKinds The resolved template's section kinds, or null for "no template".
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/report-card/spec.md#scenario-a-templated-cohort-composes-only-the-sections-its-template-declares
	 */
	public function attendanceSectionEnabled(bool $attendanceIncluded, ?array $sectionKinds): bool;

	/**
	 * Resolve a `ReportCardTemplate`'s declared `sections[].kind` list.
	 *
	 * @param mixed $rawTemplateId The Cohort/ReportCard's assigned ReportCardTemplate UUID (any raw
	 *                             register value: string, null, or absent), coerced here so callers
	 *                             need no null/type branch of their own.
	 *
	 * @return array<int,string>|null The template's section kinds, or null when no template is
	 *                                assigned or the referenced template cannot be resolved (both
	 *                                fall back to the pre-existing fixed shape via
	 *                                {@see self::sectionEnabled()}).
	 *
	 * @spec openspec/specs/report-card/spec.md#scenario-a-templated-cohort-composes-only-the-sections-its-template-declares
	 */
	public function resolveSectionKinds(mixed $rawTemplateId): ?array;

	/**
	 * Whether a section kind should be populated: always true when no
	 * template is assigned/resolvable (the pre-existing fixed shape), and
	 * only when declared otherwise.
	 *
	 * @param string $kind Section kind being considered ('grades'/'attendance'/etc).
	 * @param array<int,string>|null $sectionKinds The resolved template's section kinds, or null for "no template".
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/report-card/spec.md#scenario-an-untemplated-cohort-composes-exactly-as-before-this-change
	 */
	public function sectionEnabled(string $kind, ?array $sectionKinds): bool;
}//end interface
