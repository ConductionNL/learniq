<?php

/**
 * Learniq PeerReviewWorkProjection unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
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
 * @spec openspec/specs/assignments/spec.md#requirement-a-reviewer-reads-the-work-through-a-server-side-projection
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\PeerReviewWorkProjection;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Files\File;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for PeerReviewWorkProjection.
 */
class PeerReviewWorkProjectionTest extends TestCase {

	/**
	 * Fake rows keyed by schema, then id.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $db = [];

	/**
	 * RBAC flags of every find(), to prove the reads bypass RBAC.
	 *
	 * @var array<int, bool>
	 */
	private array $rbacFlags = [];

	/**
	 * Seed one double-blind review of Alice's submission by Bob.
	 *
	 * @param string|null $anonymity The Assignment's anonymity, null to leave it unset.
	 *
	 * @return void
	 */
	private function seed(?string $anonymity = 'double-blind'): void {
		$assignment = ['id' => 'asn-1', 'title' => 'Essay'];
		if ($anonymity !== null) {
			$assignment['peerReviewAnonymity'] = $anonymity;
		}

		$this->db = [
			'peer-review' => ['pr-1' => ['id' => 'pr-1', 'assignmentId' => 'asn-1', 'submissionId' => 'sub-1', 'reviewerId' => 'bob']],
			'assignment' => ['asn-1' => $assignment],
			'submission' => [
				'sub-1' => [
					'id' => 'sub-1',
					'assignmentId' => 'asn-1',
					'learnerIds' => ['alice'],
					'learnerRefs' => ['lp-alice'],
					'submittedAt' => '2026-09-20T10:00:00+00:00',
					'feedbackText' => 'Good start',
					'proposedGrade' => 7.5,
					'rubricScores' => [['criterionId' => 'c1', 'points' => 3]],
					'gradeEntryId' => 'ge-1',
				],
			],
		];
	}//end seed()

