<?php

declare(strict_types=1);

namespace OCA\AppsAgent\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000003Date20260926000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('appsagent_chat_turns')) {
			$table = $schema->createTable('appsagent_chat_turns');
			$table->addColumn('id', 'integer', [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('chat_key', 'string', ['notnull' => true, 'length' => 190]);
			$table->addColumn('role', 'string', ['notnull' => true, 'length' => 16]);
			$table->addColumn('content', 'text', ['notnull' => true]);
			$table->addColumn('created_at', 'string', ['notnull' => true, 'length' => 32]);
			$table->setPrimaryKey(['id']);
			// Cobre as duas consultas reais: recent() filtra por chat_key e
			// ordena por id; deleteOlderThan() varre por created_at sozinho
			// (por isso um indice simples nesse, nao composto).
			$table->addIndex(['chat_key', 'id'], 'appsagent_turns_chat_idx');
			$table->addIndex(['created_at'], 'appsagent_turns_created_idx');
		}

		return $schema;
	}
}
