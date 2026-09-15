<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCP\Files\File;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * Uses the enabled EuroOffice Nextcloud connector for server-side conversion.
 *
 * The EuroOffice classes are resolved dynamically so the Bestatter app remains
 * usable when EuroOffice is not installed or temporarily disabled.
 */
class EuroOfficeConversionService {
	private const APP_CONFIG = 'OCA\\Eurooffice\\AppConfig';
	private const CRYPT = 'OCA\\Eurooffice\\Crypt';
	private const DOCUMENT_SERVICE = 'OCA\\Eurooffice\\DocumentService';

	public function __construct(
		private IURLGenerator $urlGenerator,
		private IUserSession $userSession,
	) {}

	public function isAvailable(): bool {
		if (!class_exists(self::APP_CONFIG) || !class_exists(self::CRYPT) || !class_exists(self::DOCUMENT_SERVICE)) {
			return false;
		}

		try {
			$config = \OCP\Server::get(self::APP_CONFIG);
			return trim((string)$config->getDocumentServerInternalUrl()) !== '';
		} catch (\Throwable) {
			return false;
		}
	}

	public function convert(File $docx): string {
		if (!$this->isAvailable()) {
			throw new \RuntimeException('EuroOffice ist nicht für die automatische PDF-Konvertierung verfügbar.');
		}

		$sessionUser = $this->userSession->getUser();
		if ($sessionUser === null) {
			throw new \RuntimeException('Für die EuroOffice-Konvertierung ist ein angemeldeter Benutzer erforderlich.');
		}

		// The callback resolves the file below the user folder encoded in the
		// token. A privileged user can generate a document in a shared folder
		// owned by somebody else, so the active session is not necessarily the
		// file owner that EuroOffice must use for the server-side download.
		$fileOwner = $docx->getOwner();
		$downloadUserId = $fileOwner?->getUID() ?? $sessionUser->getUID();

		try {
			$config = \OCP\Server::get(self::APP_CONFIG);
			$crypt = \OCP\Server::get(self::CRYPT);
			$documentService = \OCP\Server::get(self::DOCUMENT_SERVICE);

			$downloadToken = $crypt->getHash([
				'action' => 'download',
				'fileId' => $docx->getId(),
				'userId' => $downloadUserId,
				'version' => 0,
				'filePath' => '',
			]);
			$downloadUrl = $this->urlGenerator->linkToRouteAbsolute('eurooffice.callback.download', ['doc' => $downloadToken]);
			$storageUrl = trim((string)$config->getStorageUrl());
			if ($storageUrl !== '') {
				$downloadUrl = str_replace($this->urlGenerator->getAbsoluteURL('/'), rtrim($storageUrl, '/') . '/', $downloadUrl);
			}

			// Conversion servers cache by revision. File IDs and ETags can be reused
			// when a replaceable invoice draft is deleted and recreated quickly.
			// Hashing the actual DOCX guarantees that a newly embedded payment QR
			// never receives an older cached PDF without the image.
			$revision = substr(hash('sha256', (string)$docx->getContent()), 0, 32);
			$convertedUrl = (string)$documentService->getConvertedUri($downloadUrl, 'docx', 'pdf', $revision);
			if ($convertedUrl === '') {
				throw new \RuntimeException('EuroOffice hat keine URL für das konvertierte PDF zurückgegeben.');
			}
			$content = (string)$documentService->request($convertedUrl);
		} catch (\Throwable $error) {
			throw new \RuntimeException('EuroOffice-PDF-Konvertierung fehlgeschlagen: ' . $error->getMessage(), 0, $error);
		}

		if (!str_starts_with($content, '%PDF-')) {
			throw new \RuntimeException('EuroOffice hat kein gültiges PDF erzeugt.');
		}
		return $content;
	}
}
