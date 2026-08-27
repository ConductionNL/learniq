<?php

/**
 * Minimal ListenerDeferralService stub for Learniq unit tests.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Deferral;

/**
 * Buffers post-event work and enqueues it at request shutdown.
 */
abstract class ListenerDeferralService {

	/**
	 * Mirrors the canonical constant so a caller passing `chunkSize:` by name
	 * cannot drift from it.
	 *
	 * @var int
	 */
	public const DEFAULT_CHUNK_SIZE = 100;

	/**
	 * Parameter names MUST match canonical OpenRegister
	 * (`lib/Service/Deferral/ListenerDeferralService.php` on
	 * origin/development) exactly — learniq calls this with PHP named
	 * arguments, which bind by NAME, so a stub that invents parameter names
	 * makes an `Unknown named parameter` runtime error invisible to psalm,
	 * phpstan and the mock-based tests.
	 *
	 * @param string               $jobClass  FQCN of the ActorForwardedJob subclass.
	 * @param array<string, mixed> $entry     Entry payload.
	 * @param int                  $chunkSize Maximum entries per enqueued job.
	 * @param string|null          $dedupeKey Optional coalescing key within the buffer.
	 *
	 * @return void
	 */
	abstract public function defer(
		string $jobClass,
		array $entry,
		int $chunkSize = self::DEFAULT_CHUNK_SIZE,
		?string $dedupeKey = null,
	): void;
}//end class
