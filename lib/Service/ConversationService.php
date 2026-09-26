<?php

declare(strict_types=1);

namespace OCA\AppsAgent\Service;

use OCA\AppsAgent\Db\ChatTurnMapper;

/**
 * Historico curto de conversa por canal externo (hoje so o Telegram).
 *
 * Existe por causa de um bug real: o TelegramController chamava
 * AgentService::run() com so a mensagem atual, sem rasto nenhum da troca
 * anterior. Quando o utilizador respondia "sim" a uma confirmacao pedida
 * pelo agente, essa mensagem chegava sozinha -- "Instrução do utilizador:
 * sim" -- sem nada a que se agarrar, e o modelo inventou uma acao plausivel
 * em vez de admitir que nao sabia a que estava a responder.
 *
 * O formato de historico e o MESMO que o painel Assistente do proprio
 * Nextcloud ja manda ao agente (ver ChatHistoryFormat) -- reutiliza-se em
 * vez de inventar outro, para o modelo nao ter de aprender dois formatos.
 */
class ConversationService {
	/** Turnos a mais tornam o prompt caro sem ajudar -- a confirmacao so precisa dos ultimos passos. */
	private const MAX_TURNS = 12;

	/**
	 * Se a ultima mensagem for mais antiga que isto, a conversa conta como
	 * esquecida -- um "sim" horas depois nao deve agarrar-se a uma pergunta
	 * antiga que ja nem faz sentido.
	 */
	private const IDLE_SECONDS = 1800;

	/** Retencao maxima antes da limpeza de fundo (ver QueueWorker), independente do canal. */
	private const RETENTION_SECONDS = 86400;

	public function __construct(
		private ChatTurnMapper $mapper,
	) {
	}

	public function withHistory(string $chatKey, string $newMessage): string {
		$turns = array_map(
			static fn ($turno): array => ['role' => $turno->getRole(), 'content' => $turno->getContent()],
			$this->mapper->recent($chatKey, self::IDLE_SECONDS, self::MAX_TURNS)
		);

		return ChatHistoryFormat::build($turns, $newMessage);
	}

	public function remember(string $chatKey, string $userText, string $assistantReply): void {
		$this->mapper->append($chatKey, 'user', $userText);
		$this->mapper->append($chatKey, 'assistant', $assistantReply);
	}

	/** Chamado a cada ciclo do QueueWorker. @return int quantos turnos foram apagados */
	public function pruneStale(): int {
		$before = (new \DateTimeImmutable('-' . self::RETENTION_SECONDS . ' seconds'))->format(DATE_ATOM);
		return $this->mapper->deleteOlderThan($before);
	}

	/** Chave estavel para o historico de um chat do Telegram. */
	public static function telegramKey(int $chatId): string {
		return 'telegram:' . $chatId;
	}
}
