<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Nextcloud\CodingStandard\Config;

$config = new Config();
$config->getFinder()
	->ignoreVCSIgnored(true)
	->notPath('js')
	->notPath('node_modules')
	->notPath('resources')
	->notPath('tests/rendered')
	->notPath('vendor')
	->in(__DIR__);

return $config;
