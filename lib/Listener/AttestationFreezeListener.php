<?php

/**
 * Learniq Attestation Freeze Listener
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Makes a signed attestation tamper-proof, not only tamper-evident. The
 * `sign` transition puts an HMAC over the evidence fields
 * (TenantSignatureAction), so a later edit shows up as a broken signature;
 * nothing refused the edit itself, because the schema dropped `appendOnly`
 * (learniq#977) when it turned out to refuse `sign` too.
 *
 *   - drafted: editable, so a mistake before signing can still be fixed;
 *   - signed: the evidence fields are fixed; only `revoke` may move on, and
 *     it writes `lifecycle` and `revocationReason` and nothing else;
 *   - revoked: final.
 *
 * Unlike the LVS freeze there is no role or system exemption (design D3): an
 * attestation that is wrong is revoked with a reason and a new one is signed,
 * which keeps the evidence trail whole. Moving a signed attestation out of its
 * register is refused like an edit.
 *
 * ADR-031 legitimate exception: a write rule that compares the stored object
 * with the incoming one, which no schema declaration can express.
 *
 * @category Listener
 * @package  OCA\Learniq\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/compliance-attestation-locked-after-signing/specs/compliance-audit/spec.md#requirement-a-signed-attestation-cannot-be-edited
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\OpenRegister\Event\ObjectLeavingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Refuses any change to a signed or revoked attestation's evidence.
 *
 * @spec openspec/changes/compliance-attestation-locked-after-signing/specs/compliance-audit/spec.md#requirement-a-signed-attestation-cannot-be-edited
 *
 * @template-implements IEventListener<Event>
 */
class AttestationFreezeListener implements IEventListener {

	/**
	 * Schema slug this listener guards.
	 */
	private const SCHEMA = 'attestation';

	/**
	 * The state a signed attestation is in until it is revoked.
	 */
	private const SIGNED = 'signed';

	/**
	 * The final state.
	 */
	private const REVOKED = 'revoked';

