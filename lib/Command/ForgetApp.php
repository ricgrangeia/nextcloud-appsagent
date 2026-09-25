<?php

declare(strict_types=1);

namespace OCA\AppsAgent\Command;

use OCA\AppsAgent\Service\MemoryService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ForgetApp extends Command {
	public function __construct(
		private MemoryService $memory,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('appsagent:forget');
		$this->setDescription('Apaga as receitas aprendidas de uma app, para o agente as voltar a explorar do zero');
		$this->addArgument('app_id', InputArgument::REQUIRED, 'Id da app (ex: notes, deck)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$appId = (string)$input->getArgument('app_id');
		$forgotten = $this->memory->forgetApp($appId);

		$output->writeln($forgotten
			? "Receitas de '{$appId}' apagadas -- o agente vai reexplorar na proxima vez."
			: "Nao havia nenhuma receita guardada para '{$appId}'.");

		return 0;
	}
}
