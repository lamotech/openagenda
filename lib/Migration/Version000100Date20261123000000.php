<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Migration;

use Closure;
use Doctrine\DBAL\Schema\Table;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Initial schema for OpenAgenda v0.1.
 *
 * Conventions:
 * - integer auto-increment primary key `id` (required by OCP Entity) + unique `uuid` used in APIs
 * - timestamps are UNIX seconds (UTC) stored as BIGINT
 * - JSON payloads are stored in TEXT columns and (de)serialised by the entities
 * - oa_mock_* tables belong exclusively to the MockOpenCaseAdapter (simulated OpenCase data)
 */
class Version000100Date20261123000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('oa_committees')) {
			$t = $schema->createTable('oa_committees');
			$this->idAndUuid($t);
			$t->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('short_name', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('description', Types::TEXT, ['notnull' => false]);
			$t->addColumn('type', Types::STRING, ['notnull' => true, 'length' => 32]);
			$t->addColumn('active', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
			$this->timestamps($t);
			$t->addUniqueIndex(['name'], 'oa_committee_name_uniq');
		}

		if (!$schema->hasTable('oa_meetings')) {
			$t = $schema->createTable('oa_meetings');
			$this->idAndUuid($t);
			$t->addColumn('committee_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('starts_at', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('ends_at', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('location', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 32]);
			$t->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
			$this->timestamps($t);
			$t->addIndex(['committee_id'], 'oa_meeting_committee_idx');
			$t->addIndex(['starts_at'], 'oa_meeting_starts_idx');
			$t->addForeignKeyConstraint($schema->getTable('oa_committees'), ['committee_id'], ['id'], [], 'oa_meeting_committee_fk');
		}

		if (!$schema->hasTable('oa_pol_cases')) {
			$t = $schema->createTable('oa_pol_cases');
			$this->idAndUuid($t);
			$t->addColumn('opencase_case_id', Types::STRING, ['notnull' => true, 'length' => 128]);
			$t->addColumn('case_number', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 32]);
			$t->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
			$this->timestamps($t);
			$t->addIndex(['opencase_case_id'], 'oa_polcase_opencase_idx');
		}

		if (!$schema->hasTable('oa_agenda_items')) {
			$t = $schema->createTable('oa_agenda_items');
			$this->idAndUuid($t);
			$t->addColumn('meeting_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('political_case_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('summary', Types::TEXT, ['notnull' => false]);
			$t->addColumn('presentation', Types::TEXT, ['notnull' => false]);
			$t->addColumn('recommendation', Types::TEXT, ['notnull' => false]);
			$t->addColumn('legal_basis', Types::TEXT, ['notnull' => false]);
			$t->addColumn('financial_impact', Types::TEXT, ['notnull' => false]);
			$t->addColumn('attachments', Types::TEXT, ['notnull' => false]);
			$t->addColumn('is_public', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
			$t->addColumn('sort_order', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 32]);
			$t->addColumn('rejection_reason', Types::TEXT, ['notnull' => false]);
			$t->addColumn('forwarded_from_item_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('updated_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$this->timestamps($t);
			$t->addIndex(['meeting_id', 'sort_order'], 'oa_item_meeting_idx');
			$t->addIndex(['political_case_id'], 'oa_item_polcase_idx');
			$t->addForeignKeyConstraint($schema->getTable('oa_meetings'), ['meeting_id'], ['id'], [], 'oa_item_meeting_fk');
			$t->addForeignKeyConstraint($schema->getTable('oa_pol_cases'), ['political_case_id'], ['id'], [], 'oa_item_polcase_fk');
		}

		if (!$schema->hasTable('oa_treatments')) {
			$t = $schema->createTable('oa_treatments');
			$this->idAndUuid($t);
			$t->addColumn('political_case_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('meeting_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('agenda_item_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('recommendation', Types::TEXT, ['notnull' => false]);
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 32]);
			$t->addColumn('treated_at', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('previous_treatment_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('forwarded_to_item_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$this->timestamps($t);
			$t->addUniqueIndex(['agenda_item_id'], 'oa_treatment_item_uniq');
			$t->addIndex(['political_case_id'], 'oa_treatment_polcase_idx');
			$t->addIndex(['meeting_id'], 'oa_treatment_meeting_idx');
			$t->addForeignKeyConstraint($schema->getTable('oa_agenda_items'), ['agenda_item_id'], ['id'], [], 'oa_treatment_item_fk');
			$t->addForeignKeyConstraint($schema->getTable('oa_meetings'), ['meeting_id'], ['id'], [], 'oa_treatment_meeting_fk');
			$t->addForeignKeyConstraint($schema->getTable('oa_pol_cases'), ['political_case_id'], ['id'], [], 'oa_treatment_polcase_fk');
		}

		if (!$schema->hasTable('oa_decisions')) {
			$t = $schema->createTable('oa_decisions');
			$this->idAndUuid($t);
			$t->addColumn('treatment_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('decision_type', Types::STRING, ['notnull' => true, 'length' => 32]);
			$t->addColumn('decision_text', Types::TEXT, ['notnull' => false]);
			$t->addColumn('decided_by', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('decided_at', Types::BIGINT, ['notnull' => true]);
			$this->timestamps($t);
			$t->addUniqueIndex(['treatment_id'], 'oa_decision_treatment_uniq');
			$t->addForeignKeyConstraint($schema->getTable('oa_treatments'), ['treatment_id'], ['id'], [], 'oa_decision_treatment_fk');
		}

		if (!$schema->hasTable('oa_snapshots')) {
			$t = $schema->createTable('oa_snapshots');
			$this->idAndUuid($t);
			$t->addColumn('meeting_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('version', Types::INTEGER, ['notnull' => true]);
			$t->addColumn('content', Types::TEXT, ['notnull' => true]);
			$t->addColumn('content_hash', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
			$t->addUniqueIndex(['meeting_id', 'version'], 'oa_snapshot_version_uniq');
			$t->addForeignKeyConstraint($schema->getTable('oa_meetings'), ['meeting_id'], ['id'], [], 'oa_snapshot_meeting_fk');
		}

		if (!$schema->hasTable('oa_protocols')) {
			$t = $schema->createTable('oa_protocols');
			$this->idAndUuid($t);
			$t->addColumn('meeting_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('version', Types::INTEGER, ['notnull' => true]);
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 32]);
			$t->addColumn('content', Types::TEXT, ['notnull' => true]);
			$t->addColumn('html', Types::TEXT, ['notnull' => true]);
			$t->addColumn('content_hash', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('generated_by', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('generated_at', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('approved_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('approved_at', Types::BIGINT, ['notnull' => false]);
			$t->addUniqueIndex(['meeting_id', 'version'], 'oa_protocol_version_uniq');
			$t->addForeignKeyConstraint($schema->getTable('oa_meetings'), ['meeting_id'], ['id'], [], 'oa_protocol_meeting_fk');
		}

		if (!$schema->hasTable('oa_journalizations')) {
			$t = $schema->createTable('oa_journalizations');
			$this->idAndUuid($t);
			$t->addColumn('protocol_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('opencase_case_id', Types::STRING, ['notnull' => true, 'length' => 128]);
			$t->addColumn('idempotency_key', Types::STRING, ['notnull' => true, 'length' => 191]);
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 32]);
			$t->addColumn('external_ref', Types::STRING, ['notnull' => false, 'length' => 128]);
			$t->addColumn('error_message', Types::TEXT, ['notnull' => false]);
			$t->addColumn('attempts', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('payload_hash', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
			$this->timestamps($t);
			$t->addUniqueIndex(['idempotency_key'], 'oa_journal_idem_uniq');
			$t->addUniqueIndex(['protocol_id', 'opencase_case_id'], 'oa_journal_proto_case_uniq');
			$t->addForeignKeyConstraint($schema->getTable('oa_protocols'), ['protocol_id'], ['id'], [], 'oa_journal_protocol_fk');
		}

		if (!$schema->hasTable('oa_publications')) {
			$t = $schema->createTable('oa_publications');
			$this->idAndUuid($t);
			$t->addColumn('meeting_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('snapshot_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$t->addColumn('protocol_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 32]);
			$t->addColumn('content', Types::TEXT, ['notnull' => true]);
			$t->addColumn('published_by', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('published_at', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('withdrawn_at', Types::BIGINT, ['notnull' => false]);
			$t->addIndex(['meeting_id', 'status'], 'oa_publication_meeting_idx');
			$t->addForeignKeyConstraint($schema->getTable('oa_meetings'), ['meeting_id'], ['id'], [], 'oa_publication_meeting_fk');
			$t->addForeignKeyConstraint($schema->getTable('oa_snapshots'), ['snapshot_id'], ['id'], [], 'oa_publication_snapshot_fk');
			$t->addForeignKeyConstraint($schema->getTable('oa_protocols'), ['protocol_id'], ['id'], [], 'oa_publication_protocol_fk');
		}

		if (!$schema->hasTable('oa_audit_events')) {
			$t = $schema->createTable('oa_audit_events');
			$this->idAndUuid($t);
			$t->addColumn('occurred_at', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('user_id', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('action', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('object_type', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('object_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('meeting_uuid', Types::STRING, ['notnull' => false, 'length' => 36]);
			$t->addColumn('metadata', Types::TEXT, ['notnull' => false]);
			$t->addIndex(['object_type', 'object_id'], 'oa_audit_object_idx');
			$t->addIndex(['meeting_uuid'], 'oa_audit_meeting_idx');
			$t->addIndex(['occurred_at'], 'oa_audit_time_idx');
		}

		// --- Simulated OpenCase storage (MockOpenCaseAdapter only) ---
		if (!$schema->hasTable('oa_mock_cases')) {
			$t = $schema->createTable('oa_mock_cases');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->setPrimaryKey(['id']);
			$t->addColumn('external_id', Types::STRING, ['notnull' => true, 'length' => 128]);
			$t->addColumn('case_number', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('description', Types::TEXT, ['notnull' => false]);
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('responsible_unit', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
			$t->addUniqueIndex(['external_id'], 'oa_mockcase_ext_uniq');
			$t->addUniqueIndex(['case_number'], 'oa_mockcase_number_uniq');
		}

		if (!$schema->hasTable('oa_mock_documents')) {
			$t = $schema->createTable('oa_mock_documents');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->setPrimaryKey(['id']);
			$t->addColumn('external_id', Types::STRING, ['notnull' => true, 'length' => 128]);
			$t->addColumn('case_external_id', Types::STRING, ['notnull' => true, 'length' => 128]);
			$t->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('document_type', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('access_level', Types::STRING, ['notnull' => true, 'length' => 32]);
			$t->addColumn('versions', Types::TEXT, ['notnull' => true]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
			$t->addUniqueIndex(['external_id'], 'oa_mockdoc_ext_uniq');
			$t->addIndex(['case_external_id'], 'oa_mockdoc_case_idx');
		}

		if (!$schema->hasTable('oa_mock_journal')) {
			$t = $schema->createTable('oa_mock_journal');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$t->setPrimaryKey(['id']);
			$t->addColumn('journal_ref', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('idempotency_key', Types::STRING, ['notnull' => true, 'length' => 191]);
			$t->addColumn('case_external_id', Types::STRING, ['notnull' => true, 'length' => 128]);
			$t->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('document_type', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('payload_hash', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('content', Types::TEXT, ['notnull' => true]);
			$t->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
			$t->addUniqueIndex(['journal_ref'], 'oa_mockjournal_ref_uniq');
			$t->addUniqueIndex(['idempotency_key'], 'oa_mockjournal_idem_uniq');
		}

		return $schema;
	}

	private function idAndUuid(Table $table): void {
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->setPrimaryKey(['id']);
		$table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
		$table->addUniqueIndex(['uuid'], $table->getName() . '_uuid_uniq');
	}

	private function timestamps(Table $table): void {
		$table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
		$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true]);
	}
}
