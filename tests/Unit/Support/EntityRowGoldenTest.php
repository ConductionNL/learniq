<?php

/**
 * Learniq entity-row conversion golden tests.
 *
 * Pins how each of these classes turns one OpenRegister result into an
 * array: an array as is, anything else as its serialised data, which must
 * be an array.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Support;

use JsonSerializable;
use OCA\Learniq\Listener\AdmissionsWaitlistPromoter;
use OCA\Learniq\Listener\ConferenceScheduleGenerator;
use OCA\Learniq\Listener\ReportCardComposer;
use OCA\Learniq\Listener\ReportCardPublishHandler;
use OCA\Learniq\Service\AssessmentResultAudience;
use OCA\Learniq\Service\AttendanceWindowAggregator;
use OCA\Learniq\Timetabling\SessionWindowLoader;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use stdClass;

/**
 * Golden tests for the row conversion of each class.
 */
class EntityRowGoldenTest extends TestCase {

	/**
	 * The classes and the method that converts a row.
	 *
	 * @return array<string, array{0: class-string, 1: string}>
	 */
	public static function converters(): array {
		return [
			'AdmissionsWaitlistPromoter' => [AdmissionsWaitlistPromoter::class, 'normalise'],
			'ConferenceScheduleGenerator' => [ConferenceScheduleGenerator::class, 'normalise'],
			'ReportCardComposer' => [ReportCardComposer::class, 'normalise'],
			'ReportCardPublishHandler' => [ReportCardPublishHandler::class, 'normalise'],
			'AssessmentResultAudience' => [AssessmentResultAudience::class, 'toRow'],
			'AttendanceWindowAggregator' => [AttendanceWindowAggregator::class, 'normalise'],
			'SessionWindowLoader' => [SessionWindowLoader::class, 'normalise'],
		];
	}//end converters()

	/**
	 * A serialisable object answering $data.
	 *
	 * @param mixed $data What jsonSerialize() answers.
	 *
	 * @return JsonSerializable
	 */
	private static function serialisable(mixed $data): JsonSerializable {
		return new class ($data) implements JsonSerializable {
			/**
			 * Constructor.
			 *
			 * @param mixed $data What jsonSerialize() answers.
			 */
			public function __construct(private readonly mixed $data) {
			}//end __construct()

			/**
			 * The data.
			 *
			 * @return mixed
			 */
			public function jsonSerialize(): mixed {
				return $this->data;
			}//end jsonSerialize()
		};
	}//end serialisable()

	/**
	 * Build the class through its constructor, with a double for every
	 * collaborator.
	 *
	 * @param class-string $class The class.
	 *
	 * @return object
	 */
	private function instance(string $class): object {
		$args = [];
		foreach (((new ReflectionClass($class))->getConstructor()?->getParameters() ?? []) as $parameter) {
			$type = $parameter->getType();
			if ($parameter->isDefaultValueAvailable() === true) {
				continue;
			}

			$args[$parameter->getName()] = $this->createMock(($type instanceof ReflectionNamedType) ? $type->getName() : 'stdClass');
		}

		return new $class(...$args);
	}//end instance()

	/**
	 * Arrays stay, entities become their data; anything else is an error.
	 *
	 * @param class-string $class The class.
	 * @param string $method The converting method.
	 *
	 * @return void
	 *
	 * @dataProvider converters
	 */
	public function testARowIsReadTheSameWay(string $class, string $method): void {
		$instance = $this->instance(class: $class);
		$convert = new ReflectionMethod($class, $method);
		$read = static fn (mixed $value): mixed => $convert->invoke($instance, $value);

		self::assertSame(['id' => 'r-1', 'n' => 2], $read(['id' => 'r-1', 'n' => 2]));
		self::assertSame([], $read([]));
		self::assertSame(['id' => 'r-2'], $read(self::serialisable(data: ['id' => 'r-2'])));
		foreach ([self::serialisable(data: 'scalar'), new stdClass(), 'a string', null] as $value) {
			$thrown = null;
			try {
				$read($value);
			} catch (\Throwable $error) {
				$thrown = $error::class;
			}

			self::assertContains($thrown, ['TypeError', 'Error']);
		}
	}//end testARowIsReadTheSameWay()
}//end class

