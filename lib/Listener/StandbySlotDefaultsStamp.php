<?php

/**
 * Learniq Standby Slot Defaults Stamp
 *
 * A standby slot planned without validity dates is valid for the school year
 * it is planned in, as the school sets that year (SchoolYear), and one
 * without a tenant belongs to the planner's (CallerTenantResolver). Values
 * the client sends are kept.
 *
 * @category Listener
 * @package  OCA\Learniq\Listener
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
 * @spec openspec/changes/school-year-setting/specs/school-structure/spec.md#requirement-the-school-year-starts-on-the-day-the-school-sets
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use DateTimeImmutable;
use OCA\Learniq\Service\Calendar\SchoolYear;
use OCA\Learniq\Service\CallerTenantResolver;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Throwable;

/**
 * Fills a new standby slot's missing validity and tenant.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/school-year-setting/specs/school-structure/spec.md#requirement-the-school-year-starts-on-the-day-the-school-sets
 */
class StandbySlotDefaultsStamp implements IEventListener {

	private const SCHEMA = 'standby-slot';

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolver $schemaResolver Entity schema id to slug.
	 * @param SchoolYear             $schoolYear     The school year as the school sets it.
	 * @param ITimeFactory           $timeFactory    The server's clock.
	 * @param IUserSession           $userSession    The planner.
	 * @param CallerTenantResolver   $tenants        The planner's tenant.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly SchoolYear $schoolYear,
		private readonly ITimeFactory $timeFactory,
		private readonly IUserSession $userSession,
		private readonly CallerTenantResolver $tenants,
	) {
	}//end __construct()

	/**
	 * Fill `validFrom`, `validUntil` and `tenant_id` when a new slot has none.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/school-year-setting/specs/school-structure/spec.md#requirement-the-school-year-starts-on-the-day-the-school-sets
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === false || $event->isPropagationStopped() === true) {
			return;
		}

		try {
			if ($this->schemaResolver->guardSchemaSlug(entity: $event->getObject()) !== self::SCHEMA) {
				return;
			}
		} catch (Throwable $exception) {
			return;
		}

		$payload = array_merge(($event->getObject()->getObject() ?? []), $event->getModifiedData());
		$stamp = array_merge($this->validity(payload: $payload), $this->tenant(payload: $payload));
		if ($stamp !== []) {
			$event->setModifiedData(array_merge($event->getModifiedData(), $stamp));
		}
	}//end handle()

	/**
	 * The current school year as `validFrom` / `validUntil`, for the ones missing.
	 *
	 * @param array<string, mixed> $payload The slot being created.
	 *
	 * @return array<string, string>
	 */
	private function validity(array $payload): array {
		$today = (new DateTimeImmutable())->setTimestamp($this->timeFactory->getTime())->format('Y-m-d');
		[$from, $until] = $this->schoolYear->around(date: $today);

		$stamp = [];
		if (self::empty(value: ($payload['validFrom'] ?? null)) === true) {
			$stamp['validFrom'] = $from;
		}

		if (self::empty(value: ($payload['validUntil'] ?? null)) === true) {
			$stamp['validUntil'] = $until;
		}

		return $stamp;
	}//end validity()

	/**
	 * The planner's tenant, when the slot names none.
	 *
	 * @param array<string, mixed> $payload The slot being created.
	 *
	 * @return array<string, string>
	 */
	private function tenant(array $payload): array {
		$user = $this->userSession->getUser();
		if (self::empty(value: ($payload['tenant_id'] ?? null)) === false || $user === null) {
			return [];
		}

		return ['tenant_id' => $this->tenants->resolve(user: $user)];
	}//end tenant()

	/**
	 * Whether a value is missing.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private static function empty(mixed $value): bool {
		return is_string($value) === false || trim($value) === '';
	}//end empty()
}//end class
