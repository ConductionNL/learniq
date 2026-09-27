<?php

/**
 * Unit tests for CourseShareExportService: gate, strip, record, return.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/lesson-sharing-consent-gate/tasks.md#task-2-share-export-service-consent-schema-and-record
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Exception\SharingBlockedException;
use OCA\Learniq\Service\CoursePackageExportService;
use OCA\Learniq\Service\CourseShareExportService;
use OCA\Learniq\Service\CourseSharePackageBuilder;
use OCA\Learniq\Service\CourseSharingGate;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Learniq\Service\CourseShareExportService
 */
class CourseShareExportServiceTest extends TestCase {

	/**
	 * Consent records the service tried to save.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * Build the service over a fixed course tree.
	 *
	 * @param array<string, mixed> $course      The course object in the tree.
	 * @param bool                 $failToSave  Whether saving the consent throws.
	 *
	 * @return CourseShareExportService
	 */
	private function service(array $course, bool $failToSave=false): CourseShareExportService {
		$tree = [
			'course'           => $course,
			'childCourses'     => [],
			'lessons'          => [['id' => 'lesson-1', 'name' => 'Betoog', 'learningObjectives' => ['Een betoog opbouwen']]],
			'materials'        => [['id' => 'm-1', 'title' => 'Uitleg', 'fileRef' => '/Docenten/uitleg.pdf', 'content' => 'PDF']],
			'assessments'      => [['id' => 'a-1', 'title' => 'Toets', 'accessCode' => 'KLAS3B']],
			'rubrics'          => [],
			'ltiPlacements'    => [['id' => 'lti-1']],
			'itemBankPackages' => [],
		];

		$exporter = $this->createMock(CoursePackageExportService::class);
		$exporter->method('gatherCourseTree')->with('course-1', 'docent-07')->willReturn($tree);
		$exporter->method('toScholiqPayload')->willReturnCallback(
			static function (array $tree): array {
				$materials = array_map(
					static function (array $material): array {
						$material['contentBase64'] = base64_encode((string)$material['content']);
						unset($material['content']);
						return $material;
					},
					$tree['materials']
				);
				return [...$tree, 'materials' => $materials];
			}
		);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend=[], $register=null, $schema=null) use ($failToSave) {
				if ($failToSave === true) {
					throw new RuntimeException('database down');
				}

				$this->saved[] = ['register' => $register, 'schema' => $schema, 'object' => $object];
				return OrEntityFactory::make($object, (string)$schema);
			}
		);

		return new CourseShareExportService($exporter, new CourseSharingGate(), new CourseSharePackageBuilder(), $objectService);
	}//end service()

	/**
	 * An openly licensed course with an author.
	 *
	 * @return array<string, mixed>
	 */
	private function openCourse(): array {
		return ['id' => 'course-1', 'name' => 'Nederlands havo 4', 'license' => 'CC-BY-SA-4.0', 'author' => 'Sectie Nederlands', 'tenant_id' => 'tenant-1'];
	}//end openCourse()

	/**
	 * A refused course throws with every reason and records nothing.
	 *
	 * @return void
	 */
	public function testARefusedCourseRecordsNothing(): void {
		$service = $this->service(['id' => 'course-1', 'name' => 'Wiskunde']);

		try {
			$service->buildPackage('course-1', 'docent-07', false, true);
			self::fail('The gate should refuse.');
		} catch (SharingBlockedException $e) {
			self::assertSame(
				['licence-missing', 'author-missing', 'pupil-data-not-confirmed'],
				array_column($e->getBlockers(), 'code')
			);
		}

		self::assertSame([], $this->saved);
	}//end testARefusedCourseRecordsNothing()

	/**
	 * An open course is stripped, carries the sharing block, and leaves one
	 * consent record with the confirming user.
	 *
	 * @return void
	 */
	public function testAnOpenCourseIsStrippedAndRecorded(): void {
		$package = $this->service($this->openCourse())->buildPackage('course-1', 'docent-07', true, true);

		self::assertArrayNotHasKey('tenant_id', $package['course']);
		self::assertArrayNotHasKey('fileRef', $package['materials'][0]);
		self::assertArrayNotHasKey('accessCode', $package['assessments'][0]);
		self::assertSame([], $package['ltiPlacements']);
		self::assertSame('CC-BY-SA-4.0', $package['sharing']['license']);
		self::assertStringNotContainsString('docent-07', (string)json_encode($package));

		self::assertCount(1, $this->saved);
		self::assertSame('learniq', $this->saved[0]['register']);
		self::assertSame('course-share-consent', $this->saved[0]['schema']);
		$record = $this->saved[0]['object'];
		self::assertSame('course-1', $record['courseId']);
		self::assertSame('Nederlands havo 4', $record['courseName']);
		self::assertSame('download', $record['purpose']);
		self::assertSame('docent-07', $record['confirmedBy']);
		self::assertTrue($record['noPupilData']);
		self::assertTrue($record['rightsCleared']);
		self::assertSame('CC-BY-SA-4.0', $record['license']);
		self::assertSame('tenant-1', $record['tenant_id']);
		self::assertSame($package['sharing']['sharedAt'], $record['confirmedAt']);
	}//end testAnOpenCourseIsStrippedAndRecorded()

	/**
	 * A consent that cannot be recorded fails the share.
	 *
	 * @return void
	 */
	public function testAFailedConsentFailsTheShare(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The share consent could not be recorded');

		$this->service($this->openCourse(), true)->buildPackage('course-1', 'docent-07', true, true);
	}//end testAFailedConsentFailsTheShare()

	/**
	 * The download is a JSON file named after the course.
	 *
	 * @return void
	 */
	public function testExportReturnsAJsonDownload(): void {
		$download = $this->service($this->openCourse())->export('course-1', 'docent-07', true, true);

		self::assertSame('course-course-1_share.json', $download['filename']);
		self::assertSame('application/json', $download['contentType']);
		self::assertSame('Nederlands havo 4', json_decode($download['content'], true)['sharing']['title']);
	}//end testExportReturnsAJsonDownload()
}//end class
