<?php

declare(strict_types=1);

namespace OCA\AppsAgent\Command;

use OCA\AppsAgent\Db\InstructionMapper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Submit extends Command {
	public function __construct(
		private InstructionMapper $mapper,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('appsagent:submit');
		$this->setDescription('Submete uma instrucao a fila do agente, processada pelo cron do Nextcloud');
		$this->addArgument('text', InputArgument::REQUIRED, 'Instrucao em linguagem natural');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$instruction = $this->mapper->insertPending((string)$input->getArgument('text'));
		$output->writeln("Instrucao #{$instruction->getId()} submetida.");
		return 0;
	}
}
