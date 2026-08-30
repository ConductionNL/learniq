<?php

/**
 * Tests for the route table's controller-method contract.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\AppInfo
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\AppInfo;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Learniq does not use `AppHost\Routes::standard()`; it hand-declares its route
 * table. That makes the table the single source of truth for which controller
 * methods must exist, and it fails in two distinct ways:
 *
 *   - A route entry naming a method the controller does not have: the router
 *     matches the URL, the dispatcher reflects the method, and the request
 *     dies with a **500**.
 *   - A canonical verb with no route entry at all: the router never matches
 *     and the request dies with a **405** — which is what
 *     `PUT /api/settings` did before this test existed (measured 2026-08-08
 *     against the dev instance: GET/POST reached the CSRF middleware and
 *     returned 412, PUT returned 405 with an empty body, i.e. no route).
 *
 * `OCA\OpenRegister\AppHost\Bootstrap::aliasControllerUnlessLeafDefinesIt()`
 * only substitutes a generic controller when the leaf app does NOT ship a
 * class of that name. Learniq ships `lib/Controller/SettingsController.php`,
 * so the generic is never constructed for `settings#*` and the leaf owes every
 * method routed there. (The absence of `PreferencesController` /
 * `HealthController` / `MetricsController` on disk is correct and must not be
 * "fixed" by creating them — those genuinely are the generics.)
 *
 * These tests assert the ITEM — each individual method — never the container,
 * i.e. never merely that the controller class exists.
 */
class CanonicalRouteMethodContractTest extends TestCase {

	/**
	 * Load and evaluate the real route table.
	 *
	 * `appinfo/routes.php` is a side-effect-free `return [...]`, so this
	 * evaluates the array the router actually consumes rather than grepping
	 * the source for a string — a grep would be satisfied by the name
	 * appearing in a comment.
	 *
	 * @return array<int, array<string, mixed>> The declared route entries.
	 */
	private function routes(): array {
		$table = require __DIR__ . '/../../../appinfo/routes.php';

		$this->assertIsArray($table, 'appinfo/routes.php must evaluate to an array');
		$this->assertArrayHasKey('routes', $table, "appinfo/routes.php must declare a 'routes' key");
		$this->assertIsArray($table['routes'], "the 'routes' key must hold an array");

		return $table['routes'];
	}//end routes()

	/**
	 * `PUT /api/settings` must be declared as `settings#update`.
	 *
	 * The canonical AppHost dialect (ADR-066) makes PUT the settings write and
	 * POST the retained legacy alias. Without this entry the verb is a 405 and
	 * any caller speaking the canonical dialect — including the AppHost
	 * contract collection — cannot write Learniq's settings at all.
	 *
	 * @return void
	 */
	public function testPutApiSettingsIsRoutedToSettingsUpdate(): void {
		$matches = array_values(
			array_filter(
				$this->routes(),
				static function (array $route): bool {
					return ($route['url'] ?? null) === '/api/settings'
						&& strtoupper((string)($route['verb'] ?? '')) === 'PUT';
				}
			)
		);

		$this->assertCount(
			1,
			$matches,
			'Exactly one route entry must serve PUT /api/settings; found ' . count($matches)
		);

		$this->assertSame(
			'settings#update',
			$matches[0]['name'],
			'PUT /api/settings must dispatch to settings#update (the canonical AppHost write)'
		);

	}//end testPutApiSettingsIsRoutedToSettingsUpdate()

	/**
	 * The legacy POST alias must survive alongside the canonical PUT.
	 *
	 * Both of Learniq's own frontend writers still POST to `/api/settings`
	 * (`src/store/modules/settings.js::saveSettings()` and
	 * `src/views/LearniqSettings.vue::saveDefaultRegister()`), so this change
	 * is a strict addition — removing the alias would break them.
	 *
	 * @return void
	 */
	public function testLegacyPostApiSettingsAliasIsStillDeclared(): void {
		$matches = array_values(
			array_filter(
				$this->routes(),
				static function (array $route): bool {
					return ($route['url'] ?? null) === '/api/settings'
						&& strtoupper((string)($route['verb'] ?? '')) === 'POST';
				}
			)
		);

		$this->assertCount(1, $matches, 'POST /api/settings must remain declared exactly once');
		$this->assertSame('settings#create', $matches[0]['name']);

	}//end testLegacyPostApiSettingsAliasIsStillDeclared()

