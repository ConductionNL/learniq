<?php

/**
 * A lesson's next step follows the first rule that holds for the learner.
 *
 * The resolver runs over the real LessonReleaseEvaluator and a store that
 * filters the way OpenRegister does, so a rule can only ever see the
 * requesting learner's own results.
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
 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-adaptive-path/spec.md#requirement-next-step-rules
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\LessonReleaseEvaluator;
use OCA\Learniq\Service\NextStepResolver;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for NextStepResolver.
 */
class NextStepResolverTest extends TestCase {

	/**
	 * The store behind the ObjectService double.
	 *
	 * @var RegisterFaithfulStore
	 */
	private RegisterFaithfulStore $store;

	/**
	 * A resolver over the real evaluator and an empty store.
	 *
	 * @return NextStepResolver
	 */
	private function resolver(): NextStepResolver {
		$this->store = new RegisterFaithfulStore();
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $this->store->findAll($config, $_rbac, $_multitenancy)
		);

		return new NextStepResolver(evaluator: new LessonReleaseEvaluator(objectService: $objects));
	}//end resolver()

	/**
	 * The quiz lesson: score below 60 goes to the refresher, else Module 2.
	 *
	 * @return array<string, mixed>
	 */
	private static function quizLesson(): array {
		return [
			'id' => 'l-quiz',
			'courseId' => 'c-1',
			'tenant_id' => 't1',
			'nextStepRules' => [
				['when' => ['kind' => 'score-below', 'assessmentId' => 'a-quiz', 'belowScore' => 60], 'goToLessonId' => 'l-refresher'],
			],
			'defaultNextLessonId' => 'l-module-2',
		];
	}//end quizLesson()

	/**
	 * A graded attempt of a learner on the quiz.
	 *
	 * @param string $learner The learner.
	 * @param float  $score   The summed score.
	 *
	 * @return array<string, mixed>
	 */
	private static function attempt(string $learner, float $score): array {
		return [
			'id' => 'r-' . $learner . '-' . $score,
			'assessmentId' => 'a-quiz',
			'learnerId' => $learner,
			'lifecycle' => 'graded',
			'tenant_id' => 't1',
			'responses' => [['autoScore' => $score]],
		];
	}//end result()

	/**
	 * A learner who scores 45 is sent to the refresher by the first rule.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-adaptive-path/spec.md#scenario-a-learner-who-fails-is-sent-to-a-refresher
	 */
	public function testALearnerWhoFailsIsSentToTheRefresher(): void {
		$resolver = $this->resolver();
		$this->store->rows['assessment-result'] = [self::attempt('jan', 45)];

		self::assertSame(['nextLessonId' => 'l-refresher', 'rule' => 0], $resolver->resolve(lesson: self::quizLesson(), learnerId: 'jan'));
	}//end testALearnerWhoFailsIsSentToTheRefresher()

	/**
	 * A learner who scores 80 continues to the default next lesson; the best
	 * of several attempts counts.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-adaptive-path/spec.md#scenario-a-learner-who-passes-continues
	 */
	public function testALearnerWhoPassesContinues(): void {
		$resolver = $this->resolver();
		$this->store->rows['assessment-result'] = [self::attempt('jan', 45), self::attempt('jan', 80)];

		self::assertSame(['nextLessonId' => 'l-module-2', 'rule' => null], $resolver->resolve(lesson: self::quizLesson(), learnerId: 'jan'));
	}//end testALearnerWhoPassesContinues()

	/**
	 * Another learner's 80 never lifts this learner's 45, and a learner with
	 * no graded attempt is neither above nor below: the default applies.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-adaptive-path/spec.md#requirement-next-step-rules
	 */
	public function testOnlyTheRequestingLearnersResultsCount(): void {
		$resolver = $this->resolver();
		$this->store->rows['assessment-result'] = [self::attempt('piet', 80), self::attempt('jan', 45)];

		self::assertSame('l-refresher', $resolver->resolve(lesson: self::quizLesson(), learnerId: 'jan')['nextLessonId']);
		self::assertSame('l-module-2', $resolver->resolve(lesson: self::quizLesson(), learnerId: 'piet')['nextLessonId']);
		self::assertSame('l-module-2', $resolver->resolve(lesson: self::quizLesson(), learnerId: 'kim')['nextLessonId']);

		foreach ($this->store->reads as $read) {
			self::assertContains($read['config']['filters']['learnerId'], ['jan', 'piet', 'kim']);
		}
	}//end testOnlyTheRequestingLearnersResultsCount()

	/**
	 * Rules are tried in order: a minimum score rule and a completed-lesson
	 * rule, the latter read from the learner's own xAPI completion.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-adaptive-path/spec.md#requirement-next-step-rules
	 */
	public function testRulesAreTriedInOrder(): void {
		$resolver = $this->resolver();
		$lesson = self::quizLesson();
		$lesson['nextStepRules'] = [
			['when' => ['kind' => 'assessment-min-score', 'assessmentId' => 'a-quiz', 'minScore' => 90], 'goToLessonId' => 'l-honours'],
			['when' => ['kind' => 'lesson-completed', 'lessonId' => 'l-intro'], 'goToLessonId' => 'l-practice'],
		];
		$this->store->rows['assessment-result'] = [self::attempt('jan', 80), self::attempt('eva', 95)];
		$this->store->rows['xapi-statement'] = [
			['id' => 's-1', 'lessonId' => 'l-intro', 'verified_actor_id' => 'jan', 'tenant_id' => 't1', 'verb' => ['id' => 'http://adlnet.gov/expapi/verbs/completed']],
		];

		self::assertSame(['nextLessonId' => 'l-honours', 'rule' => 0], $resolver->resolve(lesson: $lesson, learnerId: 'eva'));
		self::assertSame(['nextLessonId' => 'l-practice', 'rule' => 1], $resolver->resolve(lesson: $lesson, learnerId: 'jan'));
		self::assertSame(['nextLessonId' => 'l-module-2', 'rule' => null], $resolver->resolve(lesson: $lesson, learnerId: 'kim'));
	}//end testRulesAreTriedInOrder()

	/**
	 * In a preview the simulated score stands in for every score and a
	 * prerequisite lesson counts as completed; nothing is read.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-preview-as-learner/spec.md#scenario-a-teacher-walks-the-course-as-a-learner
	 */
	public function testAPreviewUsesTheSimulatedScore(): void {
		$resolver = $this->resolver();
		$lesson = self::quizLesson();

		self::assertSame('l-refresher', $resolver->resolvePreview(lesson: $lesson, simulatedScore: 45.0)['nextLessonId']);
		self::assertSame('l-module-2', $resolver->resolvePreview(lesson: $lesson, simulatedScore: 80.0)['nextLessonId']);
		self::assertSame('l-module-2', $resolver->resolvePreview(lesson: $lesson, simulatedScore: null)['nextLessonId']);

		$lesson['nextStepRules'][] = ['when' => ['kind' => 'lesson-completed', 'lessonId' => 'l-intro'], 'goToLessonId' => 'l-practice'];
		self::assertSame(['nextLessonId' => 'l-practice', 'rule' => 1], $resolver->resolvePreview(lesson: $lesson, simulatedScore: 80.0));
		self::assertSame([], $this->store->reads);
	}//end testAPreviewUsesTheSimulatedScore()

	/**
	 * Half-written rules are skipped, never matched: no target, no
	 * condition, an unknown kind, a missing assessment or threshold, a
	 * completed-lesson rule without a lesson. No default is the end of the
	 * course.
	 *
	 * @return void
	 */
	public function testHalfWrittenRulesAreSkipped(): void {
		$resolver = $this->resolver();
		$this->store->rows['assessment-result'] = [self::attempt('jan', 45)];
		$lesson = [
			'id' => 'l-quiz',
			'tenant_id' => 't1',
			'nextStepRules' => [
				'not a rule',
				['when' => ['kind' => 'score-below', 'assessmentId' => 'a-quiz', 'belowScore' => 60]],
				['goToLessonId' => 'l-x'],
				['when' => ['kind' => 'moon-phase'], 'goToLessonId' => 'l-x'],
				['when' => ['kind' => 'score-below', 'belowScore' => 60], 'goToLessonId' => 'l-x'],
				['when' => ['kind' => 'score-below', 'assessmentId' => 'a-quiz'], 'goToLessonId' => 'l-x'],
				['when' => ['kind' => 'lesson-completed'], 'goToLessonId' => 'l-x'],
			],
		];

		self::assertSame(['nextLessonId' => null, 'rule' => null], $resolver->resolve(lesson: $lesson, learnerId: 'jan'));

		$lesson['nextStepRules'] = 'not a list';
		$lesson['defaultNextLessonId'] = 'l-next';
		self::assertSame(['nextLessonId' => 'l-next', 'rule' => null], $resolver->resolve(lesson: $lesson, learnerId: 'jan'));
	}//end testHalfWrittenRulesAreSkipped()
}//end class
