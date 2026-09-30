<?php

/**
 * A lesson's next steps stay inside its own course.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Listener
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
 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-adaptive-path/spec.md#scenario-a-rule-cannot-leave-the-course
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\AppInfo\Registrar\IntegrityListenerRegistrar;
use OCA\Learniq\Listener\LessonNextStepGuard;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * Tests for LessonNextStepGuard.
 */
class LessonNextStepGuardTest extends TestCase {

	/**
	 * Lessons the guard can read, by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $lessons = [
		'l-refresher' => ['id' => 'l-refresher', 'courseId' => 'c-1'],
		'l-module-2' => ['id' => 'l-module-2', 'courseId' => 'c-1'],
		'l-elsewhere' => ['id' => 'l-elsewhere', 'courseId' => 'c-2'],
	];

	/**
	 * The guard, with the resolver reporting the given slug.
	 *
	 * @param string $slug       The schema slug of the written entity.
	 * @param bool   $findThrows Whether reading a lesson fails.
	 *
	 * @return LessonNextStepGuard
	 */
	private function guard(string $slug = 'lesson', bool $findThrows = false): LessonNextStepGuard {
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		if ($slug === '!') {
			$resolver->method('guardSchemaSlug')->willThrowException(new \RuntimeException('unknown schema'));
		} else {
			$resolver->method('guardSchemaSlug')->willReturn($slug);
		}

		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, mixed $register = null, mixed $schema = null) use ($findThrows): mixed {
				if ($findThrows === true) {
					throw new \RuntimeException('database gone');
				}

				$row = ($this->lessons[(string)$id] ?? null);
				if ($row === null || $schema !== 'lesson') {
					return null;
				}

				return OrEntityFactory::make($row, 'lesson');
			}
		);

		return new LessonNextStepGuard(schemaResolver: $resolver, objectService: $objects);
	}//end guard()

	/**
	 * A lesson of course c-1 with the given targets.
	 *
	 * @param string      $rule    The rule's target.
	 * @param string|null $default The default next lesson.
	 *
	 * @return array<string, mixed>
	 */
	private static function lesson(string $rule, ?string $default = null): array {
		return [
			'id' => 'l-quiz',
			'courseId' => 'c-1',
			'nextStepRules' => [['when' => ['kind' => 'score-below', 'assessmentId' => 'a-quiz', 'belowScore' => 60], 'goToLessonId' => $rule]],
			'defaultNextLessonId' => $default,
		];
	}//end lesson()

	/**
	 * Rules and a default inside the course are saved as written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-adaptive-path/spec.md#requirement-next-step-rules
	 */
	public function testTargetsInTheCourseAreAllowed(): void {
		$event = new ObjectCreatingEvent(OrEntityFactory::make(self::lesson('l-refresher', 'l-module-2'), 'lesson'));
		$this->guard()->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testTargetsInTheCourseAreAllowed()

	/**
	 * A rule or a default that leaves the course, or points at no lesson, is
	 * refused with the reason; on create and on update.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-adaptive-path/spec.md#scenario-a-rule-cannot-leave-the-course
	 */
	public function testATargetOutsideTheCourseIsRefused(): void {
		$create = new ObjectCreatingEvent(OrEntityFactory::make(self::lesson('l-elsewhere'), 'lesson'));
		$this->guard()->handle($create);
		self::assertTrue($create->isPropagationStopped());
		self::assertSame('A next step can only go to a lesson of the same course.', $create->getErrors()['message']);

		$old = OrEntityFactory::make(self::lesson('l-refresher'), 'lesson');
		$update = new ObjectUpdatingEvent(OrEntityFactory::make(self::lesson('l-refresher', 'l-gone'), 'lesson'), $old);
		$this->guard()->handle($update);
		self::assertTrue($update->isPropagationStopped());
		self::assertSame('next-step-outside-course', $update->getErrors()['reason']);

		$unreadable = new ObjectCreatingEvent(OrEntityFactory::make(self::lesson('l-refresher'), 'lesson'));
		$this->guard(findThrows: true)->handle($unreadable);
		self::assertTrue($unreadable->isPropagationStopped());
	}//end testATargetOutsideTheCourseIsRefused()

	/**
	 * Other schemas, other events, an unknown schema and a lesson without
	 * next steps pass untouched.
	 *
	 * @return void
	 */
	public function testOtherWritesPass(): void {
		$note = new ObjectCreatingEvent(OrEntityFactory::make(self::lesson('l-elsewhere'), 'lesson-note'));
		$this->guard(slug: 'lesson-note')->handle($note);
		self::assertFalse($note->isPropagationStopped());

		$unknown = new ObjectCreatingEvent(OrEntityFactory::make(self::lesson('l-elsewhere'), 'lesson'));
		$this->guard(slug: '!')->handle($unknown);
		self::assertFalse($unknown->isPropagationStopped());

		$plain = new ObjectCreatingEvent(OrEntityFactory::make(['id' => 'l-1', 'courseId' => 'c-1', 'nextStepRules' => 'none', 'defaultNextLessonId' => ''], 'lesson'));
		$this->guard()->handle($plain);
		self::assertFalse($plain->isPropagationStopped());

		$this->guard()->handle(new Event());
		self::assertTrue(true, 'a foreign event is ignored');
	}//end testOtherWritesPass()

	/**
	 * The guard is registered on lesson creates and updates.
	 *
	 * @return void
	 */
	public function testTheGuardIsRegisteredOnCreateAndUpdate(): void {
		$wired = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$wired): void {
				$wired[] = [$event, $listener];
			}
		);

		(new IntegrityListenerRegistrar())->register(context: $context);

		self::assertContains([ObjectCreatingEvent::class, LessonNextStepGuard::class], $wired);
		self::assertContains([ObjectUpdatingEvent::class, LessonNextStepGuard::class], $wired);
	}//end testTheGuardIsRegisteredOnCreateAndUpdate()
}//end class
