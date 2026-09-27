<?php

/**
 * Unit tests for CourseSharePackageBuilder: what a share package leaves out
 * and what it adds.
 *
 * When a schema the export reads gains a field naming a person, a school
 * structure or a secret, add it to CourseSharePackageBuilder::STRIP_KEYS and
 * to the list below.
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
 * @spec openspec/changes/lesson-sharing-consent-gate/tasks.md#task-1-gate-and-package-builder
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\CourseSharePackageBuilder;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Learniq\Service\CourseSharePackageBuilder
 */
class CourseSharePackageBuilderTest extends TestCase {

	/**
	 * A payload as CoursePackageExportService::toScholiqPayload() builds it.
	 *
	 * @return array<string, mixed>
	 */
	private function payload(): array {
		return [
			'schemaVersion' => '1.0',
			'course'        => [
				'id'                => 'course-1',
				'name'              => 'Nederlands havo 4',
				'description'       => 'Schrijfvaardigheid',
				'license'           => 'CC-BY-SA-4.0',
				'author'            => 'Sectie Nederlands',
				'subject'           => 'Nederlandse taal',
				'educationalLevels' => ['havo'],
				'language'          => 'nl',
				'tenant_id'         => 'tenant-1',
				'curriculumPlanId'  => 'plan-1',
				'programmeIds'      => ['prog-1'],
				'@self'             => ['owner' => 'docent-07', 'organisation' => 'org-1'],
			],
			'childCourses'  => [],
			'lessons'       => [
				['id' => 'lesson-1', 'name' => 'Betoog', 'learningObjectives' => ['Een betoog opbouwen', ' '], 'educationalLevels' => ['havo', 'vwo'], 'tenant_id' => 'tenant-1'],
				['id' => 'lesson-2', 'name' => 'Bronnen', 'learningObjectives' => ['Een betoog opbouwen', 'Bronnen vermelden']],
			],
			'materials'     => [['id' => 'm-1', 'title' => 'Uitleg', 'fileRef' => '/Docenten/klas 4b/uitleg.pdf', 'contentBase64' => 'UERG']],
			'assessments'   => [['id' => 'a-1', 'title' => 'Toets', 'accessCode' => 'KLAS3B', 'cohortId' => 'cohort-1', 'sessionId' => 's-1', 'gradeEntryComponentId' => 'g-1']],
			'rubrics'       => [['id' => 'r-1', 'name' => 'Beoordeling', 'tenant_id' => 'tenant-1']],
			'ltiPlacements' => [['id' => 'lti-1', 'openconnectorDeploymentId' => 'dep-1']],
		];
	}//end payload()

	/**
	 * The strip list is pinned: shrinking it is a deliberate decision.
	 *
	 * @return void
	 */
	public function testTheStripListIsPinned(): void {
		foreach (['@self', 'tenant_id', 'fileRef', 'sessionId', 'cohortId', 'curriculumPlanId', 'curriculumPlanComponentId', 'programmeIds', 'gradeEntryComponentId', 'gradeScaleId', 'accessCode'] as $key) {
			self::assertContains($key, CourseSharePackageBuilder::STRIP_KEYS);
		}
	}//end testTheStripListIsPinned()

	/**
	 * No strip key survives anywhere in the package, and LTI placements go.
	 *
	 * @return void
	 */
	public function testSchoolBoundPersonalAndSecretFieldsAreRemoved(): void {
		$package = (new CourseSharePackageBuilder())->build($this->payload(), '2026-09-27T10:00:00+00:00');
		$encoded = (string)json_encode($package);

		foreach (CourseSharePackageBuilder::STRIP_KEYS as $key) {
			self::assertStringNotContainsString('"' . $key . '"', $encoded, "$key survived stripping.");
		}

		self::assertStringNotContainsString('KLAS3B', $encoded);
		self::assertStringNotContainsString('docent-07', $encoded);
		self::assertStringNotContainsString('klas 4b', $encoded);
		self::assertSame([], $package['ltiPlacements']);
		self::assertSame('UERG', $package['materials'][0]['contentBase64']);
		self::assertSame('Toets', $package['assessments'][0]['title']);
	}//end testSchoolBoundPersonalAndSecretFieldsAreRemoved()

	/**
	 * The sharing block carries the NL-LOM metadata, levels and goals merged
	 * from the lessons, and no user id.
	 *
	 * @return void
	 */
	public function testTheSharingBlockCarriesTheMetadata(): void {
		$sharing = (new CourseSharePackageBuilder())->build($this->payload(), '2026-09-27T10:00:00+00:00')['sharing'];

		self::assertSame('Nederlands havo 4', $sharing['title']);
		self::assertSame('CC-BY-SA-4.0', $sharing['license']);
		self::assertSame('Sectie Nederlands', $sharing['author']);
		self::assertSame('Nederlandse taal', $sharing['subject']);
		self::assertSame(['havo', 'vwo'], $sharing['educationalLevels']);
		self::assertSame(['Een betoog opbouwen', 'Bronnen vermelden'], $sharing['goalsCovered']);
		self::assertSame('nl', $sharing['language']);
		self::assertSame(2, $sharing['lessonCount']);
		self::assertSame('2026-09-27T10:00:00+00:00', $sharing['sharedAt']);
		self::assertArrayNotHasKey('confirmedBy', $sharing);
	}//end testTheSharingBlockCarriesTheMetadata()

	/**
	 * A course without a subject takes the first lesson subject.
	 *
	 * @return void
	 */
	public function testSubjectFallsBackToALesson(): void {
		$sharing = (new CourseSharePackageBuilder())->sharingBlock(['name' => 'X'], [['subject' => ''], ['subject' => 'Aardrijkskunde']], 'now');

		self::assertSame('Aardrijkskunde', $sharing['subject']);
		self::assertSame([], $sharing['goalsCovered']);
	}//end testSubjectFallsBackToALesson()
}//end class
