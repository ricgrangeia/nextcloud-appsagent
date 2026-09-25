<?php

declare(strict_types=1);

namespace OCA\AppsAgent\Command;

use OCA\AppsAgent\Service\TaskService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Comando de diagnostico: chama TaskService::listTasks() diretamente, sem
 * passar pelo LLM/AgentService, para isolar se um problema de "nao encontro
 * tarefas" e do codigo CalDAV ou do raciocinio do agente.
 */
class TestTasks extends Command {
	public function __construct(
		private TaskService $tasks,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('appsagent:test-tasks');
		$this->setDescription('Lista tarefas diretamente via TaskService, sem passar pelo LLM (diagnostico)');
		$this->addOption('list', null, InputOption::VALUE_REQUIRED, 'URI da lista de tarefas', null);
		$this->addOption('include-completed', null, InputOption::VALUE_NONE, 'Inclui tarefas concluidas/canceladas');
		$this->addOption('lists', null, InputOption::VALUE_NONE, 'Mostra as listas de tarefas disponiveis em vez das tarefas');
		$this->addOption('raw', null, InputOption::VALUE_NONE, 'Mostra o pedido/resposta CalDAV crus em vez de tarefas interpretadas');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$listUri = $input->getOption('list');

		try {
			if ($input->getOption('lists')) {
				$lists = $this->tasks->listTaskLists();
				$output->writeln('Encontradas ' . count($lists) . ' lista(s) de tarefas:');
				foreach ($lists as $list) {
					$output->writeln(json_encode($list, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
				}
				return 0;
			}

			if ($input->getOption('raw')) {
				$debug = $this->tasks->debugListTasks($listUri);
				$output->writeln('URL: ' . $debug['url']);
				$output->writeln('Estado HTTP: ' . $debug['status']);
				$output->writeln('--- Corpo do pedido ---');
				$output->writeln($debug['request_body']);
				$output->writeln('--- Corpo da resposta ---');
				$output->writeln($debug['response_body']);
				return 0;
			}

			$tasksList = $this->tasks->listTasks($input->getOption('include-completed'), $listUri);
		} catch (\Throwable $e) {
			$output->writeln('ERRO: ' . $e->getMessage());
			return 1;
		}

		$output->writeln('Encontrada(s) ' . count($tasksList) . ' tarefa(s):');
		foreach ($tasksList as $task) {
			$output->writeln(json_encode($task, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
		}

		return 0;
	}
}
