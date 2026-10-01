<?php

/**
 * Learniq report card grade lines stamp
 *
 * Writes the readable copies of a ReportCard's period and subject grades
 * (`periodName`, `gradeLines`) on every create and update, so they always say
 * what `subjectGrades` and `reportPeriodId` say. The composer, a recompose, a
 * teacher's edit and an import through the API all pass here. The copies are
 * the server's: a value a caller sends for them is replaced.
 *
 * @category Listener
 * @package  OCA\Learniq\Listener
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
 * @spec openspec/changes/portal-parent-report-card-grades/specs/report-card/spec.md#requirement-a-report-card-carries-its-subject-grades-in-readable-form
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Service\ReportCardGradeLines;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Stamps `periodName` and `gradeLines` on every ReportCard write.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/portal-parent-report-card-grades/specs/report-card/spec.md#requirement-a-report-card-carries-its-subject-grades-in-readable-form
 */
class ReportCardGradeLinesStamp implements IEventListener {

	private const REPORT_CARD_SCHEMA = 'report-card';

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolver $schemaResolver Entity schema id to slug.
	 * @param ReportCardGradeLines   $gradeLines     Derives the readable copies.
	 * @param LoggerInterface        $logger         PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly ReportCardGradeLines $gradeLines,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Stamp the readable copies on a ReportCard create or update.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-parent-report-card-grades/specs/report-card/spec.md#requirement-a-report-card-carries-its-subject-grades-in-readable-form
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === false && $event instanceof ObjectUpdatingEvent === false) {
			return;
		}

		if ($event->isPropagationStopped() === true || $this->isReportCard(entity: $this->entityOf(event: $event)) === false) {
			return;
		}

		$payload = array_merge(($this->entityOf(event: $event)->getObject() ?? []), $event->getModifiedData());

		$event->setModifiedData(array_merge($event->getModifiedData(), $this->stampFor(event: $event, payload: $payload)));
	}//end handle()

	/**
	 * The copies to store. When OpenRegister cannot be read, an update keeps
	 * the copies it had and a create stores none: a stale line is better than
	 * a wrong one, and no line is better than a code.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event   The write event.
	 * @param array<string, mixed>                    $payload The ReportCard as it will be saved.
	 *
	 * @return array{periodName: string|null, gradeLines: array<int, string>}
	 */
	private function stampFor(ObjectCreatingEvent|ObjectUpdatingEvent $event, array $payload): array {
		try {
			return $this->gradeLines->derive(reportCard: $payload);
		} catch (Throwable $exception) {
			$kept = ['periodName' => null, 'gradeLines' => []];
			if ($event instanceof ObjectUpdatingEvent === true && $event->getOldObject() !== null) {
				$old = ($event->getOldObject()->getObject() ?? []);
				$kept['gradeLines'] = array_values(array_filter((array)($old['gradeLines'] ?? []), 'is_string'));
				if (is_string($old['periodName'] ?? null) === true) {
					$kept['periodName'] = $old['periodName'];
				}
			}

			$this->logger->warning(
				'[ReportCardGradeLinesStamp] Could not read the subject and period names, keeping {count} lines: {msg}',
				['count' => count($kept['gradeLines']), 'msg' => $exception->getMessage()]
			);
			return $kept;
		}//end try
	}//end stampFor()

	/**
	 * Whether the entity is a ReportCard. Not knowing the schema is not
	 * knowing it is ours, so another app's writes are never touched.
	 *
	 * @param ObjectEntity $entity The object being written.
	 *
	 * @return bool
	 */
	private function isReportCard(ObjectEntity $entity): bool {
		try {
			return $this->schemaResolver->guardSchemaSlug(entity: $entity) === self::REPORT_CARD_SCHEMA;
		} catch (Throwable $exception) {
			return false;
		}
	}//end isReportCard()

	/**
	 * The object being written: the new state on an update.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The write event.
	 *
	 * @return ObjectEntity
	 */
	private function entityOf(ObjectCreatingEvent|ObjectUpdatingEvent $event): ObjectEntity {
		if ($event instanceof ObjectUpdatingEvent === true) {
			return $event->getNewObject();
		}

		return $event->getObject();
	}//end entityOf()
}//end class
