<?php

/**
 * Learniq ElectiveOfferCopiesStamp unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/portal-contribution/spec.md#requirement-a-pupil-reads-her-schools-open-support-lessons-and-signs-herself-up
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\Kernel\Contracts\ElectiveServiceInterface;
use OCA\Learniq\Listener\ElectiveOfferCopiesStamp;
use OCA\Learniq\Service\ElectiveOfferCopies;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * An offer write carries fresh copies; a sign-up write refreshes its offer.
 */
#[CoversClass(ElectiveOfferCopiesStamp::class)]
class ElectiveOfferCopiesStampTest extends TestCase {

	private const COPIES = [
		'nextLessonStartsAt' => '2026-10-06T15:20:00+02:00',
		'nextLessonEndsAt' => '2026-10-06T16:10:00+02:00',
		'nextLessonLocation' => '2.14',
		'placesLeft' => 6,
		'placesLeftLine' => '6 places left',
	];

	/**
	 * The saves the listener made.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * The offers the copies service was asked about.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $asked = [];

	/**
	 * The listener.
	 *
	 * @param array<string, mixed>|null $stored The stored offer.
	 * @param bool                      $differ Whether the stored copies differ.
	 * @param bool                      $throws Whether the save throws.
	 *
	 * @return ElectiveOfferCopiesStamp
	 */
	private function stamp(?array $stored=null, bool $differ=true, bool $throws=false): ElectiveOfferCopiesStamp {
		$resolver = $this->createMock(originalClassName: ListenerSchemaResolver::class);
		$resolver->method('guardSchemaSlug')->willReturnCallback(static fn ($entity) => $entity->getSchema());
		$electives = $this->createMock(originalClassName: ElectiveServiceInterface::class);
		$electives->method('offer')->willReturnCallback(static fn (string $id): ?array => ['offer-1' => $stored][$id] ?? null);
		$copies = $this->createMock(originalClassName: ElectiveOfferCopies::class);
		$copies->method('copies')->willReturnCallback(
			function (array $offer): array {
				$this->asked[] = $offer;
				return self::COPIES;
			}
		);
		$copies->method('differ')->willReturn($differ);
		$objects = $this->createMock(originalClassName: ObjectService::class);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ObjectSaveOptions $options = new ObjectSaveOptions(), bool $_rbac = true) use ($throws) {
				$register = $options->target->register;
				$schema = $options->target->schema;
				$uuid = $options->uuid;
				if ($throws === true) {
					throw new RuntimeException('locked');
				}

				$this->saved[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid, '_rbac' => $_rbac];
				return null;
			}
		);

		return new ElectiveOfferCopiesStamp(schemas: $resolver, electives: $electives, copies: $copies, objects: $objects, logger: new NullLogger());
	}//end stamp()

	/**
	 * A create and an update of an offer get the copies in the same write,
	 * computed from the offer as written, with its own uuid.
	 *
	 * @return void
	 */
	public function testAnOfferWriteCarriesTheCopies(): void {
		$create = new ObjectCreatingEvent(
			OrEntityFactory::make(['name' => 'Steunles', 'capacityPerLesson' => 12], 'elective-offer', uuid: 'offer-1')
		);
		$this->stamp()->handle($create);
		self::assertSame(expected: self::COPIES, actual: $create->getModifiedData());
		self::assertSame(expected: 'offer-1', actual: $this->asked[0]['id']);
		self::assertSame(expected: 12, actual: $this->asked[0]['capacityPerLesson']);

		$old = OrEntityFactory::make(['capacityPerLesson' => 12], 'elective-offer', uuid: 'offer-1');
		$update = new ObjectUpdatingEvent(OrEntityFactory::make(['capacityPerLesson' => 14], 'elective-offer', uuid: 'offer-1'), $old);
		$this->stamp()->handle($update);
		self::assertSame(expected: self::COPIES, actual: $update->getModifiedData());
		self::assertSame(expected: 14, actual: $this->asked[1]['capacityPerLesson'], message: 'the copies follow the new capacity');

		$other = new ObjectCreatingEvent(OrEntityFactory::make(['name' => 'x'], 'session'));
		$this->stamp()->handle($other);
		self::assertSame(expected: [], actual: $other->getModifiedData());

		$stopped = new ObjectCreatingEvent(OrEntityFactory::make(['name' => 'x'], 'elective-offer'));
		$stopped->stopPropagation();
		$this->stamp()->handle($stopped);
		self::assertSame(expected: [], actual: $stopped->getModifiedData(), message: 'a refused write is left alone');
	}//end testAnOfferWriteCarriesTheCopies()

	/**
	 * A saved sign-up saves its offer again, without RBAC, so its updating event stamps fresh copies;
	 * an unchanged offer, an unknown offer or a sign-up without one is not saved.
	 *
	 * @return void
	 */
	public function testASignUpRefreshesItsOffer(): void {
		$stored = ['id' => 'offer-1', 'name' => 'Steunles', 'placesLeft' => 7];
		$signUp = OrEntityFactory::make(['offerId' => 'offer-1', 'status' => 'signed-up'], 'elective-sign-up');
		$this->stamp(stored: $stored)->handle(new ObjectCreatedEvent($signUp));

		self::assertCount(expectedCount: 1, haystack: $this->saved);
		// Saved as stored: the read-only copies are written by the save's own updating event.
		self::assertSame(expected: $stored, actual: $this->saved[0]['object']);
		self::assertSame(expected: 'offer-1', actual: $this->saved[0]['uuid']);
		self::assertSame(expected: 'elective-offer', actual: $this->saved[0]['schema']);
		self::assertFalse(condition: $this->saved[0]['_rbac']);

		$withdrawn = OrEntityFactory::make(['offerId' => 'offer-1', 'status' => 'withdrawn'], 'elective-sign-up');
		$this->stamp(stored: $stored)->handle(new ObjectUpdatedEvent($withdrawn, $signUp));
		self::assertCount(expectedCount: 2, haystack: $this->saved, message: 'a withdrawal refreshes the offer too');

		$this->stamp(stored: $stored, differ: false)->handle(new ObjectCreatedEvent($signUp));
		$this->stamp(stored: null)->handle(new ObjectCreatedEvent($signUp));
		$this->stamp(stored: $stored)->handle(new ObjectCreatedEvent(OrEntityFactory::make(['status' => 'signed-up'], 'elective-sign-up')));
		$this->stamp(stored: $stored)->handle(new ObjectCreatedEvent(OrEntityFactory::make(['offerId' => 'offer-1'], 'session')));
		self::assertCount(expectedCount: 2, haystack: $this->saved);
	}//end testASignUpRefreshesItsOffer()

	/**
	 * A failing save is logged, never thrown into the sign-up it follows.
	 *
	 * @return void
	 */
	public function testAFailingRefreshNeverBlocksTheSignUp(): void {
		$signUp = OrEntityFactory::make(['offerId' => 'offer-1'], 'elective-sign-up');
		$this->stamp(stored: ['id' => 'offer-1'], throws: true)->handle(new ObjectCreatedEvent($signUp));

		self::assertSame(expected: [], actual: $this->saved);
	}//end testAFailingRefreshNeverBlocksTheSignUp()
}//end class
