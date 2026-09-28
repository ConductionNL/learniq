<?php

/**
 * Learniq MCP scannable services
 *
 * Opts learniq's curated agent tools into OpenRegister's `#[McpTool]`
 * attribute scan (ADR-063 decision 2). Registered under
 * `OCA\OpenRegister\Mcp\IMcpScannableServices::learniq`; learniq ships no
 * `IMcpToolProvider`, so nothing shadows the tools OpenRegister derives from
 * the register.
 *
 * @category Mcp
 * @package  OCA\Learniq\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-no-hand-written-mcp-tool-code-remains-in-scholiq-req-006
 */

declare(strict_types=1);

namespace OCA\Learniq\Mcp;

use OCA\Learniq\Service\LearniqAgentTools;
use OCA\OpenRegister\Mcp\IMcpScannableServices;

/**
 * The services OpenRegister scans for learniq's `#[McpTool]` methods.
 */
class LearniqScannableServices implements IMcpScannableServices {

	/**
	 * The classes carrying `#[McpTool]` methods.
	 *
	 * @return array<int, class-string> The service classes.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-tool-surface/spec.md#requirement-no-hand-written-mcp-tool-code-remains-in-scholiq-req-006
	 */
	public function getScannableServiceClasses(): array {
		return [LearniqAgentTools::class];
	}//end getScannableServiceClasses()
}//end class
