<?php

declare(strict_types=1);

// Define that we're running PHPUnit.
define('PHPUNIT_RUN', 1);

// Include Composer's autoloader.
$autoloader = require __DIR__ . '/../vendor/autoload.php';

// Register the cross-app stub namespaces at test-time on the Composer loader.
// These used to live under composer.json `autoload-dev`, but a dev-built
// vendor/ (as on the shared dev instance) then baked the stubs into the
// RUNTIME classmap, shadowing the real OpenRegister/Talk classes instance-wide
// and 500-ing every app (openregister#2036). Registering them here keeps the
// stubs test-only. Loading is lazy, so ordering vs the OCP registration below
// is irrelevant.
$autoloader->addPsr4('OCA\\OpenRegister\\', __DIR__ . '/Stubs/');
$autoloader->addPsr4('OCA\\Talk\\', __DIR__ . '/Stubs/Talk/');

// The nextcloud/ocp package ships the OCP\* interface definitions under
// vendor/nextcloud/ocp/OCP/ but declares an empty Composer autoload block
// (the real Nextcloud server injects these classes at runtime). Register a
// PSR-4 autoloader for the OCP\ namespace so the unit suite can mock OCP
// interfaces (IRequest, IGroupManager, IAppConfig, …) in a standalone
// container without a running Nextcloud server.
$ocpRoot = __DIR__ . '/../vendor/nextcloud/ocp/OCP';
if (is_dir($ocpRoot)) {
	spl_autoload_register(static function (string $class) use ($ocpRoot): void {
		if (strncmp($class, 'OCP\\', 4) !== 0) {
			return;
		}

		$relative = str_replace('\\', '/', substr($class, 4));
		$file = $ocpRoot . '/' . $relative . '.php';
		if (is_file($file)) {
			require_once $file;
		}
	});
}

// Test-support helpers. Deliberately required rather than registered in
// composer `autoload-dev`: a dev-built vendor/ bakes autoload-dev into the
// runtime classmap and can shadow real app classes instance-wide
// (openregister#2036) — the same hazard the stub registration above avoids.
require_once __DIR__ . '/Support/OrEntityFactory.php';

// Shared guard: base.php exits() rather than throwing on a bad NC instance, so
// loading it unconditionally silently truncates the suite to zero tests while
// still exiting 0. See tests/bootstrap-nc-guard.php for the full rationale.
require_once __DIR__ . '/bootstrap-nc-guard.php';

// Bootstrap Nextcloud if not already done.
if (!defined('OC_CONSOLE')) {
	$scholiqNcRoot = __DIR__ . '/../../..';
	if (scholiq_nc_base_is_safe_to_load($scholiqNcRoot) === true) {
		require_once $scholiqNcRoot . '/lib/base.php';
	} elseif (is_file($scholiqNcRoot . '/lib/base.php') === true) {
		fwrite(
			STDERR,
			'[scholiq/tests/bootstrap] Nextcloud root found at ' . $scholiqNcRoot
			. " but it is not usable (not installed, or config/ not writable).\n"
			. "  Skipping the NC bootstrap and running on Composer autoload + tests/Stubs/ only.\n"
			. "  Loading base.php anyway would exit() and silently truncate this suite to zero tests.\n"
		);
	}

	// NC's own tests/autoload.php starts with `require_once ../lib/base.php`,
	// so it inherits the exit()-on-failure hazard verbatim. Load it only once
	// base.php has actually succeeded — \OC_App exists exactly then.
	if (class_exists('\OC_App') === true
		&& file_exists(__DIR__ . '/../../../tests/autoload.php') === true
	) {
		require_once __DIR__ . '/../../../tests/autoload.php';
	}

	// Only invoke the Nextcloud app loader when the NC server runtime is present
	// (base.php loaded \OC_App). In a standalone container (docker run php:8.3-cli
	// vendor/bin/phpunit) the unit suite runs against Composer autoloading + the
	// tests/Stubs/ shims only, so guard these NC-only calls.
	if (class_exists('\OC_App')) {
		\OC_App::loadApps();
		\OC_App::loadApp('learniq');
		\OC_Hook::clear();
	}
}

// IMcpToolProvider stub — loaded when the openregister runtime (PR #1466) is absent.
// This lets LearniqToolProvider unit tests run in standalone CI environments.
if (interface_exists(\OCA\OpenRegister\Mcp\IMcpToolProvider::class) === false) {
	require_once __DIR__ . '/Stubs/Mcp/IMcpToolProvider.php';
}

// OC\Hooks\Emitter stub — loaded when the live Nextcloud server runtime (which
// ships lib/private/Hooks/Emitter.php) is absent.
//
// `OCP\Files\IRootFolder extends Folder, Emitter`, and `Emitter` is an
// OC-internal (non-OCP) interface that the nextcloud/ocp Composer package does
// not ship. The PSR-4 rules registered above cover `OCP\` and
// `OCA\OpenRegister\` but nothing covers the `OC\` namespace, so IRootFolder
// could not resolve its full interface hierarchy and every test that mocks it
// errored with `Class or interface "OCP\Files\IRootFolder" does not exist` —
// 33 of the 41 errors this suite reported.
//
// tests/bootstrap-unit.php has always loaded this stub; tests/bootstrap.php
// (used by the default phpunit.xml, and therefore by `composer test:unit`)
// never did. The two bootstraps had silently diverged.
if (interface_exists(\OC\Hooks\Emitter::class) === false) {
	require_once __DIR__ . '/Stubs/Hooks/Emitter.php';
}