	/**
	 * A file double.
	 *
	 * @param int $id File id.
	 * @param string $name File name.
	 *
	 * @return File
	 */
	private function file(int $id, string $name): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getName')->willReturn($name);
		$file->method('getSize')->willReturn(1024);
		$file->method('getMimeType')->willReturn('application/pdf');
		$file->method('getContent')->willReturn('%PDF');
		return $file;
	}//end file()

	/**
	 * Build the projection over the fake store.
	 *
	 * @param array<int, string> $admins Uids that are admins.
	 * @param bool $filesThrow Whether listing files fails.
	 *
	 * @return PeerReviewWorkProjection
	 */
	private function makeProjection(array $admins = [], bool $filesThrow = false): PeerReviewWorkProjection {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null, bool $_rbac = true) {
				$this->rbacFlags[] = $_rbac;
				$row = ($this->db[(string)$schema][(string)$id] ?? null);
				if ($row === null) {
					return null;
				}

				return OrEntityFactory::make($row, (string)$schema);
			}
		);

		$fileService = $this->createMock(FileService::class);
		if ($filesThrow === true) {
			$fileService->method('getFiles')->willThrowException(new RuntimeException('no folder'));
		} else {
			$fileService->method('getFiles')->willReturn([$this->file(12, 'Alice_de_Vries_essay.pdf'), $this->file(7, 'bronnen.docx')]);
		}

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => in_array($uid, $admins, true));

		return new PeerReviewWorkProjection(
			objectService: $objectService,
			fileService: $fileService,
			groupManager: $groupManager,
			logger: new NullLogger(),
		);
	}//end makeProjection()

	/**
	 * A double-blind reviewer gets the work without the author, and neutral file names.
	 *
	 * @return void
	 */
	public function testADoubleBlindReviewerDoesNotLearnTheAuthor(): void {
		$this->seed();
		$projection = $this->makeProjection();
		$context = $projection->resolve(peerReviewId: 'pr-1', callerId: 'bob');
		$work = $projection->project(context: $context);

		self::assertSame(PeerReviewWorkProjection::ALLOWED, $context['verdict']);
		self::assertNull($work['authorIds']);
		self::assertSame('double-blind', $work['anonymity']);
		self::assertSame(['file-1.docx', 'file-2.pdf'], array_column($work['files'], 'name'));
		self::assertStringNotContainsString('alice', strtolower((string)json_encode($work)));
		self::assertStringNotContainsString('vries', strtolower((string)json_encode($work)));
		self::assertNotContains(true, $this->rbacFlags);
	}//end testADoubleBlindReviewerDoesNotLearnTheAuthor()

	/**
	 * Blind and open reviews show the reviewer who wrote the work.
	 *
	 * @return void
	 */
	public function testBlindAndOpenShowTheAuthor(): void {
		foreach (['blind', 'open'] as $anonymity) {
			$this->seed(anonymity: $anonymity);
			$projection = $this->makeProjection();
			$work = $projection->project(context: $projection->resolve(peerReviewId: 'pr-1', callerId: 'bob'));

			self::assertSame(['alice'], $work['authorIds'], $anonymity);
			self::assertSame(['bronnen.docx', 'Alice_de_Vries_essay.pdf'], array_column($work['files'], 'name'), $anonymity);
		}
	}//end testBlindAndOpenShowTheAuthor()

	/**
	 * Unset anonymity means the schema default `blind`; an unreadable assignment withholds.
	 *
	 * @return void
	 */
	public function testMissingAnonymityDefaultsAndAMissingAssignmentWithholds(): void {
		$this->seed(anonymity: null);
		$projection = $this->makeProjection();
		self::assertSame(['alice'], $projection->project(context: $projection->resolve(peerReviewId: 'pr-1', callerId: 'bob'))['authorIds']);

		unset($this->db['assignment']['asn-1']);
		$context = $projection->resolve(peerReviewId: 'pr-1', callerId: 'bob');
		self::assertTrue($context['hideAuthor']);
		self::assertNull($projection->project(context: $context)['authorIds']);
	}//end testMissingAnonymityDefaultsAndAMissingAssignmentWithholds()

	/**
	 * The teacher's marking and the portal keys are never in the projection.
	 *
	 * @return void
	 */
	public function testMarkingIsNeverProjected(): void {
		$this->seed(anonymity: 'open');
		$projection = $this->makeProjection();
		$work = $projection->project(context: $projection->resolve(peerReviewId: 'pr-1', callerId: 'bob'));

		foreach (['feedbackText', 'proposedGrade', 'rubricScores', 'gradeEntryId', 'learnerRefs', 'learnerIds'] as $field) {
			self::assertArrayNotHasKey($field, $work);
		}

		self::assertSame(
			['peerReviewId', 'submissionId', 'assignmentId', 'submittedAt', 'anonymity', 'authorIds', 'files'],
			array_keys($work)
		);
	}//end testMarkingIsNeverProjected()

	/**
	 * Someone who is neither the reviewer nor an admin is refused; an admin
	 * (not the reviewer) sees the author even for double-blind.
	 *
	 * @return void
	 */
	public function testOnlyTheReviewerOrAnAdminGetsIn(): void {
		$this->seed();
		$projection = $this->makeProjection(admins: ['root']);

		self::assertSame(PeerReviewWorkProjection::FORBIDDEN, $projection->resolve(peerReviewId: 'pr-1', callerId: 'alice')['verdict']);
		self::assertSame(PeerReviewWorkProjection::FORBIDDEN, $projection->resolve(peerReviewId: 'pr-1', callerId: 'mallory')['verdict']);
		self::assertSame(PeerReviewWorkProjection::FORBIDDEN, $projection->resolve(peerReviewId: 'pr-1', callerId: '')['verdict']);

		$admin = $projection->resolve(peerReviewId: 'pr-1', callerId: 'root');
		self::assertSame(PeerReviewWorkProjection::ALLOWED, $admin['verdict']);
		self::assertSame(['alice'], $projection->project(context: $admin)['authorIds']);
	}//end testOnlyTheReviewerOrAnAdminGetsIn()

	/**
	 * An unknown review, or one whose submission is gone, is not found.
	 *
	 * @return void
	 */
	public function testUnknownReviewOrSubmissionIsNotFound(): void {
		$this->seed();
		$projection = $this->makeProjection();

		self::assertSame(PeerReviewWorkProjection::NOT_FOUND, $projection->resolve(peerReviewId: 'pr-9', callerId: 'bob')['verdict']);

		unset($this->db['submission']['sub-1']);
		self::assertSame(PeerReviewWorkProjection::NOT_FOUND, $projection->resolve(peerReviewId: 'pr-1', callerId: 'bob')['verdict']);
	}//end testUnknownReviewOrSubmissionIsNotFound()

	/**
	 * A file is served only when it is one of the reviewed submission's files,
	 * under the name the reviewer may see.
	 *
	 * @return void
	 */
	public function testOnlyTheSubmissionsOwnFilesAreServed(): void {
		$this->seed();
		$projection = $this->makeProjection();
		$context = $projection->resolve(peerReviewId: 'pr-1', callerId: 'bob');

		$found = $projection->file(context: $context, fileId: '12');
		self::assertNotNull($found);
		self::assertSame('file-2.pdf', $found['name']);
		self::assertNull($projection->file(context: $context, fileId: '999'));
	}//end testOnlyTheSubmissionsOwnFilesAreServed()

	/**
	 * A submission without a file folder projects no files, not an error.
	 *
	 * @return void
	 */
	public function testNoFolderMeansNoFiles(): void {
		$this->seed();
		$projection = $this->makeProjection(filesThrow: true);

		self::assertSame([], $projection->project(context: $projection->resolve(peerReviewId: 'pr-1', callerId: 'bob'))['files']);
	}//end testNoFolderMeansNoFiles()
}//end class
