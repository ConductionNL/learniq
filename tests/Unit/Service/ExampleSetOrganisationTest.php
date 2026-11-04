<?php

/**
 * Learniq ExampleSetOrganisation unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-example-set-rows-carry-the-instances-organisation
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\ExampleSetOrganisation;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Resolving the instance's default organisation and giving it to a set's rows.
 *
 * @covers \OCA\Learniq\Service\ExampleSetOrganisation
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 */
class ExampleSetOrganisationTest extends TestCase {

	/**
	 * The default organisation's uuid is the answer.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#scenario-a-loaded-row-carries-the-instances-organisation
	 */
	public function testResolveAnswersTheDefaultOrganisation(): void {
		$service = new ExampleSetOrganisation($this->container(['OCA\OpenRegister\Service\OrganisationService' => $this->organisations('org-instance')]), $this->createMock(LoggerInterface::class));

		self::assertSame('org-instance', $service->resolve());
	}//end testResolveAnswersTheDefaultOrganisation()

	/**
	 * An organisation service that throws, or answers without a uuid, is refused by name.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#scenario-no-default-organisation-stops-the-load
	 */
	public function testResolveRefusesWhenNoOrganisationResolves(): void {
		foreach ([null, ''] as $uuid) {
			$service = new ExampleSetOrganisation($this->container(['OCA\OpenRegister\Service\OrganisationService' => $this->organisations($uuid)]), $this->createMock(LoggerInterface::class));
			try {
				$service->resolve();
				$message = null;
			} catch (RuntimeException $exception) {
				$message = $exception->getMessage();
			}

			self::assertNotNull($message, 'resolved without an organisation');
			self::assertStringContainsString('default organisation', (string)$message);
		}
	}//end testResolveRefusesWhenNoOrganisationResolves()

	/**
	 * A row without an organisation gets it, a row with one keeps it, a row
	 * that is not there is counted missing, and a write that fails stops the
	 * load once every row was tried.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#scenario-a-loaded-row-carries-the-instances-organisation
	 */
	public function testStampGivesOnlyRowsWithoutAnOrganisation(): void {
		$bare  = $this->row('ee000001-0000-4000-8000-000000000001', null);
		$owned = $this->row('ee000002-0000-4000-8000-000000000001', 'org-elsewhere');
		$stuck = $this->row('ee000004-0000-4000-8000-000000000001', null);
		$rows  = [$bare->getUuid() => $bare, $owned->getUuid() => $owned, $stuck->getUuid() => $stuck];

		$finder = new class ($rows) {
			/**
			 * Constructor.
			 *
			 * @param array<string, ObjectEntity> $rows The stored rows.
			 */
			public function __construct(private array $rows) {
			}

			/**
			 * The stored row, or an exception.
			 *
			 * @param int|string $id            Uuid.
			 * @param mixed      $register      Register.
			 * @param mixed      $schema        Schema.
			 * @param bool       $_rbac         Rbac.
			 * @param bool       $_multitenancy Multitenancy.
			 * @param bool       $_render       Render.
			 * @param bool       $_audit        Audit.
			 *
			 * @return ObjectEntity
			 */
			public function find(int|string $id, mixed $register=null, mixed $schema=null, bool $_rbac=true, bool $_multitenancy=true, bool $_render=true, bool $_audit=true): ObjectEntity {
				return $this->rows[(string)$id] ?? throw new \Exception('Object not found');
			}//end find()
		};
		$mapper = new class {
			/**
			 * The uuids written.
			 *
			 * @var array<int, string>
			 */
			public array $written = [];

			/**
			 * Write the entity, refusing one uuid.
			 *
			 * @param ObjectEntity $entity The entity.
			 *
			 * @return ObjectEntity
			 */
			public function update(ObjectEntity $entity): ObjectEntity {
				if ($entity->getUuid() === 'ee000004-0000-4000-8000-000000000001') {
					throw new \Exception('database gone');
				}

				$this->written[] = (string)$entity->getUuid();
				return $entity;
			}//end update()
		};

		$service = new ExampleSetOrganisation(
			$this->container(['OCA\OpenRegister\Service\ObjectService' => $finder, 'OCA\OpenRegister\Db\MagicMapper' => $mapper]),
			$this->createMock(LoggerInterface::class)
		);
		$self    = ['register' => 'learniq', 'schema' => 'school'];
		$listed  = [
			['uuid' => 'ee000001-0000-4000-8000-000000000001', '@self' => $self],
			['uuid' => 'ee000002-0000-4000-8000-000000000001', '@self' => $self],
			['uuid' => 'ee000003-0000-4000-8000-000000000001', '@self' => $self],
			['@self' => $self],
		];

		self::assertSame(['given' => 1, 'kept' => 1, 'missing' => 2], $service->stamp($listed, 'org-instance'));
		self::assertSame('org-instance', $bare->getOrganisation());
		self::assertSame('org-elsewhere', $owned->getOrganisation());
		self::assertSame(['ee000001-0000-4000-8000-000000000001'], $mapper->written);

		$bare->setOrganisation(null);
		$mapper->written = [];
		$stuckRow        = ['uuid' => 'ee000004-0000-4000-8000-000000000001', '@self' => $self];
		$message         = null;
		try {
			$service->stamp([$stuckRow, $listed[0]], 'org-instance');
		} catch (RuntimeException $exception) {
			$message = $exception->getMessage();
		}

		self::assertSame('1 example row(s) could not be given organisation org-instance; load the set again.', $message);
		self::assertSame(['ee000001-0000-4000-8000-000000000001'], $mapper->written, 'the rows after the failed one were still given it');
		self::assertNull($stuck->getOrganisation() === 'org-instance' ? null : null);
	}//end testStampGivesOnlyRowsWithoutAnOrganisation()

