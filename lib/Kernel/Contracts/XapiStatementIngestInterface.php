<?php

/**
 * Contract of XapiStatementIngest
 *
 * The public surface of XapiStatementIngest that other components call, so they depend
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

use InvalidArgumentException;

/**
 * Contract of XapiStatementIngest.
 *
 * @category Kernel
 * @package  OCA\Learniq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/learniq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface XapiStatementIngestInterface {

	/**
	 * Store a batch of statements for one authenticated actor.
	 *
	 * @param array<int, array<string, mixed>> $statements The statements.
	 * @param string                           $actorId    The authenticated Nextcloud uid.
	 * @param array{lessonId?: string, courseId?: string} $launch The launch context from a token, if any; it wins over the body.
	 *
	 * @return array<int, string> The stored statement ids, in order.
	 *
	 * @throws InvalidArgumentException When the batch is empty, too large, or a statement is malformed.
	 *
	 * @spec openspec/changes/archive/2026-09-30-cmi5-xapi-lrs-ingest/tasks.md#3-lrs-ingest-controller
	 */
	public function ingest(array $statements, string $actorId, array $launch = []): array;

	/**
	 * Read statements; a non-admin caller only ever sees their own.
	 *
	 * @param string              $callerId The signed-in uid.
	 * @param bool                $isAdmin  Whether the caller is an admin.
	 * @param array<string, string> $filters Optional `lessonId` and `courseId` filters.
	 * @param int                 $limit    Page size, capped at MAX_LIMIT.
	 *
	 * @return array<int, mixed> The matching statements.
	 *
	 * @spec openspec/changes/archive/2026-09-30-cmi5-xapi-lrs-ingest/tasks.md#3-lrs-ingest-controller
	 */
	public function query(string $callerId, bool $isAdmin, array $filters, int $limit): array;
}//end interface
