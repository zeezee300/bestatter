<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCP\Files\Node;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * 0.75.1: Löscht Dateien und Ordner endgültig, also am Nextcloud-Papierkorb vorbei. Ohne Papierkorb entfernt
 * Nextcloud beim Löschen auch die älteren Dateiversionen. Ist die Papierkorb-App nicht aktiv, wird normal gelöscht.
 */
class PermanentDeletion {
	private const TRASH_MANAGER = 'OCA\\Files_Trashbin\\Trash\\ITrashManager';

	public function __construct(private LoggerInterface $logger) {}

	public function delete(Node $node): void {
		$this->withoutTrash(static fn() => $node->delete());
	}

	/**
	 * @template T
	 * @param callable():T $callback
	 * @return T
	 */
	public function withoutTrash(callable $callback): mixed {
		$manager = $this->trashManager();
		if ($manager !== null) $manager->pauseTrash();
		try {
			return $callback();
		} finally {
			if ($manager !== null) $manager->resumeTrash();
		}
	}

	private function trashManager(): ?object {
		if (!interface_exists(self::TRASH_MANAGER)) return null;
		try {
			$manager = Server::get(self::TRASH_MANAGER);
			return is_object($manager) && method_exists($manager, 'pauseTrash') && method_exists($manager, 'resumeTrash') ? $manager : null;
		} catch (\Throwable $error) {
			$this->logger->info('Papierkorb nicht verfügbar – es wird ohne Umgehung gelöscht.', ['reason' => $error->getMessage()]);
			return null;
		}
	}
}
