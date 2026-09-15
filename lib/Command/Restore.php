<?php
declare(strict_types=1);
namespace OCA\Bestatter\Command;
use OCA\Bestatter\Service\BackupService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
class Restore extends Command {
	private const CONFIRMATION='BESTATTER-RESTORE-LEERES-ZIEL';
	public function __construct(private BackupService $backup){parent::__construct();}
	protected function configure():void{$this->setName('bestatter:restore')->setDescription('Prüft oder restauriert einen App-Datensnapshot ausschließlich in ein leeres Ziel.')->addArgument('file',InputArgument::REQUIRED,'Absolute Sicherungsdatei')->addOption('execute',null,InputOption::VALUE_NONE,'Restore ausführen')->addOption('confirmation',null,InputOption::VALUE_REQUIRED,'Bestätigungscode');}
	protected function execute(InputInterface $input,OutputInterface $output):int{$path=(string)$input->getArgument('file');$package=str_ends_with(strtolower($path),'.zip');$inspection=$package?$this->backup->inspectPackage($path):$this->backup->inspect($path);unset($inspection['payload'],$inspection['manifest']);$output->writeln(json_encode($inspection,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));if(!$input->getOption('execute')){$output->writeln('<comment>Vorschau: keine Daten verändert. Für das leere, isolierte Ziel --execute --confirmation='.self::CONFIRMATION.' verwenden.</comment>');return self::SUCCESS;}if((string)$input->getOption('confirmation')!==self::CONFIRMATION){$output->writeln('<error>Bestätigungscode fehlt oder ist falsch.</error>');return self::FAILURE;}$output->writeln(json_encode($package?$this->backup->restorePackage($path):$this->backup->restore($path),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));return self::SUCCESS;}
}
