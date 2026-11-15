<?php

/**
 * A logger that keeps every call, so a test can assert what was never logged.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Support
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
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Keeps level, message and context of every call.
 */
class CapturingLogger extends AbstractLogger {

	/**
	 * Every call.
	 *
	 * @var array<int, array{level: mixed, message: string, context: array<mixed>}>
	 */
	public array $records = [];

	/**
	 * Keep one call.
	 *
	 * @param mixed             $level   The level.
	 * @param string|Stringable $message The message.
	 * @param array<mixed>      $context The context.
	 *
	 * @return void
	 */
	public function log($level, string|Stringable $message, array $context = []): void {
		$this->records[] = ['level' => $level, 'message' => (string)$message, 'context' => $context];
	}//end log()

	/**
	 * Everything logged, messages and context, as one string to search.
	 *
	 * @return string The dump.
	 */
	public function dump(): string {
		return (string)json_encode($this->records);
	}//end dump()
}//end class
