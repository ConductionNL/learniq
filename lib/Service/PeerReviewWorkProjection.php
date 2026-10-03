<?php

/**
 * Learniq Peer Review Work Projection
 *
 * What a peer reviewer is shown of the work they review. A reviewer may not
 * read another learner's Submission (its read rule is staff plus the authors),
 * so the reviewer reads this projection instead, built by the server:
 *
 * - the work: when it was handed in and its files;
 * - the authors' user ids, except when the Assignment's peerReviewAnonymity is
 *   `double-blind` and the caller is the reviewer: then they are withheld, and
 *   the file names are replaced by neutral ones so a name in a file name does
 *   not give the author away either;
 * - never the teacher's marking (feedback, rubric scores, proposed grade,
 *   grade entry) or the portal keys (learnerRefs).
 *
 * This makes the reviewee side of double-blind a server-enforced guarantee on
 * the reviewer's path, mirroring PeerFeedbackAggregator's projection on the
 * author's side. Reads run without RBAC because the caller check is done here:
 * the PeerReview's reviewer, or an admin.
 *
 * @category Service
 * @package  OCA\Learniq\Service
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

namespace OCA\Learniq\Service;

use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Files\File;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Builds the reviewer-facing view of a PeerReview's Submission.
 *
 * @spec openspec/specs/assignments/spec.md#requirement-a-reviewer-reads-the-work-through-a-server-side-projection
 */
class PeerReviewWorkProjection {

	private const LEARNIQ_REGISTER = 'learniq';
	private const PEER_REVIEW_SCHEMA = 'peer-review';
	private const ASSIGNMENT_SCHEMA = 'assignment';
	private const SUBMISSION_SCHEMA = 'submission';
	private const DOUBLE_BLIND = 'double-blind';

	/**
	 * Verdict: the caller may see the projection.
	 */
	public const ALLOWED = 'allowed';

	/**
	 * Verdict: no such PeerReview (or its Submission is gone).
	 */
	public const NOT_FOUND = 'not-found';

