<?php

declare(strict_types=1);

namespace OCA\AppsAgent\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Uma mensagem (do utilizador ou do agente) dentro de uma conversa por
 * canal -- ver ChatTurnMapper para o porque de isto existir.
 *
 * @method string getChatKey()
 * @method void setChatKey(string $chatKey)
 * @method string getRole()
 * @method void setRole(string $role)
 * @method string getContent()
 * @method void setContent(string $content)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 */
class ChatTurn extends Entity {
	protected $chatKey;
	protected $role;
	protected $content;
	protected $createdAt;

	public function __construct() {
		$this->addType('id', 'integer');
	}
}
