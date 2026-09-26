<?php

declare(strict_types=1);

namespace OCA\AppsAgent\Command;

use OCA\AppsAgent\Service\ConversationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Apaga o historico de conversa de um canal (hoje so o Telegram).
 *
 * Existe porque a memoria de conversa (ver ConversationService) esquece-se
 * sozinha ao fim de 30 minutos de silencio -- mas se uma crenca errada do
 * modelo ficar gravada numa resposta sua (ex: "estou bloqueado para
 * sempre"), ela repete-se sozinha em cada turno seguinte enquanto a
 * conversa continuar ativa, e esperar meia hora so para testar uma
 * correcao e desnecessario.
 */
class ForgetChat extends Command {
	public function __construct(
		private ConversationService $conversation,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('appsagent:forget-chat');
		$this->setDescription('Apaga o historico de conversa de um chat do Telegram (ou de todos)');
		$this->addArgument('chat_id', InputArgument::OPTIONAL, 'ID numerico do chat do Telegram');
		$this->addOption('all', null, InputOption::VALUE_NONE, 'Apaga o historico de TODOS os canais');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$chatId = (string)$input->getArgument('chat_id');
		$all = (bool)$input->getOption('all');
		$hasId = $chatId !== '';

		if ($hasId === $all) {
			$output->writeln('ERRO: indica um chat_id OU --all, nao ambos nem nenhum.');
			return 1;
		}

		if ($all) {
			$apagados = $this->conversation->forgetAll();
		} else {
			if (!ctype_digit($chatId)) {
				$output->writeln('ERRO: chat_id deve ser numerico (o ID do chat do Telegram).');
				return 1;
			}
			$apagados = $this->conversation->forgetChat(ConversationService::telegramKey((int)$chatId));
		}

		$output->writeln($apagados . ' turno(s) de conversa apagado(s).');
		return 0;
	}
}
