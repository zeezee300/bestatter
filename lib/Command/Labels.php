<?php

declare(strict_types=1);

namespace OCA\Bestatter\Command;

use OCA\Bestatter\Service\LabelService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 0.76.0: Marker anzeigen und aktivieren (z. B. für Test- und Vorführinstallationen:
 * occ bestatter:labels --activate=all). Deaktivieren und Bearbeiten erfolgen im Customizing.
 */
class Labels extends Command {
	public function __construct(private LabelService $labels) { parent::__construct(); }

	protected function configure(): void {
		$this->setName('bestatter:labels')
			->setDescription('Listet die Marker und aktiviert mitgelieferte bzw. genannte Marker.')
			->addOption('activate', null, InputOption::VALUE_REQUIRED, '„all“ = alle mitgelieferten Marker, sonst Schlüssel kommagetrennt (z. B. HYGIENE,KOSTENTRAEGER)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$activate = trim((string)($input->getOption('activate') ?? ''));
		if ($activate !== '') {
			$keys = strtolower($activate) === 'all' ? ['*'] : array_values(array_filter(array_map('trim', explode(',', $activate))));
			$done = $this->labels->activate($keys, false);
			$output->writeln($done === [] ? 'Keine Änderung – die Marker waren bereits aktiv.' : 'Aktiviert: ' . implode(', ', $done));
		}
		foreach ($this->labels->definitions() as $label) {
			$output->writeln(implode(' ', [$label['active'] ? '[x]' : '[ ]', $this->pad($label['key'], 20), $this->pad($label['name'], 30), $this->pad($label['kind'], 5), $this->pad($label['color'], 7), $this->pad($label['icon'], 20), $label['origin'] === 'BUNDLED' ? 'mitgeliefert' : 'eigenes']));
		}
		return self::SUCCESS;
	}

	/** Spaltenbreite nach Zeichen (nicht Bytes), damit Umlaute die Ausrichtung nicht verschieben. */
	private function pad(string $text, int $width): string {
		return $text . str_repeat(' ', max(0, $width - mb_strlen($text)));
	}
}