	/**
	 * Every route entry pointing at a controller Learniq ships itself must
	 * name a method that exists and is public.
	 *
	 * Derived from the live route array rather than a hardcoded list, so a
	 * future route entry is covered the moment it is added.
	 *
	 * @return void
	 */
	public function testEveryRouteTargetsAnExistingPublicMethodOnALeafOwnedController(): void {
		$inspected = 0;
		$skipped = 0;
		$missing = [];

		foreach ($this->routes() as $route) {
			$name = ($route['name'] ?? '');
			if (is_string($name) === false || str_contains($name, '#') === false) {
				continue;
			}

			[$prefix, $method] = explode('#', $name, 2);

			// The class file existing ON DISK is what makes AppHost skip the
			// alias. `class_exists()` alone would also be satisfied by a DI
			// alias target in a booted container — precisely the case this
			// test must NOT treat as leaf-owned.
			$file = __DIR__ . '/../../../lib/Controller/' . ucfirst($prefix) . 'Controller.php';
			if (file_exists($file) === false) {
				$skipped++;
				continue;
			}

			$class = 'OCA\\Learniq\\Controller\\' . ucfirst($prefix) . 'Controller';

			$this->assertTrue(
				class_exists($class),
				sprintf('%s exists on disk but does not autoload as %s', $file, $class)
			);

			$inspected++;

			$reflection = new ReflectionClass($class);

			if ($reflection->hasMethod($method) === false) {
				$missing[] = ucfirst($prefix) . 'Controller::' . $method . '()';
				continue;
			}

			$this->assertTrue(
				$reflection->getMethod($method)->isPublic(),
				sprintf('%s::%s() must be public to be dispatchable', $class, $method)
			);
		}//end foreach

		// Positive control: "no missing methods" is only meaningful if
		// something was actually inspected. Zero inspections would mean the
		// route parsing or the lib/Controller path probe silently matched
		// nothing, and the empty finding list would say nothing at all.
		$this->assertGreaterThan(
			10,
			$inspected,
			'Fewer than 11 leaf-owned route targets were inspected (' . $inspected . ' inspected, '
			. $skipped . ' skipped as AppHost generics) — the probe is broken, so an empty '
			. 'finding list means nothing.'
		);

		$this->assertSame(
			[],
			$missing,
			"Route entries name method(s) that do not exist on Learniq's own controllers. "
			. 'Learniq ships these classes, so no AppHost generic is aliased in to cover them — '
			. "each of these is a 500 at runtime, not a 404:\n  - " . implode("\n  - ", $missing)
		);

	}//end testEveryRouteTargetsAnExistingPublicMethodOnALeafOwnedController()

	/**
	 * The canonical settings surface must be present on the bespoke
	 * controller: index (read), update (canonical write), create (legacy
	 * write alias) and load (register re-import).
	 *
	 * Asserted per method rather than by reflecting the class as a whole, so
	 * one missing method is one named failure.
	 *
	 * @return void
	 */
	public function testBespokeSettingsControllerImplementsTheFullCanonicalSurface(): void {
		$reflection = new ReflectionClass(\OCA\Learniq\Controller\SettingsController::class);

		foreach (['index', 'update', 'create', 'load'] as $method) {
			$this->assertTrue(
				$reflection->hasMethod($method),
				sprintf('SettingsController::%s() is missing from the canonical settings surface', $method)
			);

			$this->assertTrue(
				$reflection->getMethod($method)->isPublic(),
				sprintf('SettingsController::%s() must be public to be dispatchable', $method)
			);
		}

	}//end testBespokeSettingsControllerImplementsTheFullCanonicalSurface()

	/**
	 * The settings write must stay admin-gated on BOTH verbs.
	 *
	 * NC's SecurityMiddleware evaluates the attributes of the DISPATCHED
	 * method only, so `create()` delegating to `update()` does not inherit
	 * `update()`'s gate — dropping the attribute from either method silently
	 * opens an instance-wide config write to any authenticated user.
	 *
	 * @return void
	 */
	public function testBothSettingsWriteVerbsCarryTheAdminAttributeIndependently(): void {
		$reflection = new ReflectionClass(\OCA\Learniq\Controller\SettingsController::class);
		$checked = 0;

		foreach (['update', 'create', 'load', 'index'] as $method) {
			$attributes = $reflection->getMethod($method)->getAttributes(
				\OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting::class
			);

			$this->assertCount(
				1,
				$attributes,
				sprintf(
					'SettingsController::%s() must declare #[AuthorizedAdminSetting] in its own right',
					$method
				)
			);

			$this->assertSame(
				[\OCA\Learniq\Settings\AdminSettings::class],
				array_values($attributes[0]->getArguments()),
				sprintf(
					"SettingsController::%s() must bind Learniq's own AdminSettings panel",
					$method
				)
			);

			$checked++;
		}//end foreach

		// Positive control for the attribute scan above.
		$this->assertSame(4, $checked, 'All four settings methods must have been checked');

	}//end testBothSettingsWriteVerbsCarryTheAdminAttributeIndependently()

}//end class
