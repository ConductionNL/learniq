<?php

/**
 * The whitelisted body and string parameters a portal form posts.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\PortalFormFields;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * PortalFormFields.
 *
 * @covers \OCA\Learniq\Controller\PortalFormFields
 */
class PortalFormFieldsTest extends TestCase {

	/**
	 * A host over a request carrying the given parameters.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return object
	 */
	private function host(array $params): object {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);

		return new class ($request) {
			use PortalFormFields;

			private const FIELDS = ['learnerRef', 'answer', 'criteria'];

			public function __construct(private readonly IRequest $request) {
			}

			public function sentBody(): array {
				return $this->body();
			}

			public function param(string $name): string {
				return $this->stringParam(name: $name);
			}
		};
	}//end host()

	/**
	 * The body holds only the whitelisted fields the form actually sent, values untouched.
	 *
	 * @return void
	 */
	public function testTheBodyHoldsOnlyTheSentWhitelistedFields(): void {
		$host = $this->host(['learnerRef' => ' p-1 ', 'criteria' => ['a' => 1], 'lifecycle' => 'approved', 'answer' => null]);

		$this->assertSame(['learnerRef' => ' p-1 ', 'criteria' => ['a' => 1]], $host->sentBody());
		$this->assertSame([], $this->host([])->sentBody());
	}//end testTheBodyHoldsOnlyTheSentWhitelistedFields()

	/**
	 * A string parameter is trimmed; an absent or non-string one is ''.
	 *
	 * @return void
	 */
	public function testAStringParameterIsTrimmedElseEmpty(): void {
		$host = $this->host(['practicalTrainerId' => "  t-1\n", 'count' => 3, 'list' => ['x']]);

		$this->assertSame('t-1', $host->param('practicalTrainerId'));
		$this->assertSame('', $host->param('count'));
		$this->assertSame('', $host->param('list'));
		$this->assertSame('', $host->param('missing'));
	}//end testAStringParameterIsTrimmedElseEmpty()
}//end class
