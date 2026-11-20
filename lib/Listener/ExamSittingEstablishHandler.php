<?php

/**
 * Learniq exam sitting establish handler
 *
 * When the exam committee establishes a sitting, every result of that
 * sitting that is not yet established gets the same establishedAt and
 * establishedBy as the sitting, so all of them are established at once.
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
 * @spec openspec/changes/exam-committee-today-and-keuzedelen/specs/exam-board/spec.md#requirement-the-committee-establishes-the-results-of-a-sitting
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Stamps the sitting's establishment onto each of its results.
 *
 * @spec openspec/changes/exam-committee-today-and-keuzedelen/tasks.md#task-T2
 *
 * @template-implements IEventListener<Event>
 */
class ExamSittingEstablishHandler implements IEventListener {

	/**
	 * The learniq register slug.
	 *
	 * @var string
	 */
	private const REGISTER = 'learniq';

	/**
	 * The exam sitting schema slug.
	 *
	 * @var string
	 */
	private const SITTING_SCHEMA = 'exam-sitting';

	/**
	 * The grade entry schema slug.
	 *
	 * @var string
	 */
	private const GRADE_ENTRY_SCHEMA = 'grade-entry';

	/**
	 * Constructor.
	 *
	 * @param ObjectService                   $objectService Reads and writes the results.
	 * @param ListenerSchemaResolverInterface $schemas       Resolves the event's register and schema.
	 * @param LoggerInterface                 $logger        Logs what was established.
	 *
	 * @spec openspec/changes/exam-committee-today-and-keuzedelen/tasks.md#task-T2
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly ListenerSchemaResolverInterface $schemas,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * React to ExamSitting.establish.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/exam-committee-today-and-keuzedelen/tasks.md#task-T2
	 */
	public function handle(Event $event): void {
		if ($this->isEstablish(event: $event) === false) {
			return;
		}

		$sitting     = $event->getObject()->jsonSerialize();
		$sittingId   = (string) ($sitting['id'] ?? ($sitting['uuid'] ?? ''));
		$establishedAt = $sitting['establishedAt'] ?? null;
		$establishedBy = $sitting['establishedBy'] ?? null;
		if ($sittingId === '' || is_string($establishedAt) === false || is_string($establishedBy) === false) {
			$this->logger->warning(
				'[ExamSittingEstablishHandler] Sitting {id} was established without a moment or an actor; its results are unchanged.',
				['id' => $sittingId]
			);
			return;
		}

		$count = 0;
		foreach ($this->results(sitting: $sitting, sittingId: $sittingId) as $entry) {
			if (empty($entry['establishedAt']) === false) {
				continue;
			}

			$this->objectService->saveObject(
				object: array_merge($entry, ['establishedAt' => $establishedAt, 'establishedBy' => $establishedBy]),
				options: new ObjectSaveOptions(target: new ObjectFindTarget(register: self::REGISTER, schema: self::GRADE_ENTRY_SCHEMA))
			);
			$count++;
		}

		$this->logger->info(
			'[ExamSittingEstablishHandler] Sitting {id}: {count} results established by {actor}.',
			['id' => $sittingId, 'count' => $count, 'actor' => $establishedBy]
		);
	}//end handle()

	/**
	 * Whether the event is a learniq ExamSitting moving to established.
	 *
	 * @param Event $event The event.
	 *
	 * @return bool
	 *
	 * @psalm-assert-if-true ObjectTransitionedEvent $event
	 */
	private function isEstablish(Event $event): bool {
		return $event instanceof ObjectTransitionedEvent
			&& $this->schemas->eventRegister(event: $event) === self::REGISTER
			&& $this->schemas->eventSchema(event: $event) === self::SITTING_SCHEMA
			&& $event->getTo() === 'established';
	}//end isEstablish()

	/**
	 * The results of the sitting in the sitting's tenant.
	 *
	 * @param array<string, mixed> $sitting   The sitting.
	 * @param string               $sittingId The sitting's id.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/exam-committee-today-and-keuzedelen/tasks.md#task-T2
	 */
	private function results(array $sitting, string $sittingId): array {
		$filters = [
			'register'      => self::REGISTER,
			'schema'        => self::GRADE_ENTRY_SCHEMA,
			'examSittingId' => $sittingId,
		];
		if (is_string($sitting['tenant_id'] ?? null) === true && $sitting['tenant_id'] !== '') {
			$filters['tenant_id'] = $sitting['tenant_id'];
		}

		$rows = $this->objectService->findAll(config: ['filters' => $filters, 'limit' => 5000], _rbac: false, _multitenancy: false);

		$results = [];
		foreach ($rows as $row) {
			$data = $row;
			if (is_array($row) === false) {
				$data = $row->jsonSerialize();
			}

			if (($data['examSittingId'] ?? null) === $sittingId) {
				$results[] = $data;
			}
		}

		return $results;
	}//end results()
}//end class
