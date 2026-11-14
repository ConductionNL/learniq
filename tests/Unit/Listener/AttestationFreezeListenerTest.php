<?php

/**
 * Unit tests for AttestationFreezeListener (compliance-attestation-locked-after-signing).
 *
 * A signed attestation's evidence fields are fixed for every writer, admins
 * included; a drafted one stays editable; `revoke` may write the lifecycle and
 * its reason and nothing else; a revoked one is final.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Listener
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
 * @spec openspec/changes/compliance-attestation-locked-after-signing/specs/compliance-audit/spec.md#requirement-a-signed-attestation-cannot-be-edited
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\Listener\AttestationFreezeListener;
use OCA\Learniq\Listener\Registrar\EvidenceFreezeListenerRegistrar;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Event\ObjectLeavingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for the signed attestation freeze.
 */
class AttestationFreezeListenerTest extends TestCase {

	/**
	 * Build the listener; it takes no user or group, because nobody is exempt.
	 *
	 * @param string $schemaSlug The schema the event's entity resolves to.
	 *
	 * @return AttestationFreezeListener
	 */
	private function makeListener(string $schemaSlug = 'attestation'): AttestationFreezeListener {
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('guardSchemaSlug')->willReturn($schemaSlug);

		return new AttestationFreezeListener(schemaResolver: $resolver, logger: new NullLogger());
	}//end makeListener()

	/**
	 * An attestation in a lifecycle state.
	 *
	 * @param string              $lifecycle The state.
	 * @param array<string,mixed> $override  Fields to override.
	 *
	 * @return array<string,mixed>
	 */
	private function attestation(string $lifecycle, array $override = []): array {
		return array_merge(
			[
				'id' => 'att1',
				'learnerId' => 'learner1',
				'lessonId' => 'lesson-1',
				'courseId' => 'course-1',
				'regulationSlug' => 'nis2',
				'actorIp' => '10.0.0.1',
				'employeeId' => 'E-7',
				'score' => 8,
				'xapiStatementId' => 'stmt-1',
				'signature' => 'abc123',
				'signingKeyId' => 'key-1',
				'signedAt' => '2026-11-01T09:00:00+00:00',
				'tenant_id' => 't1',
				'lifecycle' => $lifecycle,
			],
			$override
		);
	}//end attestation()

	/**
	 * Run an update through the listener.
	 *
	 * @param array<string,mixed> $old      The stored object.
	 * @param array<string,mixed> $new      The object as it would be saved.
	 * @param string              $slug     The schema slug the entity resolves to.
	 *
	 * @return ObjectUpdatingEvent
	 */
	private function update(array $old, array $new, string $slug = 'attestation'): ObjectUpdatingEvent {
		$event = new ObjectUpdatingEvent(OrEntityFactory::make($new, $slug), OrEntityFactory::make($old, $slug));
		$this->makeListener(schemaSlug: $slug)->handle($event);
		return $event;
	}//end update()

	/**
	 * A compliance officer cannot change the score of a signed attestation.
	 *
	 * @return void
	 */
	public function testAnEditOfASignedScoreIsRefused(): void {
		$event = $this->update($this->attestation('signed'), $this->attestation('signed', ['score' => 10]));

		self::assertTrue($event->isPropagationStopped());
		self::assertSame('attestation-signed', $event->getErrors()['reason']);
		self::assertStringContainsString('cannot be changed', $event->getErrors()['message']);
	}//end testAnEditOfASignedScoreIsRefused()

	/**
	 * Every evidence field is frozen once signed, whoever writes: the listener asks for no user at all.
	 *
	 * @return array<string, array{0: string, 1: mixed}>
	 */
	public static function evidence(): array {
		$cases = [];
		foreach (AttestationFreezeListener::EVIDENCE_FIELDS as $field) {
			$cases[$field] = [$field, 'changed-' . $field];
		}

		return $cases;
	}//end evidence()

	/**
	 * @param string $field The evidence field.
	 * @param mixed  $value Its new value.
	 *
	 * @return void
	 */
	#[DataProvider('evidence')]
	public function testEveryEvidenceFieldIsFrozen(string $field, mixed $value): void {
		$event = $this->update($this->attestation('signed'), $this->attestation('signed', [$field => $value]));

		self::assertTrue($event->isPropagationStopped(), $field . ' could be changed after signing');
	}//end testEveryEvidenceFieldIsFrozen()

	/**
	 * Removing an evidence field is a change too.
	 *
	 * @return void
	 */
	public function testRemovingASignatureIsRefused(): void {
		$new = $this->attestation('signed');
		unset($new['signature']);

		self::assertTrue($this->update($this->attestation('signed'), $new)->isPropagationStopped());
	}//end testRemovingASignatureIsRefused()

	/**
	 * A drafted attestation is still corrected freely.
	 *
	 * @return void
	 */
	public function testADraftedAttestationStaysEditable(): void {
		$event = $this->update($this->attestation('drafted'), $this->attestation('drafted', ['courseId' => 'course-2']));

		self::assertFalse($event->isPropagationStopped());
	}//end testADraftedAttestationStaysEditable()

