<?php

declare(strict_types=1);

namespace OCA\AppsAgent\Command;

use OCA\AppsAgent\Db\InstructionMapper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ListInstructions extends Command {
	public function __construct(
		private InstructionMapper $mapper,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('appsagent:list');
		$this->setDescription('Lista as instrucoes submetidas ao agente e o respetivo estado/resultado');
		$this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Quantas instrucoes mostrar (mais recentes primeiro)', '10');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$limit = max(1, (int)$input->getOption('limit'));
		$instructions = array_slice(array_reverse($this->mapper->findAllOrdered()), 0, $limit);

		if ($instructions === []) {
			$output->writeln('Sem instrucoes submetidas ainda.');
			return 0;
		}

		foreach ($instructions as $instruction) {
			$output->writeln("#{$instruction->getId()} [{$instruction->getStatus()}] {$instruction->getCreatedAt()}");
			$output->writeln("  Texto: {$instruction->getText()}");
			if ($instruction->getResult() !== null) {
				$output->writeln("  Resultado: {$instruction->getResult()}");
			}
			$output->writeln('');
		}

		return 0;
	}
}
