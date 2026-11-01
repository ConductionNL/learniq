<?php

/**
 * Learniq DeliberationRecordLearnerStamp and its backfill unit tests.
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
 * @spec openspec/changes/dossier-timeline-deliberations/specs/pupil-dossier/spec.md#requirement-a-deliberation-record-names-its-pupil
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\AppInfo\Registrar\EventListenerWiring;
use OCA\Learniq\Listener\DeliberationRecordLearnerStamp;
use OCA\Learniq\Repair\BackfillDeliberationRecordLearner;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for the pupil of a deliberation record.
 */
class DeliberationRecordLearnerStampTest extends TestCase {

	/**
	 * Records saved by the backfill.
	 *
	 * @var list<array{0: array<string, mixed>, 1: string}>
	 */
	private array $saved = [];

	/**
	 * Object service over one support request `sr-1` about `pupil-1`, and the given records.
	 *
	 * @param list<array<string, mixed>> $records Deliberation records.
	 *
	 * @return ObjectService
	 */
	private function objects(array $records = []): ObjectService {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			static fn (string $id) => ($id === 'sr-1' ? OrEntityFactory::make(['id' => 'sr-1', 'learnerId' => 'pupil-1'], 'support-request') : null)
		);
		$objects->method('findAll')->willReturn($records);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null) {
				$this->saved[] = [$object, (string)$uuid];
				return OrEntityFactory::make($object, 'deliberation-record');
			}
		);

		return $objects;
	}//end objects()

	/**
	 * The stamp over the object service.
	 *
	 * @param ObjectService $objects The object service.
	 *
	 * @return DeliberationRecordLearnerStamp
	 */
	private function stamp(ObjectService $objects): DeliberationRecordLearnerStamp {
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('guardSchemaSlug')->willReturn('deliberation-record');

		return new DeliberationRecordLearnerStamp(schemaResolver: $resolver, objectService: $objects);
	}//end stamp()

	/**
	 * The pupil comes from the support request; a client value is replaced.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dossier-timeline-deliberations/specs/pupil-dossier/spec.md#requirement-a-deliberation-record-names-its-pupil
	 */
	public function testThePupilComesFromTheSupportRequest(): void {
		$event = new ObjectCreatingEvent(OrEntityFactory::make(['supportRequestId' => 'sr-1', 'learnerId' => 'someone-else'], 'deliberation-record'));
		$this->stamp(objects: $this->objects())->handle($event);
		self::assertSame('pupil-1', $event->getModifiedData()['learnerId']);

		$none = new ObjectCreatingEvent(OrEntityFactory::make(['supportRequestId' => 'sr-9', 'learnerId' => 'someone-else'], 'deliberation-record'));
		$this->stamp(objects: $this->objects())->handle($none);
		self::assertNull($none->getModifiedData()['learnerId']);
	}//end testThePupilComesFromTheSupportRequest()

	/**
	 * The backfill stamps only records without a pupil whose request names one.
	 *
	 * @return void
	 */
	public function testTheBackfillStampsOldRecords(): void {
		$objects = $this->objects(records: [
			['id' => 'd1', 'supportRequestId' => 'sr-1', 'outcome' => 'extra help'],
			['id' => 'd2', 'supportRequestId' => 'sr-1', 'learnerId' => 'pupil-1'],
			['id' => 'd3', 'supportRequestId' => 'sr-9'],
		]);
		(new BackfillDeliberationRecordLearner(objectService: $objects, stamp: $this->stamp(objects: $objects), logger: new NullLogger()))->run($this->createMock(IOutput::class));

		self::assertCount(1, $this->saved);
		self::assertSame('d1', $this->saved[0][1]);
		self::assertSame('pupil-1', $this->saved[0][0]['learnerId']);
		self::assertSame('extra help', $this->saved[0][0]['outcome']);
	}//end testTheBackfillStampsOldRecords()

	/**
	 * The stamp is wired on create and update.
	 *
	 * @return void
	 */
	public function testTheStampIsRegistered(): void {
		$registered = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$registered): void {
				$registered[] = $listener;
			}
		);

		(new EventListenerWiring())->registerAll(context: $context);

		self::assertSame(2, count(array_keys($registered, DeliberationRecordLearnerStamp::class, true)));
	}//end testTheStampIsRegistered()
}//end class