	/**
	 * The sign transition's own write starts from a drafted attestation and passes.
	 *
	 * @return void
	 */
	public function testSigningPasses(): void {
		$drafted = $this->attestation('drafted', ['signature' => null, 'signingKeyId' => null, 'signedAt' => null]);

		self::assertFalse($this->update($drafted, $this->attestation('signed'))->isPropagationStopped());
	}//end testSigningPasses()

	/**
	 * Revoking writes the lifecycle and the reason, and passes.
	 *
	 * @return void
	 */
	public function testRevokingWithAReasonPasses(): void {
		$event = $this->update(
			$this->attestation('signed'),
			$this->attestation('revoked', ['revocationReason' => 'Signed for the wrong course'])
		);

		self::assertFalse($event->isPropagationStopped());
	}//end testRevokingWithAReasonPasses()

	/**
	 * Revoking may not carry an evidence change along.
	 *
	 * @return void
	 */
	public function testRevokingCannotChangeEvidence(): void {
		$event = $this->update(
			$this->attestation('signed'),
			$this->attestation('revoked', ['revocationReason' => 'Wrong course', 'score' => 3])
		);

		self::assertSame('attestation-signed', $event->getErrors()['reason']);
	}//end testRevokingCannotChangeEvidence()

	/**
	 * A reason is only written by revoking, not by editing a signed attestation.
	 *
	 * @return void
	 */
	public function testAReasonWithoutRevokingIsRefused(): void {
		$event = $this->update($this->attestation('signed'), $this->attestation('signed', ['revocationReason' => 'x']));

		self::assertTrue($event->isPropagationStopped());
	}//end testAReasonWithoutRevokingIsRefused()

	/**
	 * A signed attestation cannot move back to drafted.
	 *
	 * @return void
	 */
	public function testASignedAttestationCannotBeUnsigned(): void {
		$event = $this->update($this->attestation('signed'), $this->attestation('drafted'));

		self::assertSame('attestation-lifecycle', $event->getErrors()['reason']);
	}//end testASignedAttestationCannotBeUnsigned()

	/**
	 * A revoked attestation is final: neither its reason nor its state changes.
	 *
	 * @return void
	 */
	public function testARevokedAttestationIsFinal(): void {
		$revoked = $this->attestation('revoked', ['revocationReason' => 'Wrong course']);

		self::assertSame(
			'attestation-revoked',
			$this->update($revoked, $this->attestation('revoked', ['revocationReason' => 'Other']))->getErrors()['reason']
		);
		self::assertSame(
			'attestation-revoked',
			$this->update($revoked, $this->attestation('signed', ['revocationReason' => 'Wrong course']))->getErrors()['reason']
		);
	}//end testARevokedAttestationIsFinal()

	/**
	 * An unchanged save of a signed attestation, and an integer score read back as a float, pass.
	 *
	 * @return void
	 */
	public function testAnUnchangedSavePasses(): void {
		$event = $this->update($this->attestation('signed'), $this->attestation('signed', ['score' => 8.0]));

		self::assertFalse($event->isPropagationStopped());
	}//end testAnUnchangedSavePasses()

	/**
	 * Another schema is not this listener's concern.
	 *
	 * @return void
	 */
	public function testAnotherSchemaIsLeftAlone(): void {
		$event = $this->update($this->attestation('signed'), $this->attestation('signed', ['score' => 1]), 'lvs-result');

		self::assertFalse($event->isPropagationStopped());
	}//end testAnotherSchemaIsLeftAlone()

	/**
	 * An unknown schema never breaks another app's write.
	 *
	 * @return void
	 */
	public function testAnUnresolvableSchemaIsLeftAlone(): void {
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('guardSchemaSlug')->willThrowException(new RuntimeException('no schema'));
		$listener = new AttestationFreezeListener(schemaResolver: $resolver, logger: new NullLogger());
		$event = new ObjectUpdatingEvent(
			OrEntityFactory::make($this->attestation('signed', ['score' => 1])),
			OrEntityFactory::make($this->attestation('signed'))
		);

		$listener->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testAnUnresolvableSchemaIsLeftAlone()

	/**
	 * Moving a signed attestation out of its register is refused; a drafted one may move.
	 *
	 * @return void
	 */
	public function testMovingASignedAttestationIsRefused(): void {
		$signed = new ObjectLeavingEvent(OrEntityFactory::make($this->attestation('signed'), 'attestation'));
		$this->makeListener()->handle($signed);
		self::assertSame('attestation-move', $signed->getErrors()['reason']);

		$drafted = new ObjectLeavingEvent(OrEntityFactory::make($this->attestation('drafted'), 'attestation'));
		$this->makeListener()->handle($drafted);
		self::assertFalse($drafted->isPropagationStopped());
	}//end testMovingASignedAttestationIsRefused()

	/**
	 * The freeze hears both the update and the move.
	 *
	 * @return void
	 */
	public function testTheRegistrarWiresTheFreezeOnUpdateAndMove(): void {
		$events  = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$events): void {
				if ($listener === AttestationFreezeListener::class) {
					$events[] = $event;
				}
			}
		);

		(new EvidenceFreezeListenerRegistrar())->register($context);

		self::assertSame([ObjectUpdatingEvent::class, ObjectLeavingEvent::class], $events);
	}//end testTheRegistrarWiresTheFreezeOnUpdateAndMove()
}//end class
