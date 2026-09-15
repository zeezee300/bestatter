<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCP\IConfig;

/**
 * Optional adapter for the Mustang CLI. Internal XML checks are never reported
 * as normative validation; only a successful external validator run may do so.
 */
class EInvoiceComplianceService {
	public function __construct(private IConfig $config) {}

	public function status(): array {
		$jar = trim($this->config->getSystemValueString('bestatter_einvoice_mustang_jar', ''));
		$java = trim($this->config->getSystemValueString('bestatter_einvoice_java', 'java')) ?: 'java';
		return [
			'available' => $jar !== '' && is_file($jar) && function_exists('proc_open'),
			'engine' => 'Mustang CLI',
			'jarConfigured' => $jar !== '',
			'jarReadable' => $jar !== '' && is_file($jar),
			'javaCommand' => $java,
		];
	}

	/** @return array{pdf:string,report:string,validated:bool} */
	public function combineAndValidate(string $pdf, string $xml): array {
		$status = $this->status();
		if (!$status['available']) throw new \RuntimeException('Der normative E-Rechnungsvalidator ist nicht eingerichtet oder nicht ausführbar.');
		$directory = sys_get_temp_dir() . '/bestatter-einvoice-' . bin2hex(random_bytes(10));
		if (!mkdir($directory, 0700) && !is_dir($directory)) throw new \RuntimeException('Temporäres Verzeichnis für die E-Rechnung konnte nicht erstellt werden.');
		$source = $directory . '/source.pdf';
		$xmlFile = $directory . '/factur-x.xml';
		$output = $directory . '/invoice-zugferd.pdf';
		try {
			file_put_contents($source, $pdf); file_put_contents($xmlFile, $xml);
			$jar = $this->config->getSystemValueString('bestatter_einvoice_mustang_jar', '');
			$java = $this->config->getSystemValueString('bestatter_einvoice_java', 'java') ?: 'java';
			$this->run([$java, '-jar', $jar, '--action', 'combine', '--source', $source, '--source-xml', $xmlFile, '--out', $output, '--format', 'zf', '--version', '2', '--profile', 'E', '--no-additional-attachments']);
			if (!is_file($output) || !str_starts_with((string)file_get_contents($output), '%PDF-')) throw new \RuntimeException('Der E-Rechnungsvalidator hat kein PDF/A-3-Dokument erzeugt.');
			$report = $this->run([$java, '-jar', $jar, '--action', 'validate', '--source', $output]);
			return ['pdf' => (string)file_get_contents($output), 'report' => $report, 'validated' => true];
		} finally {
			foreach ([$source, $xmlFile, $output] as $file) if (is_file($file)) @unlink($file);
			@rmdir($directory);
		}
	}

	private function run(array $command): string {
		$descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
		$process = proc_open($command, $descriptor, $pipes);
		if (!is_resource($process)) throw new \RuntimeException('Der E-Rechnungsvalidator konnte nicht gestartet werden.');
		$stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
		fclose($pipes[1]); fclose($pipes[2]);
		$exitCode = proc_close($process);
		if ($exitCode !== 0) throw new \RuntimeException('Die normative E-Rechnungsprüfung ist fehlgeschlagen: ' . mb_substr(trim((string)$stderr ?: (string)$stdout), 0, 1200));
		return trim((string)$stdout);
	}
}
