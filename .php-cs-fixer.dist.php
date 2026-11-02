<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Nextcloud\CodingStandard\Config;

// Geprüft wird der PHP-Code der App, der im Repository liegt. Die frühere Auswahl über
// ignoreVCSIgnored() fand wegen der Positivliste in .gitignore keine einzige Datei.
$config = new Config();
$config->getFinder()
	->in([
		__DIR__ . '/appinfo',
		__DIR__ . '/lib',
		__DIR__ . '/templates',
		__DIR__ . '/tools/ci',
	])
	->append([__FILE__]);

return $config;
