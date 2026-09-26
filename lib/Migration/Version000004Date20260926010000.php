<?php

declare(strict_types=1);

namespace OCA\AppsAgent\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000004Date20260926010000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('appsagent_confirmations')) {
			$table = $schema->createTable('appsagent_confirmations');
			$table->addColumn('id', 'integer', [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('channel_key', 'string', ['notnull' => true, 'length' => 190]);
			$table->addColumn('signature', 'string', ['notnull' => true, 'length' => 190]);
			$table->addColumn('created_at', 'string', ['notnull' => true, 'length' => 32]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['channel_key', 'signature'], 'appsagent_confirm_uniq');
			$table->addIndex(['created_at'], 'appsagent_confirm_created_idx');
		}

		return $schema;
	}
}
