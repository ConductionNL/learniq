<?php

/**
 * OpenMaterialSearch for a course: the commercial flag, and a found record.
 *
 * A panel opened on a course says whether that course is commercial, so a
 * non-commercial licence can be flagged; a course that is not the caller's,
 * or not found, is not commercial rather than an error. A record the source
 * has carries its licence decision.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\OpenMaterial
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/open-learning-material/spec.md#scenario-non-commercial-material-is-flagged-in-a-paid-course
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\OpenMaterial;

use OCA\Learniq\Exception\MaterialImportRefusedException;
use OCA\Learniq\Service\ConnectionReportService;
use OCA\Learniq\Service\OpenMaterial\MaterialImportTarget;
use OCA\Learniq\Service\OpenMaterial\MaterialLicenceRules;
use OCA\Learniq\Service\OpenMaterial\OpenMaterialImportService;
use OCA\Learniq\Service\OpenMaterial\OpenMaterialSearch;
use OCA\Learniq\Service\OpenMaterial\OpenMaterialSettings;
use OCA\Learniq\Service\OpenMaterial\OpenMaterialSourceClient;
use OCA\Learniq\Service\SegmentService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class OpenMaterialSearchCourseTest extends TestCase {

	private OpenMaterialSourceClient&MockObject $client;

	private MaterialImportTarget&MockObject $targets;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->client  = $this->createMock(OpenMaterialSourceClient::class);
		$this->targets = $this->createMock(MaterialImportTarget::class);
		$this->client->method('isAvailable')->willReturn(true);
	}//end setUp()

	/**
	 * The search with default settings and the course lookup under test.
	 *
	 * @return OpenMaterialSearch The service.
	 */
	private function search(): OpenMaterialSearch {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);
		$settings = new OpenMaterialSettings($appConfig);
		$imports  = new OpenMaterialImportService(
			$this->client,
			new MaterialLicenceRules(),
			$settings,
			$this->createMock(MaterialImportTarget::class),
			$this->createMock(ObjectService::class)
		);

		return new OpenMaterialSearch($this->client, $imports, $settings, $this->createMock(SegmentService::class), $this->createMock(ConnectionReportService::class), $this->targets);
	}//end search()

	public function testAPanelOnAPaidCourseSaysItIsCommercial(): void {
		$user = $this->createMock(IUser::class);
		$this->targets->expects($this->once())->method('resolve')
			->with($user, ['courseId' => 'course-1'])
			->willReturn(['fields' => [], 'commercial' => true]);

		$panel = $this->search()->panel(course: ['user' => $user, 'courseId' => 'course-1']);

		$this->assertTrue($panel['commercialCourse']);
		$this->assertTrue($panel['available']);
	}//end testAPanelOnAPaidCourseSaysItIsCommercial()

	public function testAPanelOnAFreeCourseSaysItIsNot(): void {
		$this->targets->method('resolve')->willReturn(['fields' => [], 'commercial' => false]);

		$panel = $this->search()->panel(course: ['user' => $this->createMock(IUser::class), 'courseId' => 'course-2']);

		$this->assertFalse($panel['commercialCourse']);
	}//end testAPanelOnAFreeCourseSaysItIsNot()

	public function testACourseThatIsNotTheCallersIsNotCommercialRatherThanAnError(): void {
		$this->targets->method('resolve')->willThrowException(new MaterialImportRefusedException('course-not-found', 'No such course.'));

		$panel = $this->search()->panel(course: ['user' => $this->createMock(IUser::class), 'courseId' => 'elsewhere']);

		$this->assertFalse($panel['commercialCourse']);
	}//end testACourseThatIsNotTheCallersIsNotCommercialRatherThanAnError()

	public function testAPanelWithoutACourseAsksNothingAboutOne(): void {
		$this->targets->expects($this->never())->method('resolve');

		$withoutCourse = $this->search()->panel(course: ['user' => $this->createMock(IUser::class), 'courseId' => '']);
		$withoutUser   = $this->search()->panel(course: ['courseId' => 'course-1']);

		$this->assertArrayNotHasKey('commercialCourse', $withoutCourse);
		$this->assertArrayNotHasKey('commercialCourse', $withoutUser);
	}//end testAPanelWithoutACourseAsksNothingAboutOne()

	public function testAFoundRecordCarriesItsLicenceDecision(): void {
		$this->client->method('find')->with('urn:test:a')->willReturn(
			['sourceId' => 'urn:test:a', 'license' => 'CC-BY-ND-4.0', 'accessRights' => 'OpenAccess', 'files' => []]
		);

		$record = $this->search()->record(sourceId: 'urn:test:a');

		$this->assertSame('urn:test:a', $record['sourceId']);
		$this->assertSame(['mode' => 'link', 'reason' => 'no-derivatives', 'nonCommercial' => false], $record['decision']);
	}//end testAFoundRecordCarriesItsLicenceDecision()
}//end class
