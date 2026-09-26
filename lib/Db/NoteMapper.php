<?php

declare(strict_types=1);

namespace OCA\AppsAgent\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Note>
 */
class NoteMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'appsagent_notes', Note::class);
	}

	/** @throws DoesNotExistException */
	public function findByTopic(string $topic): Note {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('topic', $qb->createNamedParameter($topic)));

		return $this->findEntity($qb);
	}

	/** @return Note[] */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('topic', 'ASC');

		return $this->findEntities($qb);
	}

	public function upsert(string $topic, string $note): Note {
		try {
			$entity = $this->findByTopic($topic);
			$entity->setNote($note);
			$entity->setUpdatedAt((new \DateTimeImmutable())->format(DATE_ATOM));
			return $this->update($entity);
		} catch (DoesNotExistException) {
			$entity = new Note();
			$entity->setTopic($topic);
			$entity->setNote($note);
			$entity->setUpdatedAt((new \DateTimeImmutable())->format(DATE_ATOM));
			return $this->insert($entity);
		}
	}

	/** @return bool true se existia e foi apagada, false se nao existia */
	public function deleteByTopic(string $topic): bool {
		try {
			$entity = $this->findByTopic($topic);
		} catch (DoesNotExistException) {
			return false;
		}
		$this->delete($entity);
		return true;
	}

	/**
	 * Apaga em lote os topicos com este prefixo mais antigos que $beforeIso,
	 * sem precisar de os ler um a um primeiro.
	 *
	 * @return int quantos foram apagados
	 */
	public function deleteOlderThanByPrefix(string $prefix, string $beforeIso): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->like('topic', $qb->createNamedParameter(
				$this->db->escapeLikeParameter($prefix) . '%'
			)))
			->andWhere($qb->expr()->lt('updated_at', $qb->createNamedParameter($beforeIso)));

		return $qb->executeStatement();
	}
}
