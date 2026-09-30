<?php

/**
 * Learniq Report Card PDF Transition Listener
 *
 * Renders a ReportCard to PDF after its `renderToPdf` (finalised -> finalised)
 * or `rerenderToPdf` (published-to-parents -> published-to-parents)
 * transition and saves the outcome back onto it. Both are self-loops, and
 * OpenRegister's lifecycle listeners return early when the lifecycle value
 * does not change, so neither a `requires` guard nor a transition action runs
 * on them. TransitionEngine does dispatch ObjectTransitionedEvent after the
 * save for every transition, self-loops included (learniq#983).
 *
 * The write used to happen inside the guard, which OpenRegister calls by
 * value, so it never reached the saved object.
 *
 * @category Listener
 * @package  OCA\Learniq\Listener
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
 * @spec openspec/specs/report-card/spec.md#requirement-docudesk-pdf-rendering-is-fail-soft-non-blocking-and-its-contract-is-explicitly-proposed
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Service\ReportCardPdfDelegationService;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Saves the docudesk render outcome onto a ReportCard after its self-loop transition.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/report-card/spec.md#requirement-docudesk-pdf-rendering-is-fail-soft-non-blocking-and-its-contract-is-explicitly-proposed
 */
class ReportCardPdfTransitionListener implements IEventListener {

	private const LEARNIQ_REGISTER = 'learniq';
	private const REPORT_CARD_SCHEMA = 'report-card';
	private const RENDER_ACTIONS = ['renderToPdf', 'rerenderToPdf'];

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR object service, to save the ReportCard.
	 * @param ReportCardPdfDelegationService $pdfService The docudesk render bridge.
	 * @param LoggerInterface $logger PSR logger.
	 * @param ListenerSchemaResolver $schemas Resolves the transition event's register and schema ids to slugs.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly ReportCardPdfDelegationService $pdfService,
		private readonly LoggerInterface $logger,
		private readonly ListenerSchemaResolver $schemas,
	) {
	}//end __construct()

	/**
	 * Render and save the outcome of `renderToPdf` or `rerenderToPdf`.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/report-card/spec.md#requirement-docudesk-pdf-rendering-is-fail-soft-non-blocking-and-its-contract-is-explicitly-proposed
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectTransitionedEvent) === false
			|| $this->schemas->eventRegister(event: $event) !== self::LEARNIQ_REGISTER
			|| $this->schemas->eventSchema(event: $event) !== self::REPORT_CARD_SCHEMA
			|| in_array($event->getAction(), self::RENDER_ACTIONS, true) === false
		) {
			return;
		}

		$entity = $event->getObject();

		$this->objectService->saveObject(
			object: $this->pdfService->render(reportCard: $entity->getObject()),
			register: self::LEARNIQ_REGISTER,
			schema: self::REPORT_CARD_SCHEMA,
			uuid: $entity->getUuid()
		);

		$this->logger->info(
			'[ReportCardPdfTransitionListener] Saved the {action} outcome on ReportCard {id}.',
			['action' => $event->getAction(), 'id' => $entity->getUuid()]
		);
	}//end handle()
}//end class
