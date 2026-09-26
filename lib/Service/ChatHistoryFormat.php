<?php

declare(strict_types=1);

namespace OCA\AppsAgent\Service;

/**
 * Formato de texto partilhado para injetar historico de conversa numa
 * instrucao -- o MESMO que o painel Assistente do Nextcloud ja usa via
 * AgentChatProvider/AgentChatWithToolsProvider. Extraido daqui para o
 * ConversationService (Telegram) usar tambem, em vez de reinventar o
 * formato -- o modelo ja esta habituado a este, nao a outro.
 */
class ChatHistoryFormat {
	/**
	 * @param list<array{role: string, content: string}> $turns
	 */
	public static function build(array $turns, string $newMessage): string {
		if ($turns === []) {
			return $newMessage;
		}

		$linhas = array_map(
			static function (array $turno): string {
				$papel = $turno['role'] === 'user' ? 'Utilizador'
					: ($turno['role'] === 'assistant' ? 'Assistente' : $turno['role']);
				return $papel . ': ' . $turno['content'];
			},
			$turns
		);

		return "Historico da conversa ate agora:\n" . implode("\n", $linhas)
			. "\n\nNova mensagem do utilizador: " . $newMessage;
	}
}
