<?php

declare(strict_types=1);

namespace OCA\AppsAgent\Service;

use OCA\AppsAgent\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Junta os sinais do horizonte diario -- aniversarios, "neste dia" do recall,
 * tarefas a vencer -- numa unica mensagem, NUNCA escreve nada sozinho.
 *
 * Deliberadamente so LE. Se houver algo a registar (ex: uma ideia de prenda
 * para um aniversario), a mensagem PERGUNTA -- a escrita, se vier, acontece
 * pela resposta do utilizador no Telegram normal, que ja passa pelo mesmo
 * historico de conversa e pelo mesmo portao de confirmacao de tudo o resto.
 *
 * Um dia sem nada digno de nota nao gera mensagem nenhuma -- o valor disto
 * vem de ser pouco e escolhido, a mesma regra desenhada no proprio recall.
 */
class ProactiveDigestService {
	/** Nao vale a pena avisar de um aniversario com mais de uma semana de antecedencia. */
	private const BIRTHDAY_WINDOW_DAYS = 7;

	/** Idem para tarefas -- "a vencer em breve", nao "todas as tarefas futuras". */
	private const TASK_WINDOW_DAYS = 3;

	/** URI fixo que o Nextcloud usa para o calendario de aniversarios gerado a partir dos Contactos. */
	private const BIRTHDAY_CALENDAR_URI = 'contact_birthdays';

	public function __construct(
		private CalendarService $calendar,
		private TaskService $tasks,
		private IClientService $clientService,
		private AgentAccountService $agentAccount,
		private InternalHttpService $internalHttp,
		private IConfig $config,
		private LoggerInterface $logger,
	) {
	}

	/** @return string vazio se nao houver nada digno de mensagem hoje */
	public function buildDigest(): string {
		$timezone = new \DateTimeZone(
			$this->config->getAppValue(Application::APP_ID, 'default_timezone', 'UTC')
		);
		$now = new \DateTimeImmutable('now', $timezone);

		$secoes = array_filter([
			$this->birthdaysSection($now),
			$this->onThisDaySection($now),
			$this->tasksSection($now),
		]);

		if ($secoes === []) {
			return '';
		}

		return "Bom dia! Aqui está o que pode interessar hoje:\n\n"
			. implode("\n\n", $secoes)
			. "\n\nQueres que eu registe ou ajuste alguma coisa?";
	}

	private function birthdaysSection(\DateTimeImmutable $now): string {
		try {
			$eventos = $this->calendar->listEvents(
				$now->format(DATE_ATOM),
				$now->modify('+' . self::BIRTHDAY_WINDOW_DAYS . ' days')->format(DATE_ATOM),
				self::BIRTHDAY_CALENDAR_URI,
			);
		} catch (\Throwable $e) {
			// O calendario de aniversarios pode nao estar ativado nesta instancia
			// (occ dav:generate-birthday-calendar / opcao nos Contactos) -- silencioso
			// de proposito, um dia sem aniversarios nao e um erro.
			$this->logger->warning('appsagent: falha ao ler o calendario de aniversarios', ['exception' => $e]);
			return '';
		}

		if ($eventos === []) {
			return '';
		}

		$linhas = array_map(
			static fn (array $e): string => '- ' . self::semSufixoAniversario((string)$e['summary'])
				. ' (' . mb_substr((string)$e['start'], 0, 10) . ')',
			$eventos
		);

		return "🎂 Aniversários nos próximos " . self::BIRTHDAY_WINDOW_DAYS . " dias:\n" . implode("\n", $linhas);
	}

	/** O Nextcloud gera o SUMMARY como "Nome's Birthday" -- mais legivel em portugues sem o sufixo fixo. */
	private static function semSufixoAniversario(string $summary): string {
		return preg_replace('/\'s Birthday$/', '', $summary) ?? $summary;
	}

	private function onThisDaySection(\DateTimeImmutable $now): string {
		[$username, $password] = $this->agentAccount->credentials();
		$path = '/ocs/v2.php/apps/recall/api/v1/episodes/on-this-day';
		[$url, $extraHeaders] = $this->internalHttp->resolve($path);

		try {
			$response = $this->clientService->newClient()->request('GET', $url, [
				'auth' => [$username, $password],
				'timeout' => 15,
				'headers' => $extraHeaders + ['OCS-APIRequest' => 'true', 'Accept' => 'application/json'],
				'query' => ['date' => $now->format('Y-m-d'), 'window' => 0],
				'http_errors' => false,
			]);
		} catch (\Throwable $e) {
			// A app recall pode nao estar instalada -- silencioso de proposito.
			$this->logger->warning('appsagent: falha ao ler o recall para o resumo diario', ['exception' => $e]);
			return '';
		}

		if ($response->getStatusCode() !== 200) {
			return '';
		}
		$body = json_decode((string)$response->getBody(), true);
		$porAno = $body['ocs']['data']['years'] ?? null;
		if (!is_array($porAno) || $porAno === []) {
			return '';
		}

		$linhas = [];
		foreach ($porAno as $ano => $episodios) {
			foreach ((array)$episodios as $episodio) {
				$linhas[] = '- ' . $ano . ': ' . (string)($episodio['title'] ?? '(sem título)');
			}
		}
		if ($linhas === []) {
			return '';
		}

		return "📅 Neste dia, em anos anteriores:\n" . implode("\n", $linhas);
	}

	private function tasksSection(\DateTimeImmutable $now): string {
		try {
			$tarefas = $this->tasks->listTasks(false);
		} catch (\Throwable $e) {
			$this->logger->warning('appsagent: falha ao ler tarefas para o resumo diario', ['exception' => $e]);
			return '';
		}

		$limite = $now->modify('+' . self::TASK_WINDOW_DAYS . ' days');
		$aVencer = array_values(array_filter($tarefas, static function (array $t) use ($now, $limite): bool {
			if ($t['due'] === null) {
				return false;
			}
			try {
				$due = new \DateTimeImmutable($t['due']);
			} catch (\Throwable) {
				return false;
			}
			return $due >= $now->setTime(0, 0) && $due <= $limite;
		}));

		if ($aVencer === []) {
			return '';
		}

		$linhas = array_map(
			static fn (array $t): string => '- ' . $t['summary'] . ' (vence ' . mb_substr((string)$t['due'], 0, 10) . ')',
			$aVencer
		);

		return "✅ Tarefas a vencer nos próximos " . self::TASK_WINDOW_DAYS . " dias:\n" . implode("\n", $linhas);
	}
}
