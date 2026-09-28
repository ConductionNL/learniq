<?php

/**
 * Learniq LvsResult Freeze Listener
 *
 * Keeps a verified LVS score fixed now that LvsResult is no longer
 * `appendOnly` (learniq#1124). OpenRegister refused every update on the
 * append-only schema, the `verify` and `archive` transitions included, so the
 * flag went; since then the writer groups (coordinators, compliance officers)
 * could also edit the imported score. This listener puts the freeze back where
 * the lifecycle needs it:
 *
 *   - imported: a coordinator may still correct the row before verifying it;
 *   - verified: the score fields are fixed, and the row may only move on to
 *     `archived`;
 *   - archived: final.
 *
 * `learnerId` and `assessmentResultId` stay writable: a learner merge
 * re-points the first (LearnerMergeService) and the second is a back-link that
 * may be made after verifying. Nextcloud admins and system context (no
 * session: the import job, repair steps) are not policed.
 *
 * ADR-031 legitimate exception: a write rule that compares the stored object
 * with the incoming one, which no schema declaration can express.
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
 * @spec openspec/changes/lvs-score-freeze/specs/data-exchange/spec.md#requirement-a-verified-lvs-score-cannot-be-changed
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Vetoes LvsResult updates that would change a verified score.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/lvs-score-freeze/specs/data-exchange/spec.md#requirement-a-verified-lvs-score-cannot-be-changed
 */
class LvsResultFreezeListener implements IEventListener {

	/**
	 * Schema slug this listener guards.
	 */
	private const LVS_SCHEMA = 'lvs-result';

	/**
	 * Lifecycle states in which the score is frozen.
	 */
	private const FROZEN_STATES = ['verified', 'archived'];

	/**
	 * Fields a verified result never changes.
	 */
	private const SCORE_FIELDS = [
		'provider',
		'instrument',
		'moment',
		'takenAt',
		'rawScore',
		'vaardigheidsscore',
		'niveau',
		'referentieniveau',
		'dle',
		'dataExchangeJobId',
		'tenant_id',
	];

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolver $schemaResolver Resolves the event entity's schema slug.
	 * @param IUserSession $userSession The acting user.
	 * @param IGroupManager $groupManager Admin check.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an OpenRegister updating event.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lvs-score-freeze/specs/data-exchange/spec.md#requirement-a-verified-lvs-score-cannot-be-changed
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectUpdatingEvent === false) {
			return;
		}

		$old = $event->getOldObject();
		if ($old === null || $this->isPoliced(event: $event) === false) {
			return;
		}

		$block = $this->evaluate(old: $old->getObject(), new: $event->getNewObject()->getObject());
		if ($block === null) {
			return;
		}

		$event->setErrors($block);
		$event->stopPropagation();
		$this->logger->info('[LvsResultFreezeListener] Refused a write: {reason}', ['reason' => $block['reason']]);
	}//end handle()

	/**
	 * Whether this write is an LvsResult update by a signed-in non-admin.
	 *
	 * @param ObjectUpdatingEvent $event The event.
	 *
	 * @return bool True when the write must be checked.
	 *
	 * @spec openspec/changes/lvs-score-freeze/specs/data-exchange/spec.md#requirement-a-verified-lvs-score-cannot-be-changed
	 */
	private function isPoliced(ObjectUpdatingEvent $event): bool {
		try {
			$slug = $this->schemaResolver->guardSchemaSlug(entity: $event->getNewObject());
		} catch (Throwable $exception) {
			// Not knowing the schema is not knowing it is ours: never break
			// another app's writes.
			return false;
		}

		$user = $this->userSession->getUser();

		return $slug === self::LVS_SCHEMA
			&& $user !== null
			&& $this->groupManager->isAdmin($user->getUID()) === false;
	}//end isPoliced()

	/**
	 * Decide whether an update is allowed.
	 *
	 * @param array<string,mixed> $old The stored result.
	 * @param array<string,mixed> $new The result as it would be saved.
	 *
	 * @return array{reason: string, message: string}|null The refusal, or null to allow.
	 *
	 * @spec openspec/changes/lvs-score-freeze/specs/data-exchange/spec.md#requirement-a-verified-lvs-score-cannot-be-changed
	 */
	private function evaluate(array $old, array $new): ?array {
		$state = (string)($old['lifecycle'] ?? '');
		if (in_array($state, self::FROZEN_STATES, true) === false) {
			return null;
		}

		foreach (self::SCORE_FIELDS as $field) {
			if ($this->same(left: ($old[$field] ?? null), right: ($new[$field] ?? null)) === false) {
				return [
					'reason' => 'lvs-result-verified',
					'message' => 'The score of a verified LVS result cannot be changed.',
				];
			}
		}

		$newState = (string)($new['lifecycle'] ?? $state);
		if ($newState !== $state && ($state !== 'verified' || $newState !== 'archived')) {
			return [
				'reason' => 'lvs-result-lifecycle',
				'message' => 'A verified LVS result can only be archived.',
			];
		}

		return null;
	}//end evaluate()

	/**
	 * Compare two stored values the way they round-trip through JSON, so an
	 * integer 3 and a float 3.0 are the same score.
	 *
	 * @param mixed $left One value.
	 * @param mixed $right The other value.
	 *
	 * @return bool True when equal.
	 *
	 * @spec openspec/changes/lvs-score-freeze/specs/data-exchange/spec.md#requirement-a-verified-lvs-score-cannot-be-changed
	 */
	private function same(mixed $left, mixed $right): bool {
		if (is_int($left) === true || is_float($left) === true) {
			$left = (float)$left;
		}

		if (is_int($right) === true || is_float($right) === true) {
			$right = (float)$right;
		}

		return $left === $right;
	}//end same()
}//end class
