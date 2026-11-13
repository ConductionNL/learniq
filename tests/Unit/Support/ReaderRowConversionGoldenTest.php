<?php

/**
 * Learniq row conversion golden tests.
 *
 * Pins how each of these classes turns one OpenRegister result into an
 * array: an array as is, a serialisable object as its serialised data, and
 * anything else as an empty array.
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
use OCA\Learniq\BackgroundJob\LtiAgsScorePollJob;
use OCA\Learniq\Service\CoursePackageExportService;
use OCA\Learniq\Service\Portal\PortalWerkprocesAssessment;
use OCA\Learniq\Service\QtiExportService;
use OCA\Learniq\Service\RolloverService;
use OCA\Learniq\Service\XapiDocumentStore;
use OCA\Learniq\Timetabling\SessionSeries;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use stdClass;

/**
 * Golden tests for the row conversion of each class.
 */
class ReaderRowConversionGoldenTest extends TestCase {

	/**
	 * The classes and the method that converts a row.
	 *
	 * @return array<string, array{0: class-string, 1: string}>
	 */
	public static function converters(): array {
		return [
			'LtiAgsScorePollJob' => [LtiAgsScorePollJob::class, 'toArray'],
			'CoursePackageExportService' => [CoursePackageExportService::class, 'toArray'],
			'PortalWerkprocesAssessment' => [PortalWerkprocesAssessment::class, 'toRow'],
			'QtiExportService' => [QtiExportService::class, 'toArray'],
			'RolloverService' => [RolloverService::class, 'toArray'],
			'XapiDocumentStore' => [XapiDocumentStore::class, 'toArray'],
			'SessionSeries' => [SessionSeries::class, 'toArray'],
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
	 * Arrays stay, serialisable objects become their data, the rest is empty.
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
		self::assertSame(['scalar'], $read(self::serialisable(data: 'scalar')));
		self::assertSame([], $read(self::serialisable(data: null)));
		self::assertSame([], $read(new stdClass()));
		self::assertSame([], $read('a string'));
		self::assertSame([], $read(null));
		self::assertSame([], $read(42));
	}//end testARowIsReadTheSameWay()
}//end class
