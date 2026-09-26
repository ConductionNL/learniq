<?php

/**
 * Unit tests for ReportCardTemplateSectionResolver.
 *
 * Covers: no-template/empty-id short-circuits to null (fixed-shape fallback),
 * an unresolvable template also falls back to null, a resolvable template's
 * section kinds are extracted, `sectionEnabled()`'s null-means-everything
 * rule, and `attendanceSectionEnabled()`'s combined gate.
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
 * @spec openspec/changes/report-card-templates/specs/report-card/spec.md#scenario-a-templated-cohort-composes-only-the-sections-its-template-declares
 * @spec openspec/changes/report-card-templates/specs/report-card/spec.md#scenario-an-untemplated-cohort-composes-exactly-as-before-this-change
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\ReportCardTemplateSectionResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for ReportCardTemplateSectionResolver.
 */
class ReportCardTemplateSectionResolverTest extends TestCase {

	/**
	 * A null/empty rawTemplateId resolves to null without calling find().
	 *
	 * @return void
	 */
	public function testNullOrEmptyTemplateIdResolvesToNullWithoutLookup(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->expects($this->never())->method('find');

		$resolver = new ReportCardTemplateSectionResolver($objectService, new NullLogger());

		self::assertNull($resolver->resolveSectionKinds(rawTemplateId: null));
		self::assertNull($resolver->resolveSectionKinds(rawTemplateId: ''));

	}//end testNullOrEmptyTemplateIdResolvesToNullWithoutLookup()

	/**
	 * A templateId that does not resolve to an object falls back to null
	 * (the fixed-shape composition path), not an exception.
	 *
	 * @return void
	 */
	public function testUnresolvableTemplateFallsBackToNull(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturn(null);

		$resolver = new ReportCardTemplateSectionResolver($objectService, new NullLogger());

		self::assertNull($resolver->resolveSectionKinds(rawTemplateId: 'missing-template'));

	}//end testUnresolvableTemplateFallsBackToNull()

	/**
	 * A resolvable template's declared section kinds are extracted, in
	 * declaration order, ignoring malformed entries.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/report-card-templates/specs/report-card/spec.md#scenario-a-templated-cohort-composes-only-the-sections-its-template-declares
	 */
	public function testResolvesDeclaredSectionKinds(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturn(
			OrEntityFactory::make(
				[
					'id' => 'template-1',
					'sections' => [
						['kind' => 'grades', 'order' => 1, 'scale' => 'steps'],
						['kind' => 'narrative', 'order' => 2, 'scale' => 'text'],
						'not-an-array-entry',
						['order' => 3, 'scale' => 'text'],
					],
				],
				'report-card-template'
			)
		);

		$resolver = new ReportCardTemplateSectionResolver($objectService, new NullLogger());

		self::assertSame(['grades', 'narrative'], $resolver->resolveSectionKinds(rawTemplateId: 'template-1'));

	}//end testResolvesDeclaredSectionKinds()

	/**
	 * `sectionEnabled()`: null sectionKinds (no template) means every kind is
	 * enabled; a non-null list only enables the kinds it names.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/report-card-templates/specs/report-card/spec.md#scenario-an-untemplated-cohort-composes-exactly-as-before-this-change
	 */
	public function testSectionEnabledNullMeansEverythingEnabled(): void {
		$resolver = new ReportCardTemplateSectionResolver($this->createMock(ObjectService::class), new NullLogger());

		self::assertTrue($resolver->sectionEnabled(kind: 'grades', sectionKinds: null));
		self::assertTrue($resolver->sectionEnabled(kind: 'portfolio', sectionKinds: null));
		self::assertTrue($resolver->sectionEnabled(kind: 'grades', sectionKinds: ['grades', 'narrative']));
		self::assertFalse($resolver->sectionEnabled(kind: 'attendance', sectionKinds: ['grades', 'narrative']));

	}//end testSectionEnabledNullMeansEverythingEnabled()

	/**
	 * `attendanceSectionEnabled()` requires BOTH the ReportPeriod's own
	 * `attendanceIncluded` gate and the template's own section gate.
	 *
	 * @return void
	 */
	public function testAttendanceSectionEnabledRequiresBothGates(): void {
		$resolver = new ReportCardTemplateSectionResolver($this->createMock(ObjectService::class), new NullLogger());

		// attendanceIncluded=false short-circuits regardless of template.
		self::assertFalse($resolver->attendanceSectionEnabled(attendanceIncluded: false, sectionKinds: null));
		self::assertFalse($resolver->attendanceSectionEnabled(attendanceIncluded: false, sectionKinds: ['attendance']));

		// attendanceIncluded=true, no template (null) -> enabled.
		self::assertTrue($resolver->attendanceSectionEnabled(attendanceIncluded: true, sectionKinds: null));

		// attendanceIncluded=true, template declares attendance -> enabled.
		self::assertTrue($resolver->attendanceSectionEnabled(attendanceIncluded: true, sectionKinds: ['attendance']));

		// attendanceIncluded=true, template does NOT declare attendance -> disabled.
		self::assertFalse($resolver->attendanceSectionEnabled(attendanceIncluded: true, sectionKinds: ['narrative']));

	}//end testAttendanceSectionEnabledRequiresBothGates()
}//end class
