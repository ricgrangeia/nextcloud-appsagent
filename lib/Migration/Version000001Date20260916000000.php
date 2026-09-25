<?php

declare(strict_types=1);

namespace OCA\AppsAgent\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000001Date20260916000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('appsagent_instructions')) {
			$table = $schema->createTable('appsagent_instructions');
			$table->addColumn('id', 'integer', [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('text', 'text', ['notnull' => true]);
			$table->addColumn('status', 'string', ['notnull' => true, 'length' => 32, 'default' => 'pending']);
			$table->addColumn('result', 'text', ['notnull' => false]);
			$table->addColumn('created_at', 'string', ['notnull' => true, 'length' => 32]);
			$table->addColumn('processed_at', 'string', ['notnull' => false, 'length' => 32]);
			$table->setPrimaryKey(['id']);
		}

		return $schema;
	}
}
