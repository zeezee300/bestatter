<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCP\IDBConnection;
use OCP\IUserSession;

/**
 * 0.75.3 – Logo je Niederlassung. Dokumentvorlagen enthalten an der gewünschten Stelle das Platzhalterbild
 * resources/logo-placeholder.png; beim Erzeugen ersetzt DocumentService es durch das Logo der Niederlassung des
 * Falls (Seitenverhältnis bleibt erhalten, das Logo füllt den Rahmen des Platzhalters höchstens aus).
 * Ohne Logo wird das Platzhalterbild durch ein transparentes Bild ersetzt.
 */
class BranchLogoService {
	public const PLACEHOLDER_FILE = __DIR__ . '/../../resources/logo-placeholder.png';
	public const EMPTY_FILE = __DIR__ . '/../../resources/payment-qr-empty.png';
	private const MAX_UPLOAD_BYTES = 2 * 1024 * 1024;
	private const MAX_EDGE = 1600;

	public function __construct(private IDBConnection $db, private IUserSession $userSession, private AuditService $audit) {}

	/** @return array<string,array{width:int,height:int,updatedAt:string}> je Niederlassungsschlüssel */
	public function index(): array {
		$q = $this->db->getQueryBuilder();
		$result = [];
		foreach ($q->select('branch_key', 'width', 'height', 'updated_at')->from('bestatter_branch_logos')->executeQuery()->fetchAllAssociative() as $row) {
			$result[(string)$row['branch_key']] = ['width' => (int)$row['width'], 'height' => (int)$row['height'], 'updatedAt' => (string)$row['updated_at']];
		}
		return $result;
	}

	/** PNG-Bytes des Logos oder null. */
	public function logo(string $branchKey): ?string {
		$branchKey = strtoupper(trim($branchKey));
		if ($branchKey === '') return null;
		$q = $this->db->getQueryBuilder();
		$data = $q->select('data')->from('bestatter_branch_logos')->where($q->expr()->eq('branch_key', $q->createNamedParameter($branchKey)))->executeQuery()->fetchOne();
		if ($data === false) return null;
		$binary = base64_decode((string)$data, true);
		return $binary === false || $binary === '' ? null : $binary;
	}

	/** Prüft (PNG/JPEG/GIF/WebP per Inhalt), verkleinert auf höchstens 1600 px Kantenlänge und speichert als PNG. */
	public function save(string $branchKey, string $binary): array {
		$branchKey = strtoupper(trim($branchKey));
		if (!preg_match('/^[A-Z0-9_-]+$/', $branchKey)) throw new \InvalidArgumentException('Ungültiger Niederlassungsschlüssel.');
		$png = self::normalize($binary);
		[$width, $height] = (array)getimagesizefromstring($png) + [0, 0];
		$now = date('c');
		$uid = $this->userSession->getUser()?->getUID();
		$encoded = base64_encode($png);
		$hash = hash('sha256', $png);
		$q = $this->db->getQueryBuilder();
		$exists = $q->select('id')->from('bestatter_branch_logos')->where($q->expr()->eq('branch_key', $q->createNamedParameter($branchKey)))->executeQuery()->fetchOne();
		$w = $this->db->getQueryBuilder();
		if ($exists === false) {
			$w->insert('bestatter_branch_logos')->values([
				'branch_key' => $w->createNamedParameter($branchKey), 'mime' => $w->createNamedParameter('image/png'), 'width' => $w->createNamedParameter((int)$width), 'height' => $w->createNamedParameter((int)$height),
				'data' => $w->createNamedParameter($encoded), 'sha256' => $w->createNamedParameter($hash), 'updated_at' => $w->createNamedParameter($now), 'updated_by' => $w->createNamedParameter($uid),
			])->executeStatement();
		} else {
			$w->update('bestatter_branch_logos')->set('width', $w->createNamedParameter((int)$width))->set('height', $w->createNamedParameter((int)$height))
				->set('data', $w->createNamedParameter($encoded))->set('sha256', $w->createNamedParameter($hash))->set('updated_at', $w->createNamedParameter($now))->set('updated_by', $w->createNamedParameter($uid))
				->where($w->expr()->eq('branch_key', $w->createNamedParameter($branchKey)))->executeStatement();
		}
		$this->audit->logSystem('BRANCH_LOGO', 'UPDATED', ['branch' => $branchKey, 'width' => (int)$width, 'height' => (int)$height, 'sha256' => $hash]);
		return ['branchKey' => $branchKey, 'width' => (int)$width, 'height' => (int)$height, 'updatedAt' => $now];
	}

	public function delete(string $branchKey): void {
		$branchKey = strtoupper(trim($branchKey));
		$d = $this->db->getQueryBuilder();
		$d->delete('bestatter_branch_logos')->where($d->expr()->eq('branch_key', $d->createNamedParameter($branchKey)))->executeStatement();
		$this->audit->logSystem('BRANCH_LOGO', 'DELETED', ['branch' => $branchKey]);
	}

	public static function normalize(string $binary): string {
		if ($binary === '' || strlen($binary) > self::MAX_UPLOAD_BYTES) throw new \InvalidArgumentException('Das Logo ist leer oder größer als 2 MB.');
		$info = @getimagesizefromstring($binary);
		if (!is_array($info) || !in_array($info[2] ?? 0, [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) throw new \InvalidArgumentException('Das Logo muss eine PNG-, JPEG-, GIF- oder WebP-Datei sein. Eine SVG-Datei bitte vorher als PNG speichern.');
		[$width, $height] = [(int)$info[0], (int)$info[1]];
		if ($width < 16 || $height < 16 || $width > 10000 || $height > 10000) throw new \InvalidArgumentException('Das Logo muss zwischen 16 und 10.000 Pixel breit und hoch sein.');
		$source = @imagecreatefromstring($binary);
		if ($source === false) throw new \InvalidArgumentException('Das Logo konnte nicht gelesen werden.');
		$scale = min(1.0, self::MAX_EDGE / max($width, $height));
		$targetWidth = max(1, (int)round($width * $scale)); $targetHeight = max(1, (int)round($height * $scale));
		$target = imagecreatetruecolor($targetWidth, $targetHeight);
		imagealphablending($target, false); imagesavealpha($target, true);
		imagefill($target, 0, 0, (int)imagecolorallocatealpha($target, 255, 255, 255, 127));
		imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
		ob_start(); imagepng($target, null, 9); $png = (string)ob_get_clean();
		imagedestroy($source); imagedestroy($target);
		return $png;
	}

	/** Ist das Bild das Logo-Platzhalterbild (byte-gleich)? */
	public static function isPlaceholder(string $media): bool {
		static $hash = null;
		$hash ??= is_file(self::PLACEHOLDER_FILE) ? hash('sha256', (string)file_get_contents(self::PLACEHOLDER_FILE)) : '';
		return $hash !== '' && hash_equals($hash, hash('sha256', $media));
	}
}
