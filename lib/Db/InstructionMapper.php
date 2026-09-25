<?php

declare(strict_types=1);

namespace OCA\AppsAgent\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Instruction>
 */
class InstructionMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'appsagent_instructions', Instruction::class);
	}

	/** @return Instruction[] */
	public function findPending(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter('pending')))
			->orderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/** @return Instruction[] */
	public function findAllOrdered(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	public function insertPending(string $text): Instruction {
		$instruction = new Instruction();
		$instruction->setText($text);
		$instruction->setStatus('pending');
		$instruction->setCreatedAt((new \DateTimeImmutable())->format(DATE_ATOM));

		return $this->insert($instruction);
	}

	public function markProcessed(Instruction $instruction, string $status, string $result): void {
		$instruction->setStatus($status);
		$instruction->setResult($result);
		$instruction->setProcessedAt((new \DateTimeImmutable())->format(DATE_ATOM));
		$this->update($instruction);
	}
}