	/**
	 * Fields fixed from signing on (design D2).
	 */
	public const EVIDENCE_FIELDS = [
		'learnerId',
		'lessonId',
		'courseId',
		'regulationSlug',
		'actorIp',
		'employeeId',
		'score',
		'xapiStatementId',
		'signature',
		'signingKeyId',
		'signedAt',
		'tenant_id',
	];

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolverInterface $schemaResolver Resolves the event entity's schema slug.
	 * @param LoggerInterface                 $logger         PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListenerSchemaResolverInterface $schemaResolver,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an OpenRegister updating or leaving event.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/compliance-attestation-locked-after-signing/specs/compliance-audit/spec.md#requirement-a-signed-attestation-cannot-be-edited
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectLeavingEvent === true) {
			$this->refuseFrozenMove(event: $event);
			return;
		}

		if ($event instanceof ObjectUpdatingEvent === true) {
			$this->refuseFrozenUpdate(event: $event);
		}
	}//end handle()

	/**
	 * Refuse an update that would change a signed or revoked attestation, other than its revocation.
	 *
	 * @param ObjectUpdatingEvent $event The update.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/compliance-attestation-locked-after-signing/specs/compliance-audit/spec.md#requirement-a-signed-attestation-cannot-be-edited
	 */
	private function refuseFrozenUpdate(ObjectUpdatingEvent $event): void {
		$old = $event->getOldObject();
		if ($old === null || $this->isAttestation(entity: $event->getNewObject()) === false) {
			return;
		}

		$block = self::refusal(old: (array)$old->getObject(), new: (array)$event->getNewObject()->getObject());
		if ($block === null) {
			return;
		}

		$event->setErrors($block);
		$event->stopPropagation();
		$this->logger->info('[AttestationFreezeListener] Refused a write: {reason}', ['reason' => $block['reason']]);
	}//end refuseFrozenUpdate()

	/**
	 * Refuse moving a signed or revoked attestation out of its register or schema.
	 *
	 * @param ObjectLeavingEvent $event The move event, carrying the stored object.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/compliance-attestation-locked-after-signing/specs/compliance-audit/spec.md#requirement-a-signed-attestation-cannot-be-edited
	 */
	private function refuseFrozenMove(ObjectLeavingEvent $event): void {
		$entity = $event->getObject();
		if ($this->isAttestation(entity: $entity) === false) {
			return;
		}

		$state = (string)($entity->getObject()['lifecycle'] ?? '');
		if (in_array($state, [self::SIGNED, self::REVOKED], true) === false) {
			return;
		}

		$event->setErrors(['reason' => 'attestation-move', 'message' => 'A signed attestation cannot be moved.']);
		$event->stopPropagation();
		$this->logger->info('[AttestationFreezeListener] Refused a write: {reason}', ['reason' => 'attestation-move']);
	}//end refuseFrozenMove()

	/**
	 * Whether the write concerns an Attestation. Every writer is policed, admins and system context included.
	 *
	 * @param object|null $entity The object the write concerns.
	 *
	 * @return bool True when the write must be checked.
	 */
	private function isAttestation(?object $entity): bool {
		try {
			return $this->schemaResolver->guardSchemaSlug(entity: $entity) === self::SCHEMA;
		} catch (Throwable) {
			// Not knowing the schema is not knowing it is ours: never break
			// another app's writes.
			return false;
		}
	}//end isAttestation()

	/**
	 * Decide whether an update of a stored attestation is allowed.
	 *
	 * @param array<string,mixed> $old The stored attestation.
	 * @param array<string,mixed> $new The attestation as it would be saved.
	 *
	 * @return array{reason: string, message: string}|null The refusal, or null to allow.
	 *
	 * @spec openspec/changes/compliance-attestation-locked-after-signing/specs/compliance-audit/spec.md#requirement-a-signed-attestation-cannot-be-edited
	 */
	public static function refusal(array $old, array $new): ?array {
		$state = (string)($old['lifecycle'] ?? '');
		if ($state !== self::SIGNED && $state !== self::REVOKED) {
			return null;
		}

		if (self::changed(old: $old, new: $new, fields: self::EVIDENCE_FIELDS) === true) {
			return ['reason' => 'attestation-signed', 'message' => 'A signed attestation cannot be changed. Revoke it with a reason and sign a new one.'];
		}

		$newState = (string)($new['lifecycle'] ?? $state);
		if ($state === self::REVOKED && ($newState !== self::REVOKED || self::changed(old: $old, new: $new, fields: ['revocationReason']) === true)) {
			return ['reason' => 'attestation-revoked', 'message' => 'A revoked attestation cannot be changed.'];
		}

		if ($state === self::SIGNED && $newState === self::SIGNED && self::changed(old: $old, new: $new, fields: ['revocationReason']) === true) {
			return ['reason' => 'attestation-signed', 'message' => 'A revocation reason is only written by revoking the attestation.'];
		}

		if ($newState !== $state && $newState !== self::REVOKED) {
			return ['reason' => 'attestation-lifecycle', 'message' => 'A signed attestation can only be revoked.'];
		}

		return null;
	}//end refusal()

	/**
	 * Whether any of the fields differs between the stored and the incoming attestation.
	 *
	 * Values are compared the way they round-trip through JSON, so an integer 3
	 * and a float 3.0 are the same score.
	 *
	 * @param array<string,mixed> $old    The stored attestation.
	 * @param array<string,mixed> $new    The attestation as it would be saved.
	 * @param array<int,string>   $fields The fields to compare.
	 *
	 * @return bool True when one of them changes.
	 */
	private static function changed(array $old, array $new, array $fields): bool {
		foreach ($fields as $field) {
			$left  = ($old[$field] ?? null);
			$right = ($new[$field] ?? null);
			if (is_int($left) === true || is_float($left) === true) {
				$left = (float)$left;
			}

			if (is_int($right) === true || is_float($right) === true) {
				$right = (float)$right;
			}

			if ($left !== $right) {
				return true;
			}
		}

		return false;
	}//end changed()
}//end class
