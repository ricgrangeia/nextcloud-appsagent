<?php

declare(strict_types=1);

namespace OCA\AppsAgent\Command;

use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\ISynchronousProvider;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Diagnostico: mostra os providers de Task Processing realmente registados
 * nesta instancia, e a que tipo de tarefa cada um responde.
 *
 * Existe porque o ReasoningService assume "texto -> texto" (core:text2text)
 * e nada mais -- antes de construir qualquer coisa com imagens (ex: ler uma
 * foto de um conta-quilometros), e preciso de saber se ha algum provider
 * registado para um tipo de tarefa que aceite imagem como entrada, em vez de
 * supor pelo nome. Ver a que tipo de tarefa serve, nao adivinhar.
 */
class ListProviders extends Command {
	public function __construct(
		private IManager $taskProcessingManager,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('appsagent:providers');
		$this->setDescription('Lista os providers de Task Processing registados e o tipo de tarefa de cada um');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$providers = $this->taskProcessingManager->getProviders();
		if ($providers === []) {
			$output->writeln('Nenhum provider de Task Processing registado.');
			return 0;
		}

		foreach ($providers as $provider) {
			// getId()/getTaskTypeId() so estao confirmados na interface
			// sincrona -- nao presumir que existem nos restantes tipos.
			if ($provider instanceof ISynchronousProvider) {
				$output->writeln(sprintf(
					'%s -> tarefa "%s" | sincrono: sim | classe: %s',
					$provider->getId(),
					$provider->getTaskTypeId(),
					get_class($provider),
				));
			} else {
				$output->writeln(sprintf('(assincrono, sem id/tarefa expostos aqui) | classe: %s', get_class($provider)));
			}
		}

		return 0;
	}
}
