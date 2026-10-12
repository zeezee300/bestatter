<?php

declare(strict_types=1);

namespace OCA\Bestatter\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\EmptyContentSecurityPolicy;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

/**
 * 0.75.0 – Anwenderhandbuch: liefert das aus docs/handbuch erzeugte, skriptfreie HTML-Handbuch, seine Bilder und
 * das PDF aus resources/handbuch aus. Zugriff wie die übrige App nur für Mitglieder (zentrale Middleware).
 */
class HandbuchController extends Controller {
	private const DIRECTORY = __DIR__ . '/../../resources/handbuch';

	public function __construct(string $appName, IRequest $request) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): Response {
		return $this->file('index.html', 'text/html; charset=utf-8');
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function image(string $file): Response {
		// Nur Dateinamen aus dem Bildordner, keine Pfadbestandteile.
		if (preg_match('/^[a-z0-9][a-z0-9-]{0,80}\.png$/', $file) !== 1) return new DataDisplayResponse('', Http::STATUS_NOT_FOUND);
		return $this->file('bilder/' . $file, 'image/png');
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function pdf(): Response {
		$response = $this->file('Bestatter-Handbuch.pdf', 'application/pdf');
		if ($response->getStatus() === Http::STATUS_OK) $response->addHeader('Content-Disposition', 'inline; filename="Bestatter-Handbuch.pdf"');
		return $response;
	}

	private function file(string $relative, string $type): DataDisplayResponse {
		$path = self::DIRECTORY . '/' . $relative;
		if (!is_file($path)) return new DataDisplayResponse('Das Handbuch ist in dieser Installation nicht vorhanden.', Http::STATUS_NOT_FOUND, ['Content-Type' => 'text/plain; charset=utf-8']);
		$response = new DataDisplayResponse((string)file_get_contents($path), Http::STATUS_OK, ['Content-Type' => $type]);
		// Das Handbuch enthält keine Skripte: nur eigene Bilder und eingebettete Stile zulassen.
		$policy = new EmptyContentSecurityPolicy();
		$policy->allowInlineStyle(true);
		$policy->addAllowedImageDomain("'self'");
		$response->setContentSecurityPolicy($policy);
		$response->addHeader('Cache-Control', 'private, max-age=3600');
		$response->addHeader('X-Content-Type-Options', 'nosniff');
		return $response;
	}
}
