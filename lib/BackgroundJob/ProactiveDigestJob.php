<?php

declare(strict_types=1);

namespace OCA\AppsAgent\BackgroundJob;

use OCA\AppsAgent\AppInfo\Application;
use OCA\AppsAgent\Service\AgentAccountService;
use OCA\AppsAgent\Service\ConversationService;
use OCA\AppsAgent\Service\ProactiveDigestService;
use OCA\AppsAgent\Service\TelegramClient;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * O motorzinho proativo: uma vez por dia, manda pelo Telegram o que for
 * digno de nota (aniversarios, "neste dia" do recall, tarefas a vencer).
 *
 * DESLIGADO por omissao (proactive_enabled=yes para ativar) -- e capacidade
 * nova, a mandar mensagens sem ninguem pedir, e fica a escolha explicita do
 * utilizador ligar. Corre a cada hora (o intervalo do TimedJob), mas so
 * ACONTECE uma vez por dia: guarda a data do ultimo envio e compara com a
 * hora local configurada.
 *
 * NUNCA escreve nada sozinho -- so le e manda uma mensagem. Se o utilizador
 * responder a pedir para registar algo, essa resposta entra pelo
 * TelegramController normal, com o mesmo historico de conversa e o mesmo
 * portao de confirmacao de qualquer outra acao destrutiva/de escrita.
 */
class ProactiveDigestJob extends TimedJob {
	private const CONFIG_ENABLED = 'proactive_enabled';
	private const CONFIG_HOUR = 'proactive_hour';
	private const CONFIG_LAST_RUN_DATE = 'proactive_last_run_date';

	public function __construct(
		ITimeFactory $time,
		private ProactiveDigestService $digest,
		private TelegramClient $telegramClient,
		private ConversationService $conversation,
		private AgentAccountService $agentAccount,
		private IConfig $config,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(3600);
	}

	protected function run($argument): void {
		if ($this->config->getAppValue(Application::APP_ID, self::CONFIG_ENABLED, 'no') !== 'yes') {
			return;
		}

		$timezone = new \DateTimeZone(
			$this->config->getAppValue(Application::APP_ID, 'default_timezone', 'UTC')
		);
		$now = new \DateTimeImmutable('now', $timezone);
		$today = $now->format('Y-m-d');

		if ($this->config->getAppValue(Application::APP_ID, self::CONFIG_LAST_RUN_DATE, '') === $today) {
			return;
		}

		$targetHour = max(0, min(23, (int)$this->config->getAppValue(Application::APP_ID, self::CONFIG_HOUR, '8')));
		if ((int)$now->format('G') < $targetHour) {
			return;
		}

		try {
			[$nextcloudUserId] = $this->agentAccount->credentials();
		} catch (\Throwable $e) {
			$this->logger->warning('appsagent: resumo diario sem conta configurada', ['exception' => $e]);
			return;
		}

		$chatId = $this->telegramClient->findChatIdForUser($nextcloudUserId);
		if ($chatId === null) {
			$this->logger->warning('appsagent: resumo diario sem chat do Telegram associado a ' . $nextcloudUserId);
			return;
		}

		try {
			$texto = $this->digest->buildDigest();
		} catch (\Throwable $e) {
			$this->logger->error('appsagent: falha a montar o resumo diario', ['exception' => $e]);
			return;
		}

		// So se marca como feito DEPOIS de tentar enviar -- se isto falhar
		// (ex: Telegram em baixo), a proxima hora tenta outra vez no mesmo dia.
		if ($texto === '') {
			$this->config->setAppValue(Application::APP_ID, self::CONFIG_LAST_RUN_DATE, $today);
			return;
		}

		try {
			$this->telegramClient->sendMessage($chatId, $texto);
		} catch (\Throwable $e) {
			$this->logger->error('appsagent: falha a enviar o resumo diario pelo Telegram', ['exception' => $e]);
			return;
		}

		// Grava-se como resposta do agente -- se o utilizador responder "sim,
		// regista a prenda", o historico ja tem o contexto do que foi perguntado.
		$this->conversation->remember(ConversationService::telegramKey($chatId), '(resumo diario automatico)', $texto);
		$this->config->setAppValue(Application::APP_ID, self::CONFIG_LAST_RUN_DATE, $today);
	}
}
