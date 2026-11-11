<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\Listener\AssignmentLearnerRefsStamp;
use OCA\Learniq\Listener\CheckInWindowStamp;
use OCA\Learniq\Listener\DataCorrectionRequestStamp;
use OCA\Learniq\Listener\ExamSittingPlacementCheck;
use OCA\Learniq\Listener\ExcuseRequestOwnerStamp;
use OCA\Learniq\Listener\HourWeekSubmissionStamp;
use OCA\Learniq\Listener\LessonNextStepGuard;
use OCA\Learniq\Listener\LessonNoteAuthorGuard;
use OCA\Learniq\Listener\SchemaWrite;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Pins which object each of these listeners reads from a write event: the
 * new state on an update, the object on a create, never the stored state.
 */
class WrittenObjectGoldenTest extends TestCase {

	/**
	 * The listeners and the method that picks the written object.
	 *
	 * @return array<string, array{0: class-string, 1: string}>
	 */
	public static function listeners(): array {
		return [
			'SchemaWrite' => [SchemaWrite::class, 'entityOf'],
			'AssignmentLearnerRefsStamp' => [AssignmentLearnerRefsStamp::class, 'entityOf'],
			'CheckInWindowStamp' => [CheckInWindowStamp::class, 'entityOf'],
			'DataCorrectionRequestStamp' => [DataCorrectionRequestStamp::class, 'entityOf'],
			'ExamSittingPlacementCheck' => [ExamSittingPlacementCheck::class, 'entityOf'],
			'ExcuseRequestOwnerStamp' => [ExcuseRequestOwnerStamp::class, 'entityOf'],
			'HourWeekSubmissionStamp' => [HourWeekSubmissionStamp::class, 'entityOf'],
			'LessonNextStepGuard' => [LessonNextStepGuard::class, 'writtenEntity'],
			'LessonNoteAuthorGuard' => [LessonNoteAuthorGuard::class, 'writtenEntity'],
		];
	}//end listeners()

	/**
	 * Create gives the object, update the new state.
	 *
	 * @param class-string $class The listener.
	 * @param string $method The picking method.
	 *
	 * @return void
	 *
	 * @dataProvider listeners
	 */
	public function testTheWrittenObjectIsPicked(string $class, string $method): void {
		$pick = new ReflectionMethod($class, $method);
		$instance = null;
		if ($pick->isStatic() === false) {
			$instance = (new ReflectionClass($class))->newInstanceWithoutConstructor();
		}

		$created = OrEntityFactory::make(['id' => 'o-1', 'state' => 'created'], 'thing');
		$new = OrEntityFactory::make(['id' => 'o-1', 'state' => 'new'], 'thing');
		$old = OrEntityFactory::make(['id' => 'o-1', 'state' => 'old'], 'thing');

		self::assertSame($created, $pick->invoke($instance, new ObjectCreatingEvent($created)));
		self::assertSame($new, $pick->invoke($instance, new ObjectUpdatingEvent($new, $old)));
	}//end testTheWrittenObjectIsPicked()
}//end class
