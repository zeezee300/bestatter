<?php

declare(strict_types=1);

$root = getenv('BESTATTER_NEXTCLOUD_ROOT') ?: '/var/www/html';
if (!is_file($root . '/lib/base.php')) {
	fwrite(STDERR, "Nextcloud-Bootstrap nicht gefunden; Integrationstests werden übersprungen.\n");
	return;
}
require_once $root . '/lib/base.php';
