<?php

/**
 * Learniq completed xAPI statement.
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
 * @spec openspec/specs/progress-tracking/spec.md#requirement-the-follow-up-of-an-xapi-statement-runs-outside-the-save-that-records-it
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Service\XapiEnrolmentCompletion;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCP\EventDispatcher\Event;

/**
 * The completed or passed XapiStatement a created event records, and the
 * dedupe key a follow-up of it is queued under.
 *
 * @spec openspec/specs/progress-tracking/spec.md#requirement-the-follow-up-of-an-xapi-statement-runs-outside-the-save-that-records-it
 */
final class CompletedXapiStatement {

	/**
	 * OR register slug for Learniq objects.
	 */
	private const LEARNIQ_REGISTER = 'learniq';

	/**
	 * OR schema slug for xAPI statement objects.
	 */
	private const XAPI_SCHEMA = 'xapi-statement';

	/**
	 * The statement, or null when the event is not the creation of a
	 * Learniq XapiStatement with a completion verb.
	 *
	 * @param Event                  $event          The dispatched event.
	 * @param ListenerSchemaResolver $schemaResolver Resolves the entity's register/schema ids to slugs.
	 *
	 * @return array<string,mixed>|null
	 *
	 * @spec openspec/specs/progress-tracking/spec.md#requirement-the-follow-up-of-an-xapi-statement-runs-outside-the-save-that-records-it
	 */
	public static function fromEvent(Event $event, ListenerSchemaResolver $schemaResolver): ?array {
		if ($event instanceof ObjectCreatedEvent === false) {
			return null;
		}

		$entity = $event->getObject();
		if ($schemaResolver->registerSlug(entity: $entity) !== self::LEARNIQ_REGISTER
			|| $schemaResolver->schemaSlug(entity: $entity) !== self::XAPI_SCHEMA
		) {
			return null;
		}

		$statement = $entity->jsonSerialize();
		if (in_array(($statement['verb']['id'] ?? ''), XapiEnrolmentCompletion::COMPLETION_VERBS, true) === false) {
			return null;
		}

		return $statement;
	}//end fromEvent()

	/**
	 * Deduplicated per statement: a repeated event owes one follow-up of each
	 * kind. No id, no dedupe.
	 *
	 * @param string              $kind      The follow-up kind.
	 * @param array<string,mixed> $statement The statement.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/progress-tracking/spec.md#requirement-the-follow-up-of-an-xapi-statement-runs-outside-the-save-that-records-it
	 */
	public static function dedupeKey(string $kind, array $statement): ?string {
		$id = (string)($statement['id'] ?? ($statement['uuid'] ?? ''));
		if ($id === '') {
			return null;
		}

		return $kind . '|' . $id;
	}//end dedupeKey()
}//end class
