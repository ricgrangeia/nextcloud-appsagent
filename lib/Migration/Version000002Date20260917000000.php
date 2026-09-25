<?php

declare(strict_types=1);

namespace OCA\AppsAgent\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000002Date20260917000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('appsagent_notes')) {
			$table = $schema->createTable('appsagent_notes');
			$table->addColumn('id', 'integer', [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('topic', 'string', ['notnull' => true, 'length' => 190]);
			$table->addColumn('note', 'text', ['notnull' => true]);
			$table->addColumn('updated_at', 'string', ['notnull' => true, 'length' => 32]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['topic'], 'appsagent_notes_topic_uniq');
		}

		return $schema;
	}
}
