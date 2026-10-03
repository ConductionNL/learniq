<?php

/**
 * Learniq Preview Write Guard
 *
 * The server half of preview as learner (design D2). The lesson player sends
 * the `X-Learniq-Preview` header on every write it makes in a preview; this
 * listener refuses a lesson completion, an assessment result or an xAPI
 * statement created in such a request. The player itself makes no such write
 * in a preview; this veto is what still holds when the player has a bug.
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
 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-preview-as-learner/spec.md#scenario-a-preview-leaves-no-records
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IRequest;
use Throwable;

/**
 * Refuses learner activity records written from a preview.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-preview-as-learner/spec.md#scenario-a-preview-leaves-no-records
 */
class PreviewWriteGuard implements IEventListener {

	/**
	 * The request header the lesson player sends in a preview.
	 */
	public const HEADER = 'X-Learniq-Preview';

	/**
	 * The schemas that record learner activity.
	 */
	private const ACTIVITY_SCHEMAS = ['lesson-completion', 'assessment-result', 'xapi-statement'];

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolver $schemaResolver Resolves the event entity's schema slug.
	 * @param IRequest               $request        The current request.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly IRequest $request,
	) {
	}//end __construct()

	/**
	 * Handle an OpenRegister creating event.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-preview-as-learner/spec.md#scenario-a-preview-leaves-no-records
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === false || $this->isPreview() === false) {
			return;
		}

		try {
			$slug = $this->schemaResolver->guardSchemaSlug(entity: $event->getObject());
		} catch (Throwable) {
			// Not knowing the schema is not knowing it is ours.
			return;
		}

		if (in_array($slug, self::ACTIVITY_SCHEMAS, true) === false) {
			return;
		}

		$event->setErrors(
			[
				'reason'  => 'preview-records-nothing',
				'message' => 'A preview does not record anything.',
			]
		);
		$event->stopPropagation();
	}//end handle()

	/**
	 * Whether the current request comes from a preview.
	 *
	 * @return bool
	 */
	private function isPreview(): bool {
		return trim($this->request->getHeader(self::HEADER)) === '1';
	}//end isPreview()
}//end class
