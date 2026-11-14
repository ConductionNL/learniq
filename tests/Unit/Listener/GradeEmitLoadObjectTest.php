<?php

/**
 * Learniq grade-emit handlers' single-object read unit tests.
 *
 * Pins PortfolioGradeEmitHandler::loadObject() and
 * WerkprocesGradeEmitHandler::loadObject(): a blank id reads nothing, the
 * read is one learniq object by id, nothing found is null, and a row comes
 * back as an array whether OpenRegister returns an array or an entity.
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
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\Listener\PortfolioGradeEmitHandler;
use OCA\Learniq\Listener\WerkprocesGradeEmitHandler;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Tests for the loadObject() read of both grade-emit handlers.
 */
class GradeEmitLoadObjectTest extends TestCase {

	/**
	 * The findAll() configs the handler sent.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $reads = [];

	/**
	 * Call loadObject() on a handler whose store answers $answer.
	 *
	 * @param class-string $class The handler.
	 * @param array<int, mixed> $answer What findAll() returns.
	 * @param string $id The id asked for.
	 *
	 * @return array<string, mixed>|null
	 */
	private function load(string $class, array $answer, string $id): ?array {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config = []) use ($answer): array {
				$this->reads[] = $config;
				return $answer;
			}
		);
		$handler = new $class(
			objectService: $objectService,
			logger: $this->createMock(LoggerInterface::class),
			schemas: $this->createMock(ListenerSchemaResolver::class),
		);

		return (new ReflectionMethod($handler, 'loadObject'))->invoke($handler, 'curriculum-plan', $id);
	}//end load()

	/**
	 * Both handlers.
	 *
	 * @return array<string, array{0: class-string}>
	 */
	public static function handlers(): array {
		return [
			'portfolio' => [PortfolioGradeEmitHandler::class],
			'werkproces' => [WerkprocesGradeEmitHandler::class],
		];
	}//end handlers()

	/**
	 * A blank id is null without a read.
	 *
	 * @param class-string $class The handler.
	 *
	 * @return void
	 *
	 * @dataProvider handlers
	 */
	public function testABlankIdReadsNothing(string $class): void {
		self::assertNull($this->load(class: $class, answer: [['id' => 'x']], id: ''));
		self::assertSame([], $this->reads);
	}//end testABlankIdReadsNothing()

	/**
	 * One learniq object is read by id; nothing found is null.
	 *
	 * @param class-string $class The handler.
	 *
	 * @return void
	 *
	 * @dataProvider handlers
	 */
	public function testNothingFoundIsNull(string $class): void {
		self::assertNull($this->load(class: $class, answer: [], id: 'cp-1'));
		self::assertSame(
			[['ids' => ['cp-1'], 'filters' => ['register' => 'learniq', 'schema' => 'curriculum-plan'], 'limit' => 1]],
			$this->reads
		);
	}//end testNothingFoundIsNull()

	/**
	 * The first row comes back as an array, from an array or an entity.
	 *
	 * @param class-string $class The handler.
	 *
	 * @return void
	 *
	 * @dataProvider handlers
	 */
	public function testTheRowComesBackAsAnArray(string $class): void {
		self::assertSame(['id' => 'cp-1'], $this->load(class: $class, answer: [['id' => 'cp-1'], ['id' => 'cp-2']], id: 'cp-1'));

		$row = $this->load(class: $class, answer: [OrEntityFactory::make(['id' => 'cp-1', 'title' => 'Plan'], 'curriculum-plan')], id: 'cp-1');
		self::assertSame('Plan', ($row['title'] ?? null));
	}//end testTheRowComesBackAsAnArray()
}//end class
