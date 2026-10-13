<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

/**
 * 0.87.0 – PHP-Abhängigkeiten der App (Ordner vendor/ des Installationspakets). Wird die App aus dem Quellcode statt aus
 * dem gebauten Paket installiert, fehlt vendor/: Die App startet trotzdem, QR-Codes (EPC-Zahlcode auf Rechnungen) scheitern
 * aber erst beim Benutzen. Diese Prüfung meldet das vorher – in der Systemprüfung und in occ bestatter:update-check.
 */
final class DependencyCheck {
	/** Klassen, die zur Laufzeit gebraucht werden (Paket => Klasse). */
	public const CLASSES = [
		'endroid/qr-code' => 'Endroid\QrCode\QrCode',
		'endroid/qr-code (PNG)' => 'Endroid\QrCode\Writer\PngWriter',
		'bacon/bacon-qr-code' => 'BaconQrCode\Encoder\Encoder',
	];
	public const EXTENSIONS = ['gd'];

	/** @return array{status: string, message: string, missing: list<string>, hint: string} */
	public static function report(?string $appRoot = null): array {
		$appRoot ??= dirname(__DIR__, 2);
		$missing = [];
		if (!is_file($appRoot . '/vendor/autoload.php')) $missing[] = 'vendor/autoload.php';
		foreach (self::CLASSES as $package => $class) if (!class_exists($class)) $missing[] = $package;
		foreach (self::EXTENSIONS as $extension) if (!extension_loaded($extension)) $missing[] = 'PHP-Erweiterung ' . $extension;
		$missing = array_values(array_unique($missing));
		return [
			'status' => $missing === [] ? 'OK' : 'ERROR',
			'message' => $missing === [] ? 'Alle PHP-Abhängigkeiten vorhanden (vendor/, QR-Code-Bibliotheken, GD).' : 'Es fehlt: ' . implode(', ', $missing) . '.',
			'missing' => $missing,
			'hint' => $missing === [] ? '' : 'Die App aus dem gebauten Installationspaket (bestatter-x.y.z.tar.gz) einspielen, nicht aus dem Quellcode-Ordner bzw. der Git-Arbeitskopie; das Paket enthält vendor/. Eine fehlende PHP-Erweiterung installiert der Server-Betreuer.',
		];
	}
}
