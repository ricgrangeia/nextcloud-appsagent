<?php

declare(strict_types=1);

namespace OCA\AppsAgent\Command;

use OCA\AppsAgent\Service\CalendarService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Comando de diagnostico: chama CalendarService::listEvents() diretamente,
 * sem passar pelo LLM/AgentService, para isolar se um problema de "nao
 * encontro eventos" e do codigo do calendario ou do raciocinio do agente.
 */
class TestCalendar extends Command {
	public function __construct(
		private CalendarService $calendar,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('appsagent:test-calendar');
		$this->setDescription('Lista eventos diretamente via CalendarService, sem passar pelo LLM (diagnostico)');
		$this->addOption('start', null, InputOption::VALUE_REQUIRED, 'Inicio ISO 8601', null);
		$this->addOption('end', null, InputOption::VALUE_REQUIRED, 'Fim ISO 8601', null);
		$this->addOption('calendar', null, InputOption::VALUE_REQUIRED, 'URI do calendario', null);
		$this->addOption('raw', null, InputOption::VALUE_NONE, 'Mostra o pedido/resposta CalDAV crus em vez de eventos interpretados');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$start = $input->getOption('start');
		$end = $input->getOption('end');
		$calendar = $input->getOption('calendar');

		try {
			if ($input->getOption('raw')) {
				$debug = $this->calendar->debugListEvents($start, $end, $calendar);
				$output->writeln('URL: ' . $debug['url']);
				$output->writeln('Estado HTTP: ' . $debug['status']);
				$output->writeln('--- Corpo do pedido ---');
				$output->writeln($debug['request_body']);
				$output->writeln('--- Corpo da resposta ---');
				$output->writeln($debug['response_body']);
				return 0;
			}

			$events = $this->calendar->listEvents($start, $end, $calendar);
		} catch (\Throwable $e) {
			$output->writeln('ERRO: ' . $e->getMessage());
			return 1;
		}

		$output->writeln('Encontrados ' . count($events) . ' evento(s):');
		foreach ($events as $event) {
			$output->writeln(json_encode($event, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
		}

		return 0;
	}
}
