<?php

/**
 * Who asked for a correction, what the grade was before, and that the
 * request stays as asked after it is written.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Listener
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
 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#requirement-audit-of-corrections
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\Listener\DataCorrectionRequestStamp;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * DataCorrectionRequestStamp on create and update.
 *
 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#requirement-audit-of-corrections
 */
class DataCorrectionRequestStampTest extends TestCase {

	private const ENTRY = '0b7c5a3e-0000-4000-8000-000000000001';

	/**
	 * A request as the form posts it, with fields the client may not set.
	 *
	 * @var array<string, mixed>
	 */
	private const POSTED = [
		'gradeEntryId'  => self::ENTRY,
		'proposedValue' => 6.5,
		'reason'        => 'A page of the exam was not counted.',
		'requestedBy'   => 'principal-b',
		'currentValue'  => 9.0,
		'decidedBy'     => 'principal-b',
		'lifecycle'     => 'approved',
	];

	/**
	 * The stamp over a store holding one grade entry, signed in as $userId.
	 *
	 * @param string|null $userId         The session user, null for no session.
	 * @param string      $entryLifecycle The grade entry's state.
	 * @param string      $slug           What the schema resolver answers.
	 *
	 * @return DataCorrectionRequestStamp
	 */
	private function makeStamp(?string $userId, string $entryLifecycle = 'published', string $slug = 'data-correction-request'): DataCorrectionRequestStamp {
		$entry   = ['id' => self::ENTRY, 'value' => 5.5, 'lifecycle' => $entryLifecycle];
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			static function (int|string $id) use ($entry): ObjectEntity {
				if ((string)$id === self::ENTRY) {
					return OrEntityFactory::make($entry, 'grade-entry');
				}

				throw new DoesNotExistException('gone');
			}
		);

		$schemaResolver = $this->createMock(ListenerSchemaResolver::class);
		$schemaResolver->method('guardSchemaSlug')->willReturn($slug);

		$user = null;
		if ($userId !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($userId);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new DataCorrectionRequestStamp(schemaResolver: $schemaResolver, objects: $objects, userSession: $session);
	}//end makeStamp()

	/**
	 * Teacher A asks: the request names teacher A and the grade's current
	 * value, starts as requested, and carries no decision, whatever was sent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#scenario-an-auditor-reads-the-trail
	 */
	public function testARequestNamesTheRequesterAndTheValueBefore(): void {
		$event = new ObjectCreatingEvent(OrEntityFactory::make(self::POSTED, 'data-correction-request'));
		$this->makeStamp(userId: 'teacher-a')->handle($event);

		self::assertFalse($event->isPropagationStopped());
		$data = $event->getModifiedData();
		self::assertSame('teacher-a', $data['requestedBy']);
		self::assertSame(5.5, $data['currentValue']);
		self::assertSame('requested', $data['lifecycle']);
		self::assertNull($data['decidedBy']);
		self::assertNull($data['appliedBy']);
	}//end testARequestNamesTheRequesterAndTheValueBefore()

	/**
	 * No session, an unknown grade, or a grade that is not published yet: refused.
	 *
	 * @return void
	 */
	public function testARequestNeedsASessionAndAPublishedGrade(): void {
		$anonymous = new ObjectCreatingEvent(OrEntityFactory::make(self::POSTED, 'data-correction-request'));
		$this->makeStamp(userId: null)->handle($anonymous);
		self::assertSame('correction-no-session', $anonymous->getErrors()['reason']);

		$unknown = new ObjectCreatingEvent(OrEntityFactory::make(array_merge(self::POSTED, ['gradeEntryId' => 'nothing']), 'data-correction-request'));
		$this->makeStamp(userId: 'teacher-a')->handle($unknown);
		self::assertSame('correction-no-entry', $unknown->getErrors()['reason']);

		$unnamed = new ObjectCreatingEvent(OrEntityFactory::make(array_merge(self::POSTED, ['gradeEntryId' => '']), 'data-correction-request'));
		$this->makeStamp(userId: 'teacher-a')->handle($unnamed);
		self::assertSame('correction-no-entry', $unnamed->getErrors()['reason']);

		$concept = new ObjectCreatingEvent(OrEntityFactory::make(self::POSTED, 'data-correction-request'));
		$this->makeStamp(userId: 'teacher-a', entryLifecycle: 'concept')->handle($concept);
		self::assertSame('correction-not-published', $concept->getErrors()['reason']);

		$other = new ObjectCreatingEvent(OrEntityFactory::make(self::POSTED, 'grade-entry'));
		$this->makeStamp(userId: 'teacher-a', slug: 'grade-entry')->handle($other);
		self::assertFalse($other->isPropagationStopped());
		self::assertSame([], $other->getModifiedData());
	}//end testARequestNeedsASessionAndAPublishedGrade()

	/**
	 * An approver who edits the request before approving it approves what was
	 * asked: the grade, the proposed value, the reason, the value before and
	 * the requester are put back.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#scenario-a-second-person-approves-a-correction
	 */
	public function testAnUpdateKeepsTheRequestAsAsked(): void {
		$stored = [
			'gradeEntryId'  => self::ENTRY,
			'proposedValue' => 6.5,
			'reason'        => 'A page of the exam was not counted.',
			'currentValue'  => 5.5,
			'requestedBy'   => 'teacher-a',
			'lifecycle'     => 'requested',
		];
		$edited = array_merge($stored, ['proposedValue' => 9.0, 'reason' => 'changed', 'requestedBy' => 'principal-b', 'currentValue' => 1.0]);
		$event  = new ObjectUpdatingEvent(
			OrEntityFactory::make($edited, 'data-correction-request'),
			OrEntityFactory::make($stored, 'data-correction-request')
		);
		$this->makeStamp(userId: 'principal-b')->handle($event);

		$data = $event->getModifiedData();
		foreach (['gradeEntryId', 'proposedValue', 'reason', 'currentValue', 'requestedBy'] as $field) {
			self::assertSame($stored[$field], $data[$field], $field);
		}

		$lost = new ObjectUpdatingEvent(
			OrEntityFactory::make($edited, 'data-correction-request'),
			OrEntityFactory::make(array_merge($stored, ['requestedBy' => '']), 'data-correction-request')
		);
		$this->makeStamp(userId: 'principal-b')->handle($lost);
		self::assertSame('correction-request-lost', $lost->getErrors()['reason']);
	}//end testAnUpdateKeepsTheRequestAsAsked()
}//end class
