<?php

/**
 * Learniq Report Card Template Section Resolver
 *
 * The template-section-gating half of report-card composition, extracted
 * from `ReportCardComposer` the same way `AttendanceWindowAggregator`
 * already carries the attendance half: each class carries one cohesive
 * responsibility, and `ReportCardComposer` keeps only the orchestration
 * (report-card-templates change).
 *
 * Resolves a `ReportCardTemplate`'s declared `sections[].kind` list, and
 * answers whether a given section kind should be populated at compose time.
 * A `null` section-kinds result (no template assigned, or the referenced
 * template cannot be resolved) means "populate everything" — the
 * pre-existing fixed shape this register composed before templates existed.
 *
 * Consumed by:
 *   - ReportCardComposer (constructor injection)
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
 * @spec openspec/changes/report-card-templates/specs/report-card/spec.md#requirement-composition-is-a-declared-transition-triggered-php-composer-not-a-dataexchangejob-and-not-a-timedjob
 * @spec openspec/changes/report-card-templates/specs/report-card/spec.md#scenario-an-untemplated-cohort-composes-exactly-as-before-this-change
 * @spec openspec/changes/report-card-templates/specs/report-card/spec.md#scenario-a-templated-cohort-composes-only-the-sections-its-template-declares
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * Resolves a ReportCardTemplate's declared section kinds and gates
 * population by them.
 *
 * @spec openspec/changes/report-card-templates/specs/report-card/spec.md#requirement-composition-is-a-declared-transition-triggered-php-composer-not-a-dataexchangejob-and-not-a-timedjob
 */
class ReportCardTemplateSectionResolver {

	private const LEARNIQ_REGISTER = 'learniq';
	private const REPORT_CARD_TEMPLATE_SCHEMA = 'report-card-template';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR object access service.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

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
	 * @spec openspec/changes/report-card-templates/specs/report-card/spec.md#scenario-a-templated-cohort-composes-only-the-sections-its-template-declares
	 */
	public function resolveSectionKinds(mixed $rawTemplateId): ?array {
		if ($rawTemplateId === null || $rawTemplateId === '') {
			return null;
		}

		$templateId = (string)$rawTemplateId;

		$template = $this->objectService->find(id: $templateId, register: self::LEARNIQ_REGISTER, schema: self::REPORT_CARD_TEMPLATE_SCHEMA);
		if ($template === null) {
			$this->logger->warning(
				'[ReportCardTemplateSectionResolver] ReportCardTemplate {template} not found; falling back to the fixed composition shape.',
				['template' => $templateId]
			);
			return null;
		}

		$templateData = $template->jsonSerialize();
		$sections = $templateData['sections'] ?? [];
		if (is_array($sections) === false) {
			return null;
		}

		return $this->extractKinds(sections: $sections);
	}//end resolveSectionKinds()

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
	 * @spec openspec/changes/report-card-templates/specs/report-card/spec.md#scenario-an-untemplated-cohort-composes-exactly-as-before-this-change
	 */
	public function sectionEnabled(string $kind, ?array $sectionKinds): bool {
		if ($sectionKinds === null) {
			return true;
		}

		return in_array($kind, $sectionKinds, true);
	}//end sectionEnabled()

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
	 * @spec openspec/changes/report-card-templates/specs/report-card/spec.md#scenario-a-templated-cohort-composes-only-the-sections-its-template-declares
	 */
	public function attendanceSectionEnabled(bool $attendanceIncluded, ?array $sectionKinds): bool {
		if ($attendanceIncluded === false) {
			return false;
		}

		return $this->sectionEnabled(kind: 'attendance', sectionKinds: $sectionKinds);
	}//end attendanceSectionEnabled()

	/**
	 * Pull the `kind` value out of each declared section entry.
	 *
	 * @param array<int,mixed> $sections Raw `sections[]` array.
	 *
	 * @return array<int,string>
	 */
	private function extractKinds(array $sections): array {
		$kinds = [];
		foreach ($sections as $section) {
			if (is_array($section) === false) {
				continue;
			}

			$kind = (string)($section['kind'] ?? '');
			if ($kind !== '') {
				$kinds[] = $kind;
			}
		}

		return $kinds;
	}//end extractKinds()
}//end class
