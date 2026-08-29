<?php

/**
 * Shared safety predicate for loading Nextcloud's lib/base.php from a test bootstrap.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

if (function_exists('learniq_nc_base_is_safe_to_load') === false) {
	/**
	 * Decide whether it is SAFE to load Nextcloud's lib/base.php.
	 *
	 * base.php does not signal failure by throwing — on any initialisation
	 * problem (unwritable config dir, missing/empty config.php, instance not
	 * installed) it renders an error page and calls `exit()`. `exit()` cannot
	 * be caught, so an unguarded `require_once` of base.php TERMINATES PHPUNIT
	 * DURING BOOTSTRAP, before a single test is collected, and PHPUnit's shell
	 * exit code is 0.
	 *
	 * That is the worst possible failure mode: the suite reports success while
	 * running nothing. It was live in the standard `apps-extra/` checkout
	 * layout, where `../../../lib/base.php` always resolves to the dev server
	 * tree — a bare `vendor/bin/phpunit` there printed 'Cannot write into
	 * "config" directory!', ran 0 tests and exited 0.
	 *
	 * The unit suite does not need the NC runtime at all (it runs fully green
	 * against Composer autoloading plus the tests/Stubs/ shims), so base.php is
	 * a best-effort enhancement only. Load it solely when the instance is
	 * genuinely installed AND its config directory is writable — the two
	 * conditions base.php itself exits on.
	 *
	 * @param string $ncRoot Absolute path to the candidate Nextcloud root.
	 *
	 * @return bool True when base.php can be loaded without risking exit().
	 */
	function learniq_nc_base_is_safe_to_load(string $ncRoot): bool {
		if (is_file($ncRoot . '/lib/base.php') === false) {
			return false;
		}

		$configFile = $ncRoot . '/config/config.php';
		if (is_file($configFile) === false || filesize($configFile) === 0) {
			return false;
		}

		$CONFIG = [];
		require $configFile;

		if (is_array($CONFIG) === false || ($CONFIG['installed'] ?? false) !== true) {
			return false;
		}

		// base.php exits when it cannot write the config dir, unless the
		// instance explicitly opted into a read-only config.
		if (is_writable($ncRoot . '/config') === false
			&& ($CONFIG['config_is_read_only'] ?? false) !== true
		) {
			return false;
		}

		return true;
	}//end learniq_nc_base_is_safe_to_load()
}//end if
