<?php

/**
 * CourseTemplateSkeleton unit tests: a course's module structure as a template stores it.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service\Course
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Course;

use OCA\Learniq\Service\Course\CourseTemplateSkeleton;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The template skeleton: keys, order, and only rich-text blocks with placeholder text.
 */
#[CoversClass(CourseTemplateSkeleton::class)]
class CourseTemplateSkeletonTest extends TestCase {

	/**
	 * Modules and lessons get positional keys and fall back to their position for order.
	 *
	 * @return void
	 */
	public function testModulesAndLessonsGetKeysAndOrder(): void {
		$modules = [
			['name' => 'Basics', 'ectsCredits' => 5, 'lessons' => [['name' => 'Intro', 'contentType' => 'video', 'durationMinutes' => 20], ['name' => 'Quiz', 'order' => 9]]],
			['name' => 'Advanced', 'order' => 7],
		];

		self::assertSame(
			[
				[
					'key' => 'module-1',
					'name' => 'Basics',
					'order' => 1,
					'ectsCredits' => 5,
					'lessons' => [
						['key' => 'lesson-1-1', 'name' => 'Intro', 'order' => 1, 'contentType' => 'video', 'durationMinutes' => 20, 'blocksSkeleton' => []],
						['key' => 'lesson-1-2', 'name' => 'Quiz', 'order' => 9, 'contentType' => null, 'durationMinutes' => null, 'blocksSkeleton' => []],
					],
				],
				['key' => 'module-2', 'name' => 'Advanced', 'order' => 7, 'ectsCredits' => null, 'lessons' => []],
			],
			(new CourseTemplateSkeleton())->moduleStructure(modules: $modules, placeholder: '…')
		);
	}//end testModulesAndLessonsGetKeysAndOrder()

	/**
	 * Only rich-text blocks are kept, their text replaced by the placeholder.
	 *
	 * @return void
	 */
	public function testOnlyRichTextBlocksAreKeptWithThePlaceholder(): void {
		$modules = [[
			'name' => 'M',
			'lessons' => [[
				'name' => 'L',
				'blocks' => [
					['blockId' => 'b-1', 'type' => 'richText', 'order' => 1, 'text' => 'Secret course text'],
					['blockId' => 'b-2', 'type' => 'video', 'order' => 2, 'url' => 'https://example.org/v'],
					['type' => 'richText'],
					'not a block',
				],
			]],
		]];

		$blocks = (new CourseTemplateSkeleton())->moduleStructure(modules: $modules, placeholder: 'Write here')[0]['lessons'][0]['blocksSkeleton'];

		self::assertSame(
			[
				['blockId' => 'b-1', 'type' => 'richText', 'order' => 1, 'text' => 'Write here'],
				['blockId' => null, 'type' => 'richText', 'order' => null, 'text' => 'Write here'],
			],
			$blocks
		);
	}//end testOnlyRichTextBlocksAreKeptWithThePlaceholder()

	/**
	 * Lessons and blocks that are no list, or entries that are no array, are skipped.
	 *
	 * @return void
	 */
	public function testMalformedListsAreSkipped(): void {
		$structure = (new CourseTemplateSkeleton())->moduleStructure(
			modules: [['lessons' => 'none'], ['lessons' => ['x', ['name' => 'Kept', 'blocks' => 'none']]]],
			placeholder: '…'
		);

		self::assertSame('', $structure[0]['name']);
		self::assertSame([], $structure[0]['lessons']);
		self::assertSame(['lesson-2-1'], array_column($structure[1]['lessons'], 'key'));
		self::assertSame([], $structure[1]['lessons'][0]['blocksSkeleton']);
		self::assertSame([], (new CourseTemplateSkeleton())->moduleStructure(modules: [], placeholder: '…'));
	}//end testMalformedListsAreSkipped()
}//end class
