<?php

/**
 * Contract of PeerReviewWorkProjection
 *
 * The public surface of PeerReviewWorkProjection that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Learniq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Kernel\Contracts;

use OCP\Files\File;

/**
 * Contract of PeerReviewWorkProjection.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PeerReviewWorkProjectionInterface {

	/**
	 * Verdict: the caller may see the projection.
	 */
	public const ALLOWED = 'allowed';

	/**
	 * Verdict: the caller is neither the reviewer nor an admin.
	 */
	public const FORBIDDEN = 'forbidden';

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
	public function file(array $context, string $fileId): ?array;

	/**
	 * The reviewer-facing projection of a resolved PeerReview.
	 *
	 * @param array<string, mixed> $context Output of resolve() when allowed.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/assignments/spec.md#requirement-a-reviewer-reads-the-work-through-a-server-side-projection
	 */
	public function project(array $context): array;

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
	public function resolve(string $peerReviewId, string $callerId): array;
}//end interface
