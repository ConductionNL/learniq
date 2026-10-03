<?php

/**
 * Unit tests for CourseStoreDescriptor's publish opt-in: what may leave this
 * server, who may send it, and what happens on an OpenRegister that cannot
 * publish yet.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\CourseStore
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/course-management/spec.md#requirement-a-course-store-publish-travels-through-the-store-planes-write-path
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\CourseStore;

use OCA\Learniq\Service\ActionAuthService;
use OCA\Learniq\Service\CourseStore\CourseStoreDescriptor;
use OCA\Learniq\Service\CourseStore\CourseStoreRegistryObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Learniq\Service\CourseStore\CourseStoreDescriptor
 * @uses   \OCA\Learniq\Service\CourseStore\CourseStoreRegistryObject
 */
class CourseStoreDescriptorTest extends TestCase {

	/**
	 * A matrix double answering the given groups for course-package.share.
	 *
	 * @param array<int, string> $groups The groups the matrix holds.
	 *
	 * @return ActionAuthService
	 */
	private function matrix(array $groups): ActionAuthService {
		$actionAuth = $this->createMock(ActionAuthService::class);
		$actionAuth->method('getAllowedGroups')->willReturnCallback(
			static fn (string $action): array => ($action === 'course-package.share' ? $groups : ['admin'])
		);

		return $actionAuth;
	}//end matrix()

	/**
	 * TC-2: every property the registry object carries may travel, and the
	 * list names nothing the object does not carry. A field added to the
	 * object without a decision here fails this test.
	 *
	 * @return void
	 */
	public function testPublishFieldsAreExactlyWhatTheRegistryObjectCarries(): void {
		$object = (new CourseStoreRegistryObject())->build(
			package: [
				'course'  => ['name' => 'Betoog schrijven, havo 4'],
				'sharing' => [
					'title'   => 'Betoog schrijven, havo 4',
					'license' => 'CC-BY-SA-4.0',
					'author'  => 'Sectie Nederlands, OSG De Vaart',
				],
			]
		);

		$carried = array_values(array_diff(array_keys($object), ['slug']));
		$allowed = CourseStoreDescriptor::PUBLISH_FIELDS;
		sort($carried);
		sort($allowed);

		self::assertSame($carried, $allowed);
	}//end testPublishFieldsAreExactlyWhatTheRegistryObjectCarries()

	/**
	 * TC-3: the matrix is the source of the publish groups.
	 *
	 * @return void
	 */
	public function testPublishGroupsComeFromTheMatrix(): void {
		$descriptor = (new CourseStoreDescriptor($this->matrix(['admin', 'team-leads'])))->descriptor();

		self::assertSame(['admin', 'team-leads'], $descriptor->publishGroups);
		self::assertSame(CourseStoreDescriptor::PUBLISH_FIELDS, $descriptor->publishFields);
		self::assertTrue($descriptor->isPublishable());
		self::assertSame('shared-course-package', $descriptor->schema);
	}//end testPublishGroupsComeFromTheMatrix()

	/**
	 * An explicitly empty matrix entry names nobody, so the descriptor does
	 * not opt in and the plane refuses every publish.
	 *
	 * @return void
	 */
	public function testAnEmptyMatrixEntryNamesNobody(): void {
		$descriptor = (new CourseStoreDescriptor($this->matrix([])))->descriptor();

		self::assertSame([], $descriptor->publishGroups);
		self::assertFalse($descriptor->isPublishable());
	}//end testAnEmptyMatrixEntryNamesNobody()

	/**
	 * The OpenRegister this suite runs against (the stubs follow #4079) can
	 * publish.
	 *
	 * @return void
	 */
	public function testThisOpenRegisterSupportsPublish(): void {
		self::assertTrue((new CourseStoreDescriptor($this->matrix(['admin'])))->supportsPublish());
	}//end testThisOpenRegisterSupportsPublish()

	/**
	 * TC-7: on an OpenRegister without the publish opt-in, no publish
	 * argument reaches the constructor (it would be a fatal error there),
	 * and the matrix is not even asked.
	 *
	 * @return void
	 */
	public function testWithoutPublishSupportNoPublishArgumentIsPassed(): void {
		$actionAuth = $this->createMock(ActionAuthService::class);
		$actionAuth->expects(self::never())->method('getAllowedGroups');

		$descriptorService = $this->getMockBuilder(CourseStoreDescriptor::class)
			->setConstructorArgs([$actionAuth])
			->onlyMethods(['supportsPublish'])
			->getMock();
		$descriptorService->method('supportsPublish')->willReturn(false);

		$descriptor = $descriptorService->descriptor();

		self::assertSame([], $descriptor->publishFields);
		self::assertSame([], $descriptor->publishGroups);
		self::assertFalse($descriptor->isPublishable());
		self::assertSame(CourseStoreDescriptor::CARD_FIELDS, $descriptor->cardFields);
	}//end testWithoutPublishSupportNoPublishArgumentIsPassed()
}//end class
