<?php

declare(strict_types=1);

namespace OCA\AppsAgent\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getText()
 * @method void setText(string $text)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string|null getResult()
 * @method void setResult(?string $result)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 * @method string|null getProcessedAt()
 * @method void setProcessedAt(?string $processedAt)
 */
class Instruction extends Entity implements \JsonSerializable {
	protected $text;
	protected $status;
	protected $result;
	protected $createdAt;
	protected $processedAt;

	public function __construct() {
		$this->addType('id', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'text' => $this->text,
			'status' => $this->status,
			'result' => $this->result,
			'createdAt' => $this->createdAt,
			'processedAt' => $this->processedAt,
		];
	}
}
