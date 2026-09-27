<?php

/**
 * Unit tests for the `report-card-templates` register-JSON declarations.
 *
 * Verifies the ReportCardTemplate schema shape (sections[]'s minItems:1,
 * the seven section kinds, the scale library, testKindSectionMap[]), and the
 * two new referencing properties on Cohort/ReportCard — mirroring
 * ReportCardComposerRegisterTest's established pattern for this register.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/report-card-templates/specs/report-card/spec.md#requirement-reportcardtemplate-declares-typed-sections-with-a-scale-from-a-shared-library
 * @spec openspec/changes/report-card-templates/specs/report-card/spec.md#requirement-a-template-maps-an-imported-test-kind-to-a-report-section-per-group
 * @spec openspec/changes/report-card-templates/specs/report-card/spec.md#requirement-a-reportcardtemplate-is-assigned-per-group-per-period
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the ReportCardTemplate schema declaration and its two
 * referencing properties on Cohort/ReportCard.
 */
class ReportCardTemplateRegisterTest extends TestCase {

	/**
	 * Decoded register configuration.
	 *
	 * @var array<string, mixed>
	 */
	private array $config;

	/**
	 * Load the register configuration once per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$path = __DIR__ . '/../../../lib/Settings/learniq_register.json';
		$this->config = json_decode((string)file_get_contents($path), true);

	}//end setUp()

	/**
	 * ReportCardTemplate is a registered schema.
	 *
	 * @return void
	 */
	public function testReportCardTemplateIsRegistered(): void {
		self::assertArrayHasKey('ReportCardTemplate', $this->config['components']['schemas']);

	}//end testReportCardTemplateIsRegistered()

	/**
	 * `sections[]` declares `minItems: 1` and its items declare all seven
	 * section kinds and all seven scale-library values.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/report-card-templates/specs/report-card/spec.md#scenario-a-template-with-a-scale-from-every-library-value-validates
	 * @spec openspec/changes/report-card-templates/specs/report-card/spec.md#scenario-a-template-with-no-sections-fails-schema-validation-on-save
	 */
	public function testSectionsMinItemsOneAndFullEnums(): void {
		$template = $this->config['components']['schemas']['ReportCardTemplate'];
		$sections = $template['properties']['sections'];

		self::assertSame(1, $sections['minItems']);

		$kindEnum = $sections['items']['properties']['kind']['enum'];
		self::assertSame(
			['grades', 'lvs-results', 'attendance', 'social-emotional', 'narrative', 'pupil-voice', 'portfolio'],
			$kindEnum
		);

		$scaleEnum = $sections['items']['properties']['scale']['enum'];
		self::assertSame(
			['steps', 'dots', 'smileys', 'grades-1-10', 'letters', 'cito-level', 'text'],
			$scaleEnum
		);

		self::assertContains('sections', $template['required']);

	}//end testSectionsMinItemsOneAndFullEnums()

	/**
	 * `testKindSectionMap[]` declares the free-text testKind plus the same
	 * sectionKind enum, and persists without any LVS data source present.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/report-card-templates/specs/report-card/spec.md#scenario-a-test-kind-mapping-is-declared-without-a-corresponding-data-source-present
	 */
	public function testTestKindSectionMapShape(): void {
		$map = $this->config['components']['schemas']['ReportCardTemplate']['properties']['testKindSectionMap'];

		self::assertSame([], $map['default']);
		self::assertSame(['testKind', 'sectionKind'], $map['items']['required']);
		self::assertSame('string', $map['items']['properties']['testKind']['type']);
		self::assertContains('lvs-results', $map['items']['properties']['sectionKind']['enum']);

	}//end testTestKindSectionMapShape()

	/**
	 * ReportCardTemplate's own `slug` property (distinct from the schema's
	 * OpenRegister slug) is required — it is what
	 * ReportCardPdfDelegationService sends docudesk as `templateSlug`.
	 *
	 * @return void
	 */
	public function testTemplateSlugPropertyIsRequired(): void {
		$template = $this->config['components']['schemas']['ReportCardTemplate'];

		self::assertContains('slug', $template['required']);
		self::assertSame('string', $template['properties']['slug']['type']);
		self::assertSame('report-card-template', $template['slug']);

	}//end testTemplateSlugPropertyIsRequired()

	/**
	 * ReportCardTemplate's lifecycle mirrors CourseTemplate's draft ->
	 * active -> archived, with reactivate back to active.
	 *
	 * @return void
	 */
	public function testLifecycleMirrorsCourseTemplate(): void {
		$lifecycle = $this->config['components']['schemas']['ReportCardTemplate']['x-openregister-lifecycle'];

		self::assertSame('draft', $lifecycle['initial']);
		self::assertSame(['draft', 'active'], [$lifecycle['transitions']['activate']['from'], $lifecycle['transitions']['activate']['to']]);
		self::assertSame(['active', 'archived'], [$lifecycle['transitions']['archive']['from'], $lifecycle['transitions']['archive']['to']]);
		self::assertSame(['archived', 'active'], [$lifecycle['transitions']['reactivate']['from'], $lifecycle['transitions']['reactivate']['to']]);

	}//end testLifecycleMirrorsCourseTemplate()

	/**
	 * Cohort.reportCardTemplateId and ReportCard.templateId are both
	 * nullable `$ref: ReportCardTemplate` properties, additive to the
	 * existing schemas.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/report-card-templates/specs/report-card/spec.md#scenario-a-cohorts-assigned-template-determines-its-report-cards-sections
	 */
	public function testCohortAndReportCardReferenceProperties(): void {
		$cohort = $this->config['components']['schemas']['Cohort']['properties']['reportCardTemplateId'];
		self::assertTrue($cohort['nullable']);
		self::assertSame('ReportCardTemplate', $cohort['$ref']);

		$reportCard = $this->config['components']['schemas']['ReportCard']['properties']['templateId'];
		self::assertTrue($reportCard['nullable']);
		self::assertSame('ReportCardTemplate', $reportCard['$ref']);

	}//end testCohortAndReportCardReferenceProperties()

	/**
	 * The register's info.version was bumped for this change (at least
	 * 0.22.0), following ReportCardComposerRegisterTest's own
	 * "at least" pattern rather than pinning the exact current tip.
	 *
	 * @return void
	 */
	public function testRegisterVersionBumped(): void {
		self::assertTrue(
			version_compare($this->config['info']['version'], '0.22.0', '>='),
			'info.version MUST be at least 0.22.0 (report-card-templates\' own bump) — got ' . $this->config['info']['version']
		);

	}//end testRegisterVersionBumped()
}//end class
