<?php

declare(strict_types=1);

namespace OCA\Bestatter\Command;

use OCA\Bestatter\AppInfo\Application;
use OCA\Bestatter\Service\PaperlessService;
use OCP\IDBConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Read-only acceptance check for the optional Paperless integration. */
class PaperlessAcceptanceCheck extends Command {
	public function __construct(
		private PaperlessService $paperless,
		private IDBConnection $db,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('bestatter:paperless-acceptance-check')
			->setDescription('Prüft die Paperless-Anbindung ohne Daten zu verändern.')
			->addOption('document-id', null, InputOption::VALUE_REQUIRED, 'Vorhandene Paperless-Dokument-ID für einen lesenden Downloadtest')
			->addOption('probe-first-inbox', null, InputOption::VALUE_NONE, 'Ersten unzugeordneten Beleg lesend herunterladen, falls vorhanden');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$settings = $this->paperless->settings();
		$connection = $this->paperless->connectionTest();
		$inbox = $this->paperless->inbox();
		$externalRows = $this->externalRows();
		$jobRows = $this->jobRows();

		$documentId = trim((string)$input->getOption('document-id'));
		if ($documentId === '' && (bool)$input->getOption('probe-first-inbox')) {
			foreach ($inbox['items'] as $item) {
				if (!empty($item['externalDocumentId'])) {
					$documentId = (string)$item['externalDocumentId'];
					break;
				}
			}
		}

		$download = ['status'=>'NOT_RUN', 'reason'=>$documentId === ''
			? 'Keine Dokument-ID angegeben und kein abrufbarer Beleg ausgewählt.'
			: null];
		if ($documentId !== '') {
			try {
				$file = $this->paperless->downloadDocument($documentId);
				$download = [
					'status'=>'OK',
					'documentId'=>$documentId,
					'mimeType'=>$file['mimeType'],
					'extension'=>$file['extension'],
					'bytes'=>strlen($file['content']),
					'sha256'=>hash('sha256', $file['content']),
				];
			} catch (\Throwable $error) {
				$download = ['status'=>'ERROR', 'documentId'=>$documentId, 'message'=>$this->safeMessage($error)];
			}
		}

		$duplicates = $this->duplicates($externalRows);
		$externalStatus = $this->statusCounts($externalRows, 'sync_status');
		$jobStatus = $this->statusCounts($jobRows, 'job_status');
		$errors = [];
		if (($connection['status'] ?? 'ERROR') !== 'OK') $errors[] = 'CONNECTION';
		if ($duplicates !== []) $errors[] = 'DUPLICATE_EXTERNAL_DOCUMENT_IDS';
		if (($download['status'] ?? '') === 'ERROR') $errors[] = 'DOCUMENT_DOWNLOAD';

		$result = [
			'version'=>Application::VERSION,
			'readOnly'=>true,
			'configuration'=>[
				'mode'=>$settings['mode'],
				'enabled'=>$settings['enabled'],
				'baseUrl'=>$settings['baseUrl'],
				'apiTokenConfigured'=>$settings['tokenConfigured'],
				'webhookSecretConfigured'=>$settings['webhookSecretConfigured'],
				'documentTypeId'=>$settings['documentTypeId'],
				'tagIds'=>$settings['tagIds'],
			],
			'connection'=>$connection,
			'inbox'=>['count'=>count($inbox['items']), 'mode'=>$inbox['mode'], 'manualFallback'=>$inbox['manualFallback']],
			'downloadProbe'=>$download,
			'integrity'=>[
				'externalDocuments'=>count($externalRows),
				'externalStatus'=>$externalStatus,
				'integrationJobs'=>count($jobRows),
				'jobStatus'=>$jobStatus,
				'duplicateDocumentIds'=>$duplicates,
			],
			'assignment'=>[
				'status'=>'NOT_RUN',
				'reason'=>'Die Fallzuordnung erzeugt eine Eingangsrechnung und eine Nextcloud-Datei und ist daher nicht Teil der lesenden Prüfung.',
			],
			'overall'=>$errors === [] ? 'OK' : 'ERROR',
			'errors'=>$errors,
		];

		$output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
		return $errors === [] ? self::SUCCESS : self::FAILURE;
	}

	private function externalRows(): array {
		$query = $this->db->getQueryBuilder();
		return $query->select('id', 'external_document_id', 'sync_status')
			->from('bestatter_external_documents')
			->where($query->expr()->eq('provider', $query->createNamedParameter('PAPERLESS')))
			->executeQuery()->fetchAllAssociative();
	}

	private function jobRows(): array {
		$query = $this->db->getQueryBuilder();
		return $query->select('id', 'job_status')
			->from('bestatter_integration_jobs')
			->where($query->expr()->eq('provider', $query->createNamedParameter('PAPERLESS')))
			->executeQuery()->fetchAllAssociative();
	}

	private function statusCounts(array $rows, string $column): array {
		$result = [];
		foreach ($rows as $row) {
			$status = (string)($row[$column] ?? 'UNKNOWN');
			$result[$status] = ($result[$status] ?? 0) + 1;
		}
		ksort($result);
		return $result;
	}

	private function duplicates(array $rows): array {
		$ids = [];
		foreach ($rows as $row) {
			$id = trim((string)($row['external_document_id'] ?? ''));
			if ($id !== '') $ids[$id][] = (int)$row['id'];
		}
		return array_filter($ids, static fn(array $recordIds): bool => count($recordIds) > 1);
	}

	private function safeMessage(\Throwable $error): string {
		$message = $error->getMessage();
		$message = preg_replace('/Token\s+\S+/i', 'Token [geschützt]', $message) ?? 'Paperless-Prüfung fehlgeschlagen.';
		return mb_substr($message, 0, 500);
	}
}
