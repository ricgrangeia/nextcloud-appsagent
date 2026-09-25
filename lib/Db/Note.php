<?php

declare(strict_types=1);

namespace OCA\AppsAgent\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getTopic()
 * @method void setTopic(string $topic)
 * @method string getNote()
 * @method void setNote(string $note)
 * @method string getUpdatedAt()
 * @method void setUpdatedAt(string $updatedAt)
 */
class Note extends Entity implements \JsonSerializable {
	protected $topic;
	protected $note;
	protected $updatedAt;

	public function __construct() {
		$this->addType('id', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'topic' => $this->topic,
			'note' => $this->note,
			'updatedAt' => $this->updatedAt,
		];
	}
}
