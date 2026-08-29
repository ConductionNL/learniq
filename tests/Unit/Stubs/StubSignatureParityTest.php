<?php

/**
 * Every OpenRegister stub must carry canonical parameter NAMES.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Stubs;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * learniq maps `OCA\OpenRegister\` at `tests/Stubs/`, so a unit run resolves
 * those classes to stubs while CI — which installs the real app — resolves
 * them to openregister itself.
 *
 * THAT ASYMMETRY IS THE HAZARD. learniq calls these with PHP NAMED arguments,
 * which bind by NAME, so a stub whose parameter names differ from canonical
 * passes every local test and fails only in CI, with an error that names the
 * stub's fiction rather than the caller's mistake.
 *
 * Measured: `DeferredListenerContext` was stubbed as `__construct(array
 * $entries)` when canonical is `__construct(?string $userId, ?string $orgUuid,
 * array $entries)`. Eighteen job tests passed locally; all six PHPUnit cells
 * failed in CI with
 *
 *     Argument #1 ($userId) must be of type ?string, array given
 *
 * This test closes that gap by comparing the LOADED class against the stub
 * FILE. When the real app is present — i.e. in CI — the comparison is real.
 */
class StubSignatureParityTest extends TestCase {

	/**
	 * Stub file → the class it stands in for.
	 *
	 * @return array<string, array{0: string, 1: string}> The cases.
	 */
	public static function stubProvider(): array {
		$root = __DIR__ . '/../../Stubs/';
		return [
			'ListenerDeferralService' => [
				$root . 'Service/Deferral/ListenerDeferralService.php',
				'OCA\OpenRegister\Service\Deferral\ListenerDeferralService',
				'lib/Service/Deferral/ListenerDeferralService.php',
			],
			'DeferredListenerContext' => [
				$root . 'Service/Deferral/DeferredListenerContext.php',
				'OCA\OpenRegister\Service\Deferral\DeferredListenerContext',
				'lib/Service/Deferral/DeferredListenerContext.php',
			],
			'ActorForwardedJob' => [
				$root . 'BackgroundJob/ActorForwardedJob.php',
				'OCA\OpenRegister\BackgroundJob\ActorForwardedJob',
				'lib/BackgroundJob/ActorForwardedJob.php',
			],
		];
	}//end stubProvider()

	/**
	 * The loaded class and the stub file agree on every parameter name.
	 *
	 * @param string $stubPath  Path to the stub file.
	 * @param string $className The class it stands in for.
	 * @param string $canonicalRelative Path to the canonical file inside openregister.
	 *
	 * @return void
	 *
	 * @dataProvider stubProvider
	 *
	 * @spec exclude Test-harness integrity check; no capability spec describes
	 *  the shape of a test stub.
	 */
	public function testStubParameterNamesMatchTheLoadedClass(string $stubPath, string $className, string $canonicalRelative): void {
		self::assertFileExists($stubPath, 'the stub itself must exist');
		self::assertTrue(class_exists($className), $className . ' must resolve to something');

		$reflection = new ReflectionClass($className);
		$loadedFrom = (string)$reflection->getFileName();

		$stubSource = (string)file_get_contents($stubPath);
		$stubIsWhatLoaded = (realpath($loadedFrom) === realpath($stubPath));

		if ($stubIsWhatLoaded === false) {
			// CI: the real app is installed, so reflect it directly.
			foreach ($reflection->getMethods() as $method) {
				$stubParams = $this->parseParameterNames($stubSource, $method->getName());
				if ($stubParams === null) {
					continue;
				}

				$realParams = array_map(
					static fn (\ReflectionParameter $p): string => $p->getName(),
					$method->getParameters()
				);
				$this->assertParams($realParams, $stubParams, $className, $method->getName());
			}

			return;
		}

		// Local: the stub is what loaded, so reflection would compare it to
		// itself. Read canonical off disk instead — a checkout of openregister
		// sits beside this one in the workspace, and inside server/apps in CI.
		$canonical = $this->locateCanonical($canonicalRelative);
		self::assertNotNull(
			$canonical,
			'neither the real class nor an openregister checkout was found, so stub parity '
			. 'is UNVERIFIED. Reporting that rather than passing: this check is worthless '
			. 'if it can silently compare nothing.'
		);

		$canonicalSource = (string)file_get_contents($canonical);
		foreach ($this->declaredMethods($stubSource) as $name) {
			$realParams = $this->parseParameterNames($canonicalSource, $name);
			if ($realParams === null) {
				self::fail($className . '::' . $name . '() is stubbed but absent from canonical ' . $canonical);
			}

			$this->assertParams($realParams, $this->parseParameterNames($stubSource, $name) ?? [], $className, $name);
		}
	}//end testStubParameterNamesMatchTheLoadedClass()

	/**
	 * Assert one method's parameter names.
	 *
	 * @param array<int, string> $real   Canonical names, in order.
	 * @param array<int, string> $stub   Stub names, in order.
	 * @param string             $class  The class, for the message.
	 * @param string             $method The method, for the message.
	 *
	 * @return void
	 */
	private function assertParams(array $real, array $stub, string $class, string $method): void {
		self::assertSame(
			$real,
			$stub,
			$class . '::' . $method . '() — the stub\'s parameter NAMES must match the real class '
			. 'exactly, because callers bind to them by name'
		);
	}//end assertParams()

	/**
	 * An openregister checkout, wherever it is.
	 *
	 * @param string $relative Path inside the app.
	 *
	 * @return string|null The absolute path, or null when not found.
	 */
	private function locateCanonical(string $relative): ?string {
		$candidates = [
			__DIR__ . '/../../../../openregister/' . $relative,
			__DIR__ . '/../../../../../apps/openregister/' . $relative,
			__DIR__ . '/../../../openregister/' . $relative,
		];
		foreach ($candidates as $candidate) {
			if (file_exists($candidate) === true) {
				return $candidate;
			}
		}

		return null;
	}//end locateCanonical()

	/**
	 * Method names the stub declares.
	 *
	 * @param string $source The stub source.
	 *
	 * @return array<int, string> The names.
	 */
	private function declaredMethods(string $source): array {
		preg_match_all('/function\s+(\w+)\s*\(/', $source, $m);
		return $m[1];
	}//end declaredMethods()

	/**
	 * Parameter names for one method, read out of source text.
	 *
	 * @param string $source The stub file's contents.
	 * @param string $method The method name.
	 *
	 * @return array<int, string>|null The names in order, or null when absent.
	 */
	private function parseParameterNames(string $source, string $method): ?array {
		$pattern = '/function\s+' . preg_quote($method, '/') . '\s*\(([^)]*)\)/s';
		if (preg_match($pattern, $source, $m) !== 1) {
			return null;
		}

		preg_match_all('/\$(\w+)/', $m[1], $names);
		return $names[1];
	}//end parseParameterNames()
}//end class
