<?php

declare(strict_types=1);

namespace OCA\AppsAgent\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getChannelKey()
 * @method void setChannelKey(string $channelKey)
 * @method string getSignature()
 * @method void setSignature(string $signature)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 */
class PendingConfirmation extends Entity {
	protected $channelKey;
	protected $signature;
	protected $createdAt;

	public function __construct() {
		$this->addType('id', 'integer');
	}
}
