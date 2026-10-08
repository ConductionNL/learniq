<?php

/**
 * Learniq example set dates
 *
 * Keeps an example set in the week it was loaded (demo-dates-follow-the-load-week):
 * remembers, per set, the offset its dates were moved by, and moves the set's
 * objects that already exist when a load runs in another week. OpenRegister's
 * seed import skips an object that exists, so without this step a reload a
 * week later would leave last week's lessons in place.
 *
 * @category Service
 * @package  OCA\Learniq\Service
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
 * @spec openspec/changes/demo-dates-follow-the-load-week/specs/example-sets/spec.md#requirement-a-reload-in-another-week-moves-what-exists-and-a-reload-in-the-same-week-writes-nothing
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\Learniq\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The offset of each loaded set, and the move of its existing objects.
 *
 * @spec openspec/changes/demo-dates-follow-the-load-week/specs/example-sets/spec.md#requirement-a-reload-in-another-week-moves-what-exists-and-a-reload-in-the-same-week-writes-nothing
 */
class ExampleSetDates {

	/**
	 * The app config key, per set, of the offset its dates carry.
	 */
	private const KEY_PREFIX = 'example_set_date_offset_';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig         $appConfig  Remembers the offset per set.
	 * @param ITimeFactory       $time       Today.
	 * @param LoadedExampleSets  $loadedSets Whether a set was loaded before offsets were kept.
	 * @param ContainerInterface $container  Resolves OpenRegister's object service.
	 * @param LoggerInterface    $logger     Records what moved.
	 * @param DemoDates          $demoDates  Moves the dates.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ITimeFactory $time,
		private readonly LoadedExampleSets $loadedSets,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly DemoDates $demoDates=new DemoDates(),
	) {
	}//end __construct()

	/**
	 * The offset for a load that runs today.
	 *
	 * @return int Days, a multiple of 7.
	 *
	 * @spec openspec/changes/demo-dates-follow-the-load-week/specs/example-sets/spec.md#requirement-a-load-moves-every-date-to-the-week-it-runs-in
	 */
	public function currentOffset(): int {
		return $this->demoDates->offsetFor(today: $this->time->now());
	}//end currentOffset()

	/**
	 * The offset the set's dates carry now: the remembered one; 0 for a set
	 * loaded before offsets were kept (its dates are the boards' own); null
	 * for a set never loaded.
	 *
	 * @param string $setId The set.
	 *
	 * @return int|null
	 *
	 * @spec openspec/changes/demo-dates-follow-the-load-week/specs/example-sets/spec.md#requirement-a-reload-in-another-week-moves-what-exists-and-a-reload-in-the-same-week-writes-nothing
	 */
	public function appliedOffset(string $setId): ?int {
		$stored = $this->appConfig->getValueString(Application::APP_ID, self::KEY_PREFIX . $setId, '');
		if (preg_match('/^-?\d+$/', $stored) === 1) {
			return (int)$stored;
		}

		if (in_array($setId, array_column($this->loadedSets->all(), 'id'), true) === true) {
			return 0;
		}

		return null;
	}//end appliedOffset()

	/**
	 * A set ready to import at this week's offset: what exists from a load
	 * in another week has moved, the offset is remembered, and the descriptor
	 * carries this week's dates.
	 *
	 * @param string                           $setId   The set.
	 * @param array<string, mixed>             $data    The shipped descriptor.
	 * @param array<int, array<string, mixed>> $objects Its objects.
	 *
	 * @return array{data: array<string, mixed>, offset: int, previous: int|null}
	 *
	 * @spec openspec/changes/demo-dates-follow-the-load-week/specs/example-sets/spec.md#requirement-a-reload-in-another-week-moves-what-exists-and-a-reload-in-the-same-week-writes-nothing
	 */
	public function prepare(string $setId, array $data, array $objects): array {
		$offset   = $this->currentOffset();
		$previous = $this->appliedOffset(setId: $setId);
		if ($previous !== null && $previous !== $offset) {
			$this->moveExisting(objects: $objects, days: $offset - $previous);
		}

		// What exists carries this offset now, and what the import adds will too.
		$this->remember(setId: $setId, offset: $offset);

		return ['data' => (array)$this->demoDates->shift(value: $data, days: $offset), 'offset' => $offset, 'previous' => $previous];
	}//end prepare()

	/**
	 * Remember the offset the set's dates now carry.
	 *
	 * @param string $setId  The set.
	 * @param int    $offset The offset.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/demo-dates-follow-the-load-week/specs/example-sets/spec.md#requirement-a-reload-in-another-week-moves-what-exists-and-a-reload-in-the-same-week-writes-nothing
	 */
	public function remember(string $setId, int $offset): void {
		$this->appConfig->setValueString(Application::APP_ID, self::KEY_PREFIX . $setId, (string)$offset);
	}//end remember()

	/**
	 * Forget a removed set's offset.
	 *
	 * @param string $setId The set.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/demo-dates-follow-the-load-week/specs/example-sets/spec.md#requirement-a-reload-in-another-week-moves-what-exists-and-a-reload-in-the-same-week-writes-nothing
	 */
	public function forget(string $setId): void {
		$this->appConfig->deleteKey(Application::APP_ID, self::KEY_PREFIX . $setId);
	}//end forget()

	/**
	 * Move the set's objects that exist by `$days`. An object somebody
	 * removed is skipped; an object without a date is not written.
	 *
	 * @param array<int, array<string, mixed>> $objects The set's descriptor objects (their uuid and `@self`).
	 * @param int                              $days    The days to move.
	 *
	 * @return array{moved: int, unchanged: int, missing: int, failed: int}
	 *
	 * @spec openspec/changes/demo-dates-follow-the-load-week/specs/example-sets/spec.md#requirement-a-reload-in-another-week-moves-what-exists-and-a-reload-in-the-same-week-writes-nothing
	 */
	public function moveExisting(array $objects, int $days): array {
		$counts = ['moved' => 0, 'unchanged' => 0, 'missing' => 0, 'failed' => 0];
		if ($days === 0 || $objects === []) {
			return $counts;
		}

		$service = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		foreach ($objects as $object) {
			$outcome = $this->moveOne(service: $service, object: $object, days: $days);
			$counts[$outcome]++;
		}

		$this->logger->info(
			'[ExampleSetDates] moved existing example objects by {days} days: {moved} moved, {unchanged} without dates, {missing} missing, {failed} failed.',
			['days' => $days] + $counts
		);

		return $counts;
	}//end moveExisting()

	/**
	 * Move one object.
	 *
	 * @param object               $service OpenRegister's object service.
	 * @param array<string, mixed> $object  The descriptor object.
	 * @param int                  $days    The days.
	 *
	 * @return string `moved`, `unchanged`, `missing` or `failed`.
	 */
	private function moveOne(object $service, array $object, int $days): string {
		$uuid     = (string)($object['uuid'] ?? '');
		$register = (string)($object['@self']['register'] ?? '');
		$schema   = (string)($object['@self']['schema'] ?? '');
		if ($uuid === '' || $register === '' || $schema === '') {
			return 'missing';
		}

		try {
			$entity = $service->find(
				id: $uuid,
				register: $register,
				schema: $schema,
				_rbac: false,
				_multitenancy: false,
				_render: false,
				_audit: false
			);
			if ($entity === null) {
				return 'missing';
			}

			$data  = (array)$entity->getObject();
			$moved = $this->demoDates->shift(value: $data, days: $days);
			if ($moved === $data) {
				return 'unchanged';
			}

			$service->saveObject(
				object: $moved,
				register: $register,
				schema: $schema,
				uuid: $uuid,
				_rbac: false,
				_multitenancy: false,
				silent: true,
				_validation: false
			);
			return 'moved';
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[ExampleSetDates] could not move {uuid}: {msg}',
				['uuid' => $uuid, 'msg' => $exception->getMessage()]
			);
			return 'failed';
		}//end try
	}//end moveOne()
}//end class
