<?php
declare(strict_types=1);
namespace OCA\Bestatter\Command;
use OCA\Bestatter\Service\BackupService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
class Backup extends Command {
	public function __construct(private BackupService $backup){parent::__construct();}
	protected function configure():void{$this->setName('bestatter:backup')->setDescription('Erstellt einen prüfbaren App-Datensnapshot oder ein vollständiges Fachpaket.')->addOption('output',null,InputOption::VALUE_REQUIRED,'Absolute Zieldatei für einen reinen JSON-Snapshot')->addOption('include-files',null,InputOption::VALUE_NONE,'Fallakten, Vorlagen und Artikelbilder in ein ZIP-Fachpaket aufnehmen');}
	protected function execute(InputInterface $input,OutputInterface $output):int{$result=$input->getOption('include-files')?$this->backup->createPackage():$this->backup->create($input->getOption('output')?:null);$output->writeln(json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));return self::SUCCESS;}
}
