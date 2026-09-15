<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$directories = ['appinfo', 'lib', 'templates', 'tests/php'];
$failed = false;
$checked = 0;

foreach ($directories as $directory) {
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
		$root . DIRECTORY_SEPARATOR . $directory,
		FilesystemIterator::SKIP_DOTS,
	));
	foreach ($iterator as $file) {
		if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
			continue;
		}
		$checked++;
		$command = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname());
		exec($command, $output, $exitCode);
		if ($exitCode !== 0) {
			$failed = true;
			fwrite(STDERR, implode(PHP_EOL, $output) . PHP_EOL);
		}
		$output = [];
	}
}

if ($failed) {
	exit(1);
}

fwrite(STDOUT, sprintf("%d PHP-Dateien ohne Syntaxfehler.\n", $checked));
