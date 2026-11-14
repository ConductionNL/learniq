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

use OCA\Learniq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Learniq\Kernel\Contracts\ReportCardPdfAttacherInterface;
use OCA\Learniq\Kernel\Contracts\ReportCardPdfDelegationServiceInterface;
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
	 * Publishing to parents renders the PDF when none was rendered yet (report-card-pdf-to-guardians D1).
	 *
	 * @var string
	 */
	private const PUBLISH_ACTION = 'publishToParents';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR object service, to save the ReportCard.
	 * @param ReportCardPdfDelegationServiceInterface $pdfService The docudesk render bridge.
	 * @param LoggerInterface $logger PSR logger.
	 * @param ListenerSchemaResolverInterface $schemas Resolves the transition event's register and schema ids to slugs.
	 * @param ReportCardPdfAttacherInterface $attacher Puts the rendered PDF on the card as a file.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly ReportCardPdfDelegationServiceInterface $pdfService,
		private readonly LoggerInterface $logger,
		private readonly ListenerSchemaResolverInterface $schemas,
		private readonly ReportCardPdfAttacherInterface $attacher,
	) {
	}//end __construct()

	/**
	 * Render and save the outcome of `renderToPdf`, `rerenderToPdf` or a publish without a PDF,
	 * then attach the rendered PDF to the card.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/report-card/spec.md#requirement-docudesk-pdf-rendering-is-fail-soft-non-blocking-and-its-contract-is-explicitly-proposed
	 * @spec openspec/changes/report-card-pdf-to-guardians/specs/report-card/spec.md#requirement-the-rendered-pdf-is-a-file-on-the-report-card
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectTransitionedEvent) === false
			|| $this->schemas->eventRegister(event: $event) !== self::LEARNIQ_REGISTER
			|| $this->schemas->eventSchema(event: $event) !== self::REPORT_CARD_SCHEMA
		) {
			return;
		}

		$entity = $event->getObject();
		if ($this->rendersOn(action: $event->getAction(), reportCard: $entity->getObject()) === false) {
			return;
		}

		$saved = $this->objectService->saveObject(
			object: $this->pdfService->render(reportCard: $entity->getObject()),
			register: self::LEARNIQ_REGISTER,
			schema: self::REPORT_CARD_SCHEMA,
			uuid: $entity->getUuid()
		);

		// A successful render puts the PDF on the card, where the guardian's download reads it (D2).
		if (($saved->getObject()['docudeskRenderStatus'] ?? null) === 'rendered') {
			$this->attacher->attach(reportCard: $saved);
		}

		$this->logger->info(
			'[ReportCardPdfTransitionListener] Saved the {action} outcome on ReportCard {id}.',
			['action' => $event->getAction(), 'id' => $entity->getUuid()]
		);
	}//end handle()

	/**
	 * Whether this transition renders the PDF.
	 *
	 * `renderToPdf` and `rerenderToPdf` always do. `publishToParents` does when
	 * no PDF was rendered yet: publishing is when the card goes home, and it
	 * runs after the publish is saved, so a filinq outage never blocks it.
	 *
	 * @param string $action The transition that ran.
	 * @param array<string, mixed> $reportCard The report card as saved.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/report-card-pdf-to-guardians/specs/report-card/spec.md#requirement-publishing-a-report-card-renders-its-pdf
	 */
	private function rendersOn(string $action, array $reportCard): bool {
		if (in_array($action, self::RENDER_ACTIONS, true) === true) {
			return true;
		}

		return $action === self::PUBLISH_ACTION && ($reportCard['docudeskRenderStatus'] ?? null) !== 'rendered';
	}//end rendersOn()
}//end class
