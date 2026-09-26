<?php

declare(strict_types=1);

namespace OCA\AppsAgent\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<ChatTurn>
 *
 * Historico de conversa por canal (ex: "telegram:123456"), curto e com
 * expiracao propria -- existe SO para o agente saber a que esta a responder
 * quando confirmas uma acao numa mensagem seguinte ("sim força" nao diz nada
 * por si so). Nao e a memoria de longo prazo (isso e o MemoryService) nem o
 * recall (isso sao memorias do UTILIZADOR, com data); isto e so o rasto de
 * uma troca em curso, e some sozinho se ficar parada.
 */
class ChatTurnMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'appsagent_chat_turns', ChatTurn::class);
	}

	public function append(string $chatKey, string $role, string $content): void {
		$entity = new ChatTurn();
		$entity->setChatKey($chatKey);
		$entity->setRole($role);
		$entity->setContent($content);
		$entity->setCreatedAt((new \DateTimeImmutable())->format(DATE_ATOM));
		$this->insert($entity);
	}

	/**
	 * Os turnos recentes desta conversa, do mais antigo para o mais recente
	 * (ordem de leitura). Se a ultima mensagem for mais antiga que
	 * $maxAgeSeconds, a conversa e tratada como esquecida -- devolve-se vazio
	 * em vez de agarrar "sim" a uma pergunta de horas atras.
	 *
	 * @return ChatTurn[]
	 */
	public function recent(string $chatKey, int $maxAgeSeconds, int $maxTurns): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('chat_key', $qb->createNamedParameter($chatKey)))
			->orderBy('id', 'DESC')
			->setMaxResults(max(1, $maxTurns));

		/** @var ChatTurn[] $turns */
		$turns = $this->findEntities($qb);
		if ($turns === []) {
			return [];
		}

		$cutoff = (new \DateTimeImmutable('-' . max(1, $maxAgeSeconds) . ' seconds'))->format(DATE_ATOM);
		if ($turns[0]->getCreatedAt() < $cutoff) {
			// O turno mais recente ja e mais velho que o limite -- a conversa
			// parou ha demasiado tempo, nao arrastar para a mensagem nova.
			return [];
		}

		return array_reverse($turns);
	}

	/**
	 * Limpeza de fundo (ver QueueWorker): apaga turnos com mais de
	 * $beforeIso, independentemente do canal.
	 *
	 * @return int quantos foram apagados
	 */
	public function deleteOlderThan(string $beforeIso): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->lt('created_at', $qb->createNamedParameter($beforeIso)));

		return $qb->executeStatement();
	}
}
