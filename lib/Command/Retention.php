<?php
declare(strict_types=1);
namespace OCA\Bestatter\Command;
use OCA\Bestatter\Service\RetentionPolicyService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
class Retention extends Command {
	private const CONFIRMATION='BESTATTER-AUFBEWAHRUNG-ANWENDEN';
	public function __construct(private RetentionPolicyService $retention){parent::__construct();}
	protected function configure():void{$this->setName('bestatter:retention')->setDescription('Zeigt abgelaufene Fälle oder wendet die konfigurierte Lösch-/Anonymisierungsregel bestätigt an.')->addOption('execute',null,InputOption::VALUE_NONE)->addOption('confirmation',null,InputOption::VALUE_REQUIRED);}
	protected function execute(InputInterface $input,OutputInterface $output):int{$preview=$this->retention->preview(500);$output->writeln(json_encode($preview,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));if(!$input->getOption('execute')){$output->writeln('<comment>Vorschau: keine Daten verändert.</comment>');return self::SUCCESS;}if((string)$input->getOption('confirmation')!==self::CONFIRMATION){$output->writeln('<error>Bestätigungscode fehlt oder ist falsch.</error>');return self::FAILURE;}$output->writeln(json_encode($this->retention->execute(array_column($preview['cases'],'id')),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));return self::SUCCESS;}
}
