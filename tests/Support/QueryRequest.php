<?php

/**
 * An IRequest with a real `get` property, for code that reads the query string.
 *
 * `OCP\IRequest` exposes the query string as the `$get` magic property, which a
 * plain interface double cannot carry. Mock this class instead and set `$get`.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Support
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
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Support;

use OCP\IRequest;

/**
 * A request whose query parameters a test can set.
 */
abstract class QueryRequest implements IRequest {

	/**
	 * The query-string parameters.
	 *
	 * @var array<string, mixed>
	 */
	public array $get = [];
}//end class
