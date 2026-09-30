<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Standalone bootstrap: no Nextcloud server needed. Unit tests use nextcloud/ocp
 * (interfaces and base classes) together with mocks.
 */

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
	fwrite(STDERR, "Run `composer install` first.\n");
	exit(1);
}
require_once $autoload;

// Make sure nextcloud/ocp (OCP\ and NCU\) is autoloadable even if composer did not register it.
$ocp = __DIR__ . '/../vendor/nextcloud/ocp';
if (is_dir($ocp)) {
	spl_autoload_register(static function (string $class) use ($ocp): void {
		foreach (['OCP\\' => '/OCP/', 'NCU\\' => '/NCU/'] as $prefix => $dir) {
			if (str_starts_with($class, $prefix)) {
				$file = $ocp . $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
				if (is_file($file)) {
					require_once $file;
				}
			}
		}
	});
}
