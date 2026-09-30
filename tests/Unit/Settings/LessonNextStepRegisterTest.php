<?php

/**
 * The Lesson schema stores next step rules and a default next lesson.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-adaptive-path/spec.md#requirement-next-step-rules
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use OCA\Learniq\Tests\Support\RegisterSchemaPayloads;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the next step fields of the Lesson schema.
 */
class LessonNextStepRegisterTest extends TestCase {
	use RegisterSchemaPayloads;

	/**
	 * A lesson as the composer saves it.
	 *
	 * @return array<string, mixed>
	 */
	private static function lesson(): array {
		return [
			'courseId' => '00000000-0000-4000-8000-00000000000a',
			'name' => 'Quiz',
			'order' => 1,
			'contentType' => 'text',
			'tenant_id' => '00000000-0000-4000-8000-00000000000b',
			'nextStepRules' => [
				['when' => ['kind' => 'score-below', 'assessmentId' => '00000000-0000-4000-8000-00000000000c', 'belowScore' => 60], 'goToLessonId' => '00000000-0000-4000-8000-00000000000d'],
				['when' => ['kind' => 'assessment-min-score', 'assessmentId' => '00000000-0000-4000-8000-00000000000c', 'minScore' => 90], 'goToLessonId' => '00000000-0000-4000-8000-00000000000e'],
				['when' => ['kind' => 'lesson-completed', 'lessonId' => '00000000-0000-4000-8000-00000000000f'], 'goToLessonId' => '00000000-0000-4000-8000-00000000000e'],
			],
			'defaultNextLessonId' => '00000000-0000-4000-8000-000000000010',
		];
	}//end lesson()

	/**
	 * Whether a lesson's next step fields fit the shipped fragment. The whole
	 * Lesson schema carries an if/then block the validator cannot parse once
	 * OpenRegister's own keys are gone, so the two fields are validated as the
	 * shipped fragment, unchanged apart from those keys.
	 *
	 * @param array<string, mixed> $lesson The lesson.
	 *
	 * @return bool
	 */
	private static function fits(array $lesson): bool {
		$properties = self::shippedSchema(slug: 'lesson')['properties'];
		$fragment = self::withoutOrKeys(
			schema: [
				'type' => 'object',
				'properties' => ['nextStepRules' => $properties['nextStepRules'], 'defaultNextLessonId' => $properties['defaultNextLessonId']],
			]
		);
		$fragment['properties']['defaultNextLessonId']['type'] = ['string', 'null'];
		$payload = array_intersect_key($lesson, ['nextStepRules' => true, 'defaultNextLessonId' => true]);

		return (new Validator())->validate(json_decode((string)json_encode((object)$payload)), (string)json_encode($fragment))->isValid();
	}//end fits()

	/**
	 * The editor's payload fits the shipped Lesson schema; a lesson without
	 * next steps still does.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-adaptive-path/spec.md#requirement-next-step-rules
	 */
	public function testTheComposersRulesFitTheSchema(): void {
		self::assertTrue(self::fits(lesson: self::lesson()));

		$plain = self::lesson();
		unset($plain['nextStepRules'], $plain['defaultNextLessonId']);
		self::assertTrue(self::fits(lesson: $plain));
		$plain['defaultNextLessonId'] = null;
		self::assertTrue(self::fits(lesson: $plain));
	}//end testTheComposersRulesFitTheSchema()

	/**
	 * A rule needs a condition and a target, and its kind is one the resolver knows.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-adaptive-path/spec.md#requirement-next-step-rules
	 */
	public function testARuleNeedsAConditionAndATarget(): void {
		foreach ([['goToLessonId'], ['when']] as $drop) {
			$lesson = self::lesson();
			unset($lesson['nextStepRules'][0][$drop[0]]);
			self::assertFalse(self::fits(lesson: $lesson), $drop[0]);
		}

		$lesson = self::lesson();
		$lesson['nextStepRules'][0]['when']['kind'] = 'moon-phase';
		self::assertFalse(self::fits(lesson: $lesson));

		self::assertSame(['score-below', 'assessment-min-score', 'lesson-completed'], self::shippedSchema(slug: 'lesson')['properties']['nextStepRules']['items']['properties']['when']['properties']['kind']['enum']);
	}//end testARuleNeedsAConditionAndATarget()
}//end class
