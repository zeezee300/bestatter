<?php

declare(strict_types=1);

// CI: Nextcloud-Klassen (OCP) und Drittbibliotheken aus einem Checkout von nextcloud/server laden,
// damit PHPStan dieselben Typen sieht wie in der Test-Nextcloud. Pfad über NEXTCLOUD_SERVER.
$server = rtrim((string)(getenv('NEXTCLOUD_SERVER') ?: dirname(__DIR__, 3) . '/server'), '/');
require $server . '/3rdparty/autoload.php';
spl_autoload_register(static function (string $class) use ($server): void {
	foreach (['OCP\\' => '/lib/public/', 'NCU\\' => '/lib/unstable/', 'OC\\' => '/lib/private/'] as $prefix => $dir) {
		if (str_starts_with($class, $prefix)) {
			$file = $server . $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
			if (is_file($file)) require $file;
			return;
		}
	}
});
require dirname(__DIR__, 2) . '/vendor/autoload.php';
