<?php

declare(strict_types=1);

namespace OCA\AppsAgent\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<PendingConfirmation>
 *
 * Existe porque "o modelo poe confirm:true so numa mensagem separada" e uma
 * regra de PROMPT, e um prompt e so texto -- confirmado duas vezes no mesmo
 * dia que o modelo nao a respeita sempre (uma pediu confirmacao "por acaso"
 * depois de listar; outra executou logo, no mesmo turno, sem perguntar
 * nada). Isto move a garantia para o codigo: a MESMA acao destrutiva, no
 * MESMO canal, tem de ja ter sido vista numa chamada ANTERIOR (outro turno)
 * antes de poder executar -- nao importa se o modelo pos confirm:true ou
 * nao da primeira vez, a primeira vez e sempre recusada.
 */
class PendingConfirmationMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'appsagent_confirmations', PendingConfirmation::class);
	}

	private function find(string $channelKey, string $signature): ?PendingConfirmation {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('channel_key', $qb->createNamedParameter($channelKey)))
			->andWhere($qb->expr()->eq('signature', $qb->createNamedParameter($signature)));

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Testa se esta accao ja tinha sido vista antes (dentro da janela de
	 * frescura) e, se nao tinha, regista-a agora para a proxima vez.
	 *
	 * @return bool true se JA existia um registo fresco (turno anterior);
	 *   false se esta e a primeira vez que se ve esta accao neste canal
	 */
	public function seenBefore(string $channelKey, string $signature, int $maxAgeSeconds): bool {
		$existing = $this->find($channelKey, $signature);
		$cutoff = (new \DateTimeImmutable('-' . max(1, $maxAgeSeconds) . ' seconds'))->format(DATE_ATOM);

		if ($existing !== null && $existing->getCreatedAt() >= $cutoff) {
			return true;
		}

		$now = (new \DateTimeImmutable())->format(DATE_ATOM);
		if ($existing !== null) {
			// Registo antigo demais para contar -- renova-se, como se fosse novo.
			$existing->setCreatedAt($now);
			$this->update($existing);
		} else {
			$entity = new PendingConfirmation();
			$entity->setChannelKey($channelKey);
			$entity->setSignature($signature);
			$entity->setCreatedAt($now);
			$this->insert($entity);
		}

		return false;
	}

	/** Chamado depois de uma execucao confirmada com sucesso. */
	public function consume(string $channelKey, string $signature): void {
		$existing = $this->find($channelKey, $signature);
		if ($existing !== null) {
			$this->delete($existing);
		}
	}

	/** Limpeza de fundo (ver QueueWorker). @return int quantos foram apagados */
	public function deleteOlderThan(string $beforeIso): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->lt('created_at', $qb->createNamedParameter($beforeIso)));

		return $qb->executeStatement();
	}
}
