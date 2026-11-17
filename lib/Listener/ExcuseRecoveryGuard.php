<?php

/**
 * Learniq Excuse Recovery Guard
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * An illness may be reported without a last day ("ziek, nog niet beter").
 * The guardian, or a pupil who reports for herself, ends it later with
 * `reportRecovered`, the portal action that sets `dateTo`. That last day
 * must not be before the first day; that holds for every write that moves
 * the last day, staff corrections included. A portal write (portaliq writes
 * without a Nextcloud session) may only end an open report: a guardian or
 * pupil cannot move the last day of a report that already ends. Staff keep
 * correcting it as before.
 *
 * @category Listener
 * @package  OCA\Learniq\Listener
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/absence-reports-seen-and-ended/tasks.md#T4
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Refuses a new last day before the first day of an absence report, and a
 * portal write that moves the last day of a report that already ends.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/absence-reports-seen-and-ended/tasks.md#T4
 */
class ExcuseRecoveryGuard implements IEventListener {

	private const EXCUSE_SCHEMA = 'excuse-request';

	public const REFUSALS = [
		'excuse-recovery-before-start' => 'The last day cannot be before the first day of the absence.',
		'excuse-recovery-not-open' => 'This absence report already has a last day. Ask the school to change it.',
	];

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolverInterface $schemaResolver Resolves the written object's schema.
	 * @param LoggerInterface                 $logger         Logs a refusal.
	 * @param IUserSession                    $userSession    Tells a staff write from a portal write.
	 */
	public function __construct(
		private readonly ListenerSchemaResolverInterface $schemaResolver,
		private readonly LoggerInterface $logger,
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * Check an update that sets the last day of an absence report.
	 *
	 * @param Event $event The write event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/absence-reports-seen-and-ended/tasks.md#T4
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectUpdatingEvent === false || $event->isPropagationStopped() === true) {
			return;
		}

		try {
			if ($this->schemaResolver->guardSchemaSlug(entity: $event->getNewObject()) !== self::EXCUSE_SCHEMA) {
				return;
			}
		} catch (Throwable $exception) {
			return;
		}

		$old = ($event->getOldObject()?->getObject() ?? []);
		$new = array_merge(($event->getNewObject()->getObject() ?? []), $event->getModifiedData());
		$oldLast = $this->day(value: ($old['dateTo'] ?? null));
		$newLast = $this->day(value: ($new['dateTo'] ?? null));
		if ($newLast === '' || $newLast === $oldLast) {
			return;
		}

		$reason = $this->refusalFor(oldLast: $oldLast, newLast: $newLast, first: $this->day(value: ($new['dateFrom'] ?? null)));
		if ($reason === null) {
			return;
		}

		$event->setErrors(['reason' => $reason, 'message' => self::REFUSALS[$reason]]);
		$event->stopPropagation();
		$this->logger->info('[ExcuseRecoveryGuard] Refused ending an absence report: {reason}', ['reason' => $reason]);
	}//end handle()

	/**
	 * Why a new last day is refused, or null when it may be written.
	 *
	 * @param string $oldLast The stored last day, '' when the report was open.
	 * @param string $newLast The new last day.
	 * @param string $first   The first day.
	 *
	 * @return string|null
	 */
	private function refusalFor(string $oldLast, string $newLast, string $first): ?string {
		if ($first !== '' && $newLast < $first) {
			return 'excuse-recovery-before-start';
		}

		if ($oldLast !== '' && $this->userSession->getUser() === null) {
			return 'excuse-recovery-not-open';
		}

		return null;
	}//end refusalFor()

	/**
	 * A date value as YYYY-MM-DD, or '' when empty.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private function day(mixed $value): string {
		if (is_string($value) === false) {
			return '';
		}

		return substr(trim($value), 0, 10);
	}//end day()
}//end class
