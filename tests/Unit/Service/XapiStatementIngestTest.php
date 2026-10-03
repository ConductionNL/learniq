<?php

/**
 * Tests for re-sent statement ids in the xAPI statement ingest.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-09-30-cmi5-xapi-lrs-ingest/tasks.md#9-statement-id-conflicts
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Learniq\Exception\XapiRequestException;
use OCA\Learniq\Service\CallerTenantResolver;
use OCA\Learniq\Service\XapiStatementIngest;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * xAPI 1.0.3: a known id with a matching statement is a no-op, with a different one a 409.
 */
class XapiStatementIngestTest extends TestCase {

	/**
	 * The stored rows by id, as the fake append-only schema holds them.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $rows = [];

	/**
	 * Ids passed to saveObject, in order.
	 *
	 * @var array<int, string>
	 */
	private array $saves = [];

	/**
	 * A statement id.
	 *
	 * @var string
	 */
	private const ID = '7a394703-09fd-436b-9c90-78da537af5a5';

	/**
	 * The ingest over an OpenRegister double that behaves like the live one for this schema.
	 *
	 * - `saveObject` refuses a uuid that is already stored (append-only), with the live message;
	 * - `find` throws DoesNotExistException for a missing id, as a scoped lookup does;
	 * - a property the statement did not send comes back as null.
	 *
	 * @return XapiStatementIngest
	 */
	private function ingest(): XapiStatementIngest {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null): ObjectEntity {
				$row = (array)$object;
				if (isset($this->rows[$uuid]) === true) {
					throw new RuntimeException('SCHEMA_APPEND_ONLY: Schema "xapi-statement" is append-only; update operations are not permitted.');
				}

				$this->saves[]     = (string)$uuid;
				$this->rows[$uuid] = $row + ['result' => null, 'context' => null, 'timestamp' => null, 'authority' => null];
				return OrEntityFactory::make($this->rows[$uuid], 'xapi-statement');
			}
		);
		$objects->method('find')->willReturnCallback(
			function (int|string $id): ObjectEntity {
				if (isset($this->rows[$id]) === false) {
					throw new DoesNotExistException('Object not found in magic table');
				}

				return OrEntityFactory::make($this->rows[$id], 'xapi-statement');
			}
		);

		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturn('tenant-a');

		return new XapiStatementIngest(objectService: $objects, tenants: new CallerTenantResolver($config, $this->createMock(ObjectService::class)));
	}//end ingest()

	/**
	 * A statement with the fixed id.
	 *
	 * @param array<string, mixed> $overrides Members to replace.
	 *
	 * @return array<string, mixed>
	 */
	private static function statement(array $overrides = []): array {
		return array_replace(
			[
				'id'        => self::ID,
				'actor'     => ['objectType' => 'Agent', 'account' => ['homePage' => 'https://school.example/', 'name' => 'pupil1']],
				'verb'      => ['id' => 'http://adlnet.gov/expapi/verbs/completed', 'display' => ['en-US' => 'completed']],
				'object'    => ['objectType' => 'Activity', 'id' => 'https://school.example/a', 'definition' => ['name' => ['en-US' => 'Lesson 1']]],
				'context'   => ['registration' => '0f8e7a2c-1b3d-4c5e-9f60-718293a4b5c6'],
				'timestamp' => '2026-09-29T21:07:14.123Z',
			],
			$overrides
		);
	}//end statement()

	/**
	 * Re-sending an identical statement answers its id and stores nothing new.
	 *
	 * @return void
	 */
	public function testIdenticalResendIsANoOp(): void {
		$ingest = $this->ingest();
		self::assertSame([self::ID], $ingest->ingest(statements: [self::statement()], actorId: 'pupil1'));
		self::assertSame([self::ID], $ingest->ingest(statements: [self::statement()], actorId: 'pupil1'));
		self::assertSame([self::ID], $this->saves, 'stored once');
	}//end testIdenticalResendIsANoOp()

	/**
	 * Differences the specification allows do not make a conflict.
	 *
	 * @return void
	 */
	public function testAllowedDifferencesStillMatch(): void {
		$ingest = $this->ingest();
		$ingest->ingest(statements: [self::statement()], actorId: 'pupil1');

		$resent = self::statement(
			[
				'id'        => strtoupper(self::ID),
				'object'    => ['id' => 'https://school.example/a', 'objectType' => 'Activity', 'definition' => ['name' => ['en-US' => 'Renamed']]],
				'context'   => ['registration' => strtoupper('0f8e7a2c-1b3d-4c5e-9f60-718293a4b5c6')],
				'timestamp' => '2026-09-29T23:07:14.123+02:00',
				'authority' => ['objectType' => 'Agent', 'account' => ['homePage' => 'https://x/', 'name' => 'lrs']],
				'stored'    => '2030-01-01T00:00:00Z',
				'version'   => '1.0.3',
			]
		);

		self::assertSame([self::ID], $ingest->ingest(statements: [$resent], actorId: 'pupil1'));
		self::assertCount(1, $this->saves);
	}//end testAllowedDifferencesStillMatch()

	/**
	 * A different statement with a known id is a 409 and stores nothing.
	 *
	 * @return void
	 */
	public function testDifferentStatementWithAKnownIdIsAConflict(): void {
		$ingest = $this->ingest();
		$ingest->ingest(statements: [self::statement()], actorId: 'pupil1');

		$cases = [
			'another verb'   => self::statement(['verb' => ['id' => 'http://adlnet.gov/expapi/verbs/failed']]),
			'another result' => self::statement(['result' => ['score' => ['scaled' => 0.4]]]),
			'another time'   => self::statement(['timestamp' => '2026-09-29T21:07:15.123Z']),
		];
		foreach ($cases as $label => $statement) {
			try {
				$ingest->ingest(statements: [$statement], actorId: 'pupil1');
				self::fail($label . ': no conflict');
			} catch (XapiRequestException $e) {
				self::assertSame(409, $e->getStatus(), $label);
			}
		}

		self::assertCount(1, $this->saves);
	}//end testDifferentStatementWithAKnownIdIsAConflict()

	/**
	 * An id held by another learner's statement is a conflict, even for an identical body.
	 *
	 * @return void
	 */
	public function testAnotherLearnersIdIsAConflict(): void {
		$ingest = $this->ingest();
		$ingest->ingest(statements: [self::statement()], actorId: 'pupil1');

		$this->expectException(XapiRequestException::class);
		$ingest->ingest(statements: [self::statement()], actorId: 'pupil2');
	}//end testAnotherLearnersIdIsAConflict()

	/**
	 * A batch of a known identical statement and a new one stores only the new one and answers both ids.
	 *
	 * @return void
	 */
	public function testMixedBatchStoresOnlyTheNewStatement(): void {
		$ingest = $this->ingest();
		$ingest->ingest(statements: [self::statement()], actorId: 'pupil1');

		$new = self::statement(['id' => '11111111-2222-4333-8444-555555555555']);
		$ids = $ingest->ingest(statements: [self::statement(), $new], actorId: 'pupil1');

		self::assertSame([self::ID, '11111111-2222-4333-8444-555555555555'], $ids);
		self::assertSame([self::ID, '11111111-2222-4333-8444-555555555555'], $this->saves);
	}//end testMixedBatchStoresOnlyTheNewStatement()

	/**
	 * A batch with one conflict is refused whole: the new statement in it is not stored either.
	 *
	 * @return void
	 */
	public function testBatchWithAConflictStoresNothing(): void {
		$ingest = $this->ingest();
		$ingest->ingest(statements: [self::statement()], actorId: 'pupil1');

		$new      = self::statement(['id' => '11111111-2222-4333-8444-555555555555']);
		$conflict = self::statement(['verb' => ['id' => 'http://adlnet.gov/expapi/verbs/failed']]);
		try {
			$ingest->ingest(statements: [$new, $conflict], actorId: 'pupil1');
			self::fail('no conflict');
		} catch (XapiRequestException $e) {
			self::assertSame(409, $e->getStatus());
		}

		self::assertSame([self::ID], $this->saves);
	}//end testBatchWithAConflictStoresNothing()

	/**
	 * The same id twice in one batch is a malformed request.
	 *
	 * @return void
	 */
	public function testDuplicateIdInOneBatchIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->ingest()->ingest(statements: [self::statement(), self::statement()], actorId: 'pupil1');
	}//end testDuplicateIdInOneBatchIsRefused()
}//end class