	/**
	 * Nothing to stamp asks OpenRegister for nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#scenario-a-loaded-row-carries-the-instances-organisation
	 */
	public function testStampWithoutRowsTouchesNothing(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects(self::never())->method('get');

		$counts = (new ExampleSetOrganisation($container, $this->createMock(LoggerInterface::class)))->stamp([], 'org-instance');

		self::assertSame(['given' => 0, 'kept' => 0, 'missing' => 0], $counts);
	}//end testStampWithoutRowsTouchesNothing()

	/**
	 * A container that serves the given services by id.
	 *
	 * @param array<string, object> $services The services.
	 *
	 * @return ContainerInterface
	 */
	private function container(array $services): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id): object => $services[$id]);
		return $container;
	}//end container()

	/**
	 * An organisation service whose default organisation has the given uuid,
	 * or that throws when the uuid is null.
	 *
	 * @param string|null $uuid The uuid.
	 *
	 * @return object
	 */
	private function organisations(?string $uuid): object {
		return new class ($uuid) {
			/**
			 * Constructor.
			 *
			 * @param string|null $uuid The uuid.
			 */
			public function __construct(private ?string $uuid) {
			}

			/**
			 * The default organisation.
			 *
			 * @return object
			 */
			public function ensureDefaultOrganisation(): object {
				if ($this->uuid === null) {
					throw new \Exception('no organisation could be created');
				}

				$uuid = $this->uuid;
				return new class ($uuid) {
					/**
					 * Constructor.
					 *
					 * @param string $uuid The uuid.
					 */
					public function __construct(private string $uuid) {
					}

					/**
					 * The uuid.
					 *
					 * @return string
					 */
					public function getUuid(): string {
						return $this->uuid;
					}
				};
			}//end ensureDefaultOrganisation()
		};
	}//end organisations()

	/**
	 * A stored row.
	 *
	 * @param string      $uuid         The uuid.
	 * @param string|null $organisation Its organisation.
	 *
	 * @return ObjectEntity
	 */
	private function row(string $uuid, ?string $organisation): ObjectEntity {
		$row = new ObjectEntity();
		$row->setUuid($uuid);
		$row->setOrganisation($organisation);
		return $row;
	}//end row()
}//end class
