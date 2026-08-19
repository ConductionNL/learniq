<?php

/**
 * Test stub for OCA\OpenRegister\Service\AuditHashService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Stubs\Service
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * Minimal AuditHashService stub for Scholiq unit tests.
 */
abstract class AuditHashService {
	/**
	 * Parameter names MUST match canonical OpenRegister
	 * (`lib/Service/AuditHashService.php` on origin/development) exactly —
	 * scholiq calls this with PHP named arguments, which bind by name, so a
	 * stub that invents parameter names makes an `Unknown named parameter`
	 * runtime error invisible to psalm, phpstan and the mock-based tests.
	 *
	 * @param int|null $from
	 * @param int|null $to
	 * @return array<string,mixed>
	 */
	abstract public function verifyChain(?int $from = null, ?int $to = null): array;
}//end class