	/**
	 * Verdict: the caller is neither the reviewer nor an admin.
	 */
	public const FORBIDDEN = 'forbidden';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object access.
	 * @param FileService $fileService OpenRegister file access (the Submission's files).
	 * @param IGroupManager $groupManager Admin check.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly FileService $fileService,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolve a PeerReview for a caller: the verdict, and when allowed the
	 * loaded context the projection and the file download are built from.
	 *
	 * @param string $peerReviewId UUID of the PeerReview.
	 * @param string $callerId Nextcloud uid of the caller.
	 *
	 * @return array{verdict: string, review?: array<string, mixed>, anonymity?: string, submission?: array<string, mixed>, hideAuthor?: bool}
	 *
	 * @spec openspec/specs/assignments/spec.md#requirement-a-reviewer-reads-the-work-through-a-server-side-projection
	 */
	public function resolve(string $peerReviewId, string $callerId): array {
		$review = $this->load(id: $peerReviewId, schema: self::PEER_REVIEW_SCHEMA);
		if ($review === null) {
			return ['verdict' => self::NOT_FOUND];
		}

		$isReviewer = ($callerId !== '' && ($review['reviewerId'] ?? null) === $callerId);
		if ($isReviewer === false && ($callerId === '' || $this->groupManager->isAdmin($callerId) === false)) {
			return ['verdict' => self::FORBIDDEN];
		}

		$submission = $this->load(id: (string)($review['submissionId'] ?? ''), schema: self::SUBMISSION_SCHEMA);
		if ($submission === null) {
			return ['verdict' => self::NOT_FOUND];
		}

		$assignment = $this->load(id: (string)($review['assignmentId'] ?? ''), schema: self::ASSIGNMENT_SCHEMA);

		return [
			'verdict' => self::ALLOWED,
			'review' => $review,
			'anonymity' => $this->anonymity(assignment: $assignment),
			'submission' => $submission,
			'hideAuthor' => $isReviewer === true && $this->anonymity(assignment: $assignment) === self::DOUBLE_BLIND,
		];
	}//end resolve()

	/**
	 * The reviewer-facing projection of a resolved PeerReview.
	 *
	 * @param array<string, mixed> $context Output of resolve() when allowed.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/assignments/spec.md#requirement-a-reviewer-reads-the-work-through-a-server-side-projection
	 */
	public function project(array $context): array {
		$submission = (array)($context['submission'] ?? []);
		$authorIds = null;
		if (($context['hideAuthor'] ?? true) === false) {
			$authorIds = array_values(array_filter((array)($submission['learnerIds'] ?? []), 'is_string'));
		}

		$files = [];
		foreach ($this->files(context: $context) as $position => $file) {
			$files[] = [
				'id' => (string)$file->getId(),
				'name' => $this->displayName(file: $file, position: $position, hideAuthor: ($context['hideAuthor'] ?? true) === true),
				'size' => $file->getSize(),
				'mimetype' => $file->getMimeType(),
			];
		}

		return [
			'peerReviewId' => (string)(((array)($context['review'] ?? []))['id'] ?? ''),
			'submissionId' => (string)($submission['id'] ?? ''),
			'assignmentId' => (string)($submission['assignmentId'] ?? ''),
			'submittedAt' => ($submission['submittedAt'] ?? null),
			'anonymity' => (string)($context['anonymity'] ?? self::DOUBLE_BLIND),
			'authorIds' => $authorIds,
			'files' => $files,
		];
	}//end project()

	/**
	 * One file of the reviewed Submission with the name the reviewer sees, or
	 * null when the id is not one of that Submission's files.
	 *
	 * @param array<string, mixed> $context Output of resolve() when allowed.
	 * @param string $fileId Nextcloud file id.
	 *
	 * @return array{file: File, name: string}|null
	 *
	 * @spec openspec/specs/assignments/spec.md#requirement-a-reviewer-reads-the-work-through-a-server-side-projection
	 */
	public function file(array $context, string $fileId): ?array {
		foreach ($this->files(context: $context) as $position => $file) {
			if ((string)$file->getId() === $fileId) {
				return [
					'file' => $file,
					'name' => $this->displayName(file: $file, position: $position, hideAuthor: ($context['hideAuthor'] ?? true) === true),
				];
			}
		}

		return null;
	}//end file()

	/**
	 * The Submission's files, in a stable order. A missing folder is no files.
	 *
	 * @param array<string, mixed> $context Resolved context.
	 *
	 * @return list<File>
	 */
	private function files(array $context): array {
		$submissionId = (string)(((array)($context['submission'] ?? []))['id'] ?? '');
		if ($submissionId === '') {
			return [];
		}

		try {
			$nodes = $this->fileService->getFiles(object: $submissionId);
		} catch (Throwable $exception) {
			$this->logger->info(
				'[PeerReviewWorkProjection] No files for submission {id}: {msg}',
				['id' => $submissionId, 'msg' => $exception->getMessage()]
			);
			return [];
		}

		$files = array_values(array_filter($nodes, static fn ($node): bool => $node instanceof File));
		usort($files, static fn (File $a, File $b): int => ($a->getId() <=> $b->getId()));

		return $files;
	}//end files()

	/**
	 * The file name the reviewer sees: the real one, or `file-N.ext` when the
	 * author is withheld.
	 *
	 * @param File $file The file.
	 * @param int $position Zero-based position in the stable order.
	 * @param bool $hideAuthor Whether the author is withheld.
	 *
	 * @return string
	 */
	private function displayName(File $file, int $position, bool $hideAuthor): string {
		$name = $file->getName();
		if ($hideAuthor === false) {
			return $name;
		}

		$extension = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
		$neutral = 'file-' . ($position + 1);
		if ($extension !== '' && preg_match('/^[a-z0-9]{1,8}$/', $extension) === 1) {
			$neutral .= '.' . $extension;
		}

		return $neutral;
	}//end displayName()

	/**
	 * The Assignment's anonymity: its own value, the schema default `blind`
	 * when unset, and `double-blind` when the Assignment cannot be read, so
	 * a lookup failure withholds the author instead of revealing them.
	 *
	 * @param array<string, mixed>|null $assignment The Assignment, or null.
	 *
	 * @return string
	 */
	private function anonymity(?array $assignment): string {
		if ($assignment === null) {
			return self::DOUBLE_BLIND;
		}

		$value = ($assignment['peerReviewAnonymity'] ?? 'blind');
		if (in_array($value, ['open', 'blind', self::DOUBLE_BLIND], true) === false) {
			return self::DOUBLE_BLIND;
		}

		return $value;
	}//end anonymity()

	/**
	 * Load one object without RBAC, as an array, or null.
	 *
	 * @param string $id Object UUID.
	 * @param string $schema Schema slug.
	 *
	 * @return array<string, mixed>|null
	 */
	private function load(string $id, string $schema): ?array {
		if ($id === '') {
			return null;
		}

		try {
			$object = $this->objectService->find(
				id: $id,
				register: self::LEARNIQ_REGISTER,
				schema: $schema,
				_rbac: false
			);
		} catch (Throwable $exception) {
			return null;
		}

		if ($object === null) {
			return null;
		}

		return $object->jsonSerialize();
	}//end load()
}//end class
