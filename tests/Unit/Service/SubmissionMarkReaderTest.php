<?php

/**
 * Learniq SubmissionMarkReader tests
 *
 * Pins how the marks of a submission are read: one read as the system,
 * entity and array rows alike, relations left out, anything else skipped.
 *
 * @category Test
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
 * @spec openspec/specs/assignments/spec.md#scenario-the-second-marker-cannot-peek
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\SubmissionMarkReader;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * Reading a submission's marks.
 */
class SubmissionMarkReaderTest extends TestCase {

	/**
	 * Every read: its config and its rbac flag.
	 *
	 * @var list<array{0: array<string, mixed>, 1: bool}>
	 */
	private array $reads = [];

	/**
	 * A reader over a store answering these rows.
	 *
	 * @param array<int, mixed> $rows The rows.
	 *
	 * @return SubmissionMarkReader
	 */
	private function reader(array $rows): SubmissionMarkReader {
		$store = $this->createMock(ObjectService::class);
		$store->method('findAll')->willReturnCallback(
			function (array $config = [], bool $_rbac = true) use ($rows): array {
				$this->reads[] = [$config, $_rbac];
				return $rows;
			}
		);

		return new SubmissionMarkReader($store);
	}//end reader()

	/**
	 * A reader who sees all gets every mark row, relations left out, in
	 * store order; a submission without an id reads nothing.
	 *
	 * @return void
	 */
	public function testTheMarksOfASubmission(): void {
		$entity = OrEntityFactory::make(['markerId' => 'm-2', 'lifecycle' => 'draft', 'proposedGrade' => 6], 'submission-mark', 'learniq', 'mk-2');
		$read = $this->reader(
			[
				['id' => 'mk-1', 'markerId' => 'm-1', 'lifecycle' => 'submitted', 'proposedGrade' => 8, '@self' => ['id' => 'mk-1', 'relations' => ['x']]],
				$entity,
				'not a row',
				new \stdClass(),
			]
		)->forCaller(submission: ['id' => 's-1'], userId: 'teacher', seesAll: true);

		self::assertSame(
			[[['filters' => ['register' => 'learniq', 'schema' => 'submission-mark', 'submissionId' => 's-1'], 'limit' => 50], false]],
			$this->reads
		);
		self::assertCount(2, $read['marks']);
		self::assertSame(['id' => 'mk-1', 'markerId' => 'm-1', 'lifecycle' => 'submitted', 'proposedGrade' => 8, '@self' => ['id' => 'mk-1']], $read['marks'][0]);
		self::assertSame('m-2', $read['marks'][1]['markerId']);
		self::assertArrayNotHasKey('relations', $read['marks'][1]['@self'] ?? []);
		self::assertSame(['allocated' => 2, 'submitted' => 1, 'average' => 8.0, 'highest' => 8.0], $read['summary']);
		self::assertFalse($read['complete']);

		$this->reads = [];
		$none = $this->reader([['markerId' => 'teacher']])->forCaller(submission: [], userId: 'teacher', seesAll: true);
		self::assertSame([], $this->reads);
		self::assertSame([], $none['marks']);
	}//end testTheMarksOfASubmission()
}//end class
