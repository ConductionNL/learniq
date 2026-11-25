<?php

/**
 * Learniq exam objection deadline stamp
 *
 * A new ExamObjection gets the moment it was submitted (when the creator gave
 * none), the exam regulation's term in working days (the app setting
 * `exam_objection_term_working_days`, ten by default) and the day the committee
 * must decide by, counted from the submission day. The deadline is always the
 * server's: a value the creator sent for `decideBy` or `termWorkingDays` is
 * replaced, so a deadline can not be moved by whoever files the objection.
 *
 * @category Listener
 * @package  OCA\Learniq\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-25-exam-committee-today-and-keuzedelen/tasks.md#task-T1
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use DateTimeImmutable;
use OCA\Learniq\Kernel\AppIdentity;
use OCA\Learniq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Learniq\Service\Exam\WorkingDays;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use Throwable;

/**
 * Stamps the decision deadline on a new exam objection.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/archive/2026-11-25-exam-committee-today-and-keuzedelen/tasks.md#task-T1
 */
class ExamObjectionDeadlineStamp implements IEventListener {

	/**
	 * The schema this stamp applies to.
	 *
	 * @var string
	 */
	public const SCHEMA = 'exam-objection';

	/**
	 * The app setting holding the term in working days.
	 *
	 * @var string
	 */
	public const TERM_KEY = 'exam_objection_term_working_days';

	/**
	 * The term when the setting is absent: ten working days.
	 *
	 * @var int
	 */
	public const DEFAULT_TERM = 10;

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolverInterface $schemaResolver Reads the schema of the object being created.
	 * @param IAppConfig                      $appConfig      Holds the exam regulation's term.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerSchemaResolverInterface $schemaResolver,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Stamp `submittedAt`, `termWorkingDays` and `decideBy` on a new objection.
	 *
	 * @param Event $event The creating event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-25-exam-committee-today-and-keuzedelen/tasks.md#task-T1
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

		$payload   = array_merge(($event->getObject()->getObject() ?? []), $event->getModifiedData());
		$submitted = $this->submittedAt(value: ($payload['submittedAt'] ?? null));
		$term      = $this->term();

		$event->setModifiedData(
			array_merge(
				$event->getModifiedData(),
				[
					'submittedAt' => $submitted->format(DATE_ATOM),
					'termWorkingDays' => $term,
					'decideBy' => WorkingDays::after(start: $submitted, days: $term)->format('Y-m-d'),
				]
			)
		);
	}//end handle()

	/**
	 * The submission moment the creator gave, or now.
	 *
	 * @param mixed $value The `submittedAt` the creator sent.
	 *
	 * @return DateTimeImmutable The submission moment.
	 */
	private function submittedAt(mixed $value): DateTimeImmutable {
		if (is_string($value) === true && $value !== '') {
			try {
				return new DateTimeImmutable($value);
			} catch (Throwable $exception) {
				// An unreadable date is replaced by the moment of filing.
			}
		}

		return new DateTimeImmutable();
	}//end submittedAt()

	/**
	 * The exam regulation's term in working days, at least one.
	 *
	 * @return int The term.
	 */
	private function term(): int {
		return max(1, $this->appConfig->getValueInt(AppIdentity::APP_ID, self::TERM_KEY, self::DEFAULT_TERM));
	}//end term()
}//end class
