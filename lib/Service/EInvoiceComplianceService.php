<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCP\IConfig;

/**
 * Optional adapter for the Mustang CLI as normative validator. Internal XML checks
 * are never reported as normative validation; only a successful external validator
 * run may do so. Since 0.73.0 the app embeds the XML itself (FacturXPdfService).
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

	/** 0.73.0: remembers the last ZUGFeRD output for the system check (PDF/A delivered by the converter?). */
	public function recordOutput(array $output): void {
		$this->config->setAppValue('bestatter', 'einvoice_last_output', json_encode([
			'at' => date('c'), 'embedded' => (bool)($output['embedded'] ?? false), 'pdfa' => $output['pdfa'] ?? null,
			'normativeValidation' => (string)($output['normativeValidation'] ?? ''), 'embedError' => $output['embedError'] ?? null,
		], JSON_THROW_ON_ERROR));
	}

	/** @return ?array{at:string,embedded:bool,pdfa:?string,normativeValidation:string,embedError:?string} */
	public function lastOutput(): ?array {
		$value = json_decode($this->config->getAppValue('bestatter', 'einvoice_last_output', ''), true);
		return is_array($value) ? $value : null;
	}

	/**
	 * 0.73.0: Normative check of the finished ZUGFeRD PDF (PDF/A-3 and embedded EN 16931 data).
	 * The app embeds the XML itself; Mustang is only used as validator.
	 *
	 * @return array{valid:bool,report:string}
	 */
	public function validate(string $pdf): array {
		$status = $this->status();
		if (!$status['available']) throw new \RuntimeException('Der normative E-Rechnungsvalidator ist nicht eingerichtet oder nicht ausführbar.');
		$directory = sys_get_temp_dir() . '/bestatter-einvoice-' . bin2hex(random_bytes(10));
		if (!mkdir($directory, 0700) && !is_dir($directory)) throw new \RuntimeException('Temporäres Verzeichnis für die E-Rechnung konnte nicht erstellt werden.');
		$source = $directory . '/invoice-zugferd.pdf';
		try {
			file_put_contents($source, $pdf);
			$jar = $this->config->getSystemValueString('bestatter_einvoice_mustang_jar', '');
			$java = $this->config->getSystemValueString('bestatter_einvoice_java', 'java') ?: 'java';
			[$exitCode, $stdout, $stderr] = $this->run([$java, '-jar', $jar, '--action', 'validate', '--source', $source]);
			$report = trim($stdout);
			if (!str_contains($report, '<summary')) throw new \RuntimeException('Der E-Rechnungsvalidator hat keinen Prüfbericht geliefert: ' . mb_substr(trim($stderr ?: $stdout), 0, 1200));
			$valid = $exitCode === 0 && preg_match('/<summary[^>]*status\s*=\s*"valid"/i', $report) === 1;
			return ['valid' => $valid, 'report' => $report];
		} finally {
			if (is_file($source)) @unlink($source);
			@rmdir($directory);
		}
	}

	/** @return array{0:int,1:string,2:string} exit code, stdout, stderr */
	private function run(array $command): array {
		$descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
		$process = proc_open($command, $descriptor, $pipes);
		if (!is_resource($process)) throw new \RuntimeException('Der E-Rechnungsvalidator konnte nicht gestartet werden.');
		$stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
		fclose($pipes[1]); fclose($pipes[2]);
		return [proc_close($process), (string)$stdout, (string)$stderr];
	}
}
