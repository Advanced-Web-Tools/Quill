<?php

namespace Quill\services;

use database\creator\ColumnCreator as Column;
use database\creator\TableWizard;
use RuntimeException;

class Migration
{
    /** @var array<string, list<Column>> */
    private array $tables;

    public function __construct()
    {
        $this->tables = [
            'quill_page' => [
                $this->id(),
                Column::VARCHAR('name', '255'),
                Column::TEXT('description')->nullable(),

                Column::ENUM('status', ['draft', 'published'])
                    ->default('draft'),

                Column::ENUM('source_type', ['quill', 'urpc'])
                    ->default('quill'),

                Column::VARCHAR('source_key', '255')
                    ->nullable()
                    ->unique(),

                Column::JSON('page_json'),

                $this->adminId(),
                $this->createdAt(),
                $this->updatedAt(),
                $this->lockVersion(),
                $this->deletedAt(),
            ],

            'quill_page_template' => [
                $this->id(), Column::VARCHAR('name', '255'), Column::TEXT('description')->nullable(),
                Column::JSON('template_json'), $this->adminId(), $this->createdAt(),
                $this->updatedAt(), $this->lockVersion(), $this->deletedAt(),
            ],

            'quill_block' => [
                $this->id(),

                Column::VARCHAR('urpc_uid', '255')
                    ->nullable()
                    ->index(),

                Column::JSON('block_json'),

                $this->adminId(),
                $this->createdAt(),
                $this->updatedAt(),
                $this->lockVersion(),
                $this->deletedAt(),
            ],

            'quill_component' => [
                $this->id(),
                Column::VARCHAR('name', '255'),
                Column::TEXT('description')->nullable(),
                Column::JSON('component_json'),

                $this->adminId(),
                $this->createdAt(),
                $this->updatedAt(),
                $this->lockVersion(),
                $this->deletedAt(),
            ],

            'quill_color_palette' => [
                $this->id(), Column::VARCHAR('name', '255'), Column::JSON('palette_json'),
                $this->adminId(), $this->createdAt(), $this->updatedAt(),
                $this->lockVersion(), $this->deletedAt(),
            ],

            'quill_animation' => [
                $this->id(), Column::VARCHAR('name', '255'), Column::JSON('animation_json'),
                $this->adminId(), $this->createdAt(), $this->updatedAt(),
                $this->lockVersion(), $this->deletedAt(),
            ],

            'quill_dynamic_route' => [
                $this->id(),

                Column::VARCHAR('path', '512')->unique(),

                $this->reference(
                    'page_id',
                    'quill_page',
                    nullable: true
                ),

                $this->adminId(),
                $this->createdAt(),
                $this->updatedAt(),
            ],

            'quill_page_snapshot' => [
                $this->id(),

                $this->reference('page_id', 'quill_page'),

                Column::INT('revision')
                    ->unsigned(),

                Column::VARCHAR('name', '255'),
                Column::TEXT('description')->nullable(),

                Column::ENUM('source_type', ['quill', 'urpc'])
                    ->default('quill'),

                Column::VARCHAR('source_key', '255')->nullable(),

                Column::JSON('page_json'),

                $this->adminId(),
                $this->createdAt(),
            ],

            // Editor history is scoped to its owner; published snapshot manifests stay immutable.
            'quill_block_revision' => [
                $this->id(),
                $this->reference('page_id', 'quill_page', nullable: true),
                $this->reference('component_id', 'quill_component', nullable: true),
                Column::VARCHAR('block_uid', '255')->index(),
                Column::INT('revision')->unsigned(),
                Column::JSON('block_json'),
                $this->adminId(), $this->createdAt(),
            ],

            'quill_block_snapshot' => [
                $this->id(),

                $this->reference('block_id', 'quill_block'),

                Column::INT('revision')
                    ->unsigned(),

                Column::VARCHAR('urpc_uid', '255')->nullable(),
                Column::JSON('block_json'),

                $this->adminId(),
                $this->createdAt(),
            ],

            'quill_component_snapshot' => [
                $this->id(),

                $this->reference(
                    'component_id',
                    'quill_component'
                ),

                Column::INT('revision')
                    ->unsigned(),

                Column::VARCHAR('name', '255'),
                Column::TEXT('description')->nullable(),
                Column::JSON('component_json'),

                $this->adminId(),
                $this->createdAt(),
            ],

            'quill_page_snapshot_block' => [
                $this->id(),

                $this->reference(
                    'page_snapshot_id',
                    'quill_page_snapshot'
                ),

                $this->reference(
                    'block_id',
                    'quill_block'
                ),

                $this->reference(
                    'block_snapshot_id',
                    'quill_block_snapshot'
                ),
            ],

            'quill_page_snapshot_component' => [
                $this->id(),

                $this->reference(
                    'page_snapshot_id',
                    'quill_page_snapshot'
                ),

                $this->reference(
                    'component_id',
                    'quill_component'
                ),

                $this->reference(
                    'component_snapshot_id',
                    'quill_component_snapshot'
                ),
            ],

            'quill_component_snapshot_block' => [
                $this->id(),

                $this->reference(
                    'component_snapshot_id',
                    'quill_component_snapshot'
                ),

                $this->reference(
                    'block_id',
                    'quill_block'
                ),

                $this->reference(
                    'block_snapshot_id',
                    'quill_block_snapshot'
                ),
            ],

            'quill_component_snapshot_component' => [
                $this->id(),

                $this->reference(
                    'owner_snapshot_id',
                    'quill_component_snapshot'
                ),

                $this->reference(
                    'component_id',
                    'quill_component'
                ),

                $this->reference(
                    'referenced_snapshot_id',
                    'quill_component_snapshot'
                ),
            ],
        ];
    }

    public function run(int $creatorId = 0): void
    {
        $tw = TableWizard::create($creatorId);

        foreach ($this->tables as $table => $columns) {
            if (!$tw->tableExists($table)) {
                foreach ($columns as $column) {
                    $tw->addColumn($column);
                }

                if (!$tw->createTable($table)) {
                    throw new RuntimeException(
                        "Failed to create table: {$table}"
                    );
                }

                continue;
            }

            foreach ($columns as $column) {
                $this->ensureColumn($tw, $table, $column);
            }
        }

        /*
         * Dodaju se nakon snapshot tabela jer postoji kružna veza:
         *
         * page -> published snapshot -> page
         * component -> published snapshot -> component
         */
        $this->ensureColumn(
            $tw,
            'quill_page',
            $this->reference(
                'published_snapshot_id',
                'quill_page_snapshot',
                nullable: true
            )
        );

        $this->ensureColumn(
            $tw,
            'quill_component',
            $this->reference(
                'published_snapshot_id',
                'quill_component_snapshot',
                nullable: true
            )
        );
    }

    private function ensureColumn(
        TableWizard $tw,
        string $table,
        Column $column
    ): void {
        $name = $column->getName();

        if ($tw->columnExists($table, $name)) {
            return;
        }

        if (!$tw->addColumnToTable($table, $column)) {
            throw new RuntimeException(
                "Failed to add column: {$table}.{$name}"
            );
        }
    }

    private function id(): Column
    {
        return Column::BIGINT('id')
            ->unsigned()
            ->primary()
            ->autoIncrement();
    }

    private function reference(
        string $name,
        string $table,
        bool $nullable = false
    ): Column {
        $column = Column::BIGINT($name)
            ->unsigned()
            ->index()
            ->foreignKey(
                $table,
                'id',
                onDelete: 'RESTRICT',
                onUpdate: 'RESTRICT'
            );

        if ($nullable) {
            $column->nullable();
        }

        return $column;
    }

    private function adminId(): Column
    {
        /*
         * Bez FK: ne pretpostavljamo naziv i tip ID kolone
         * postojećeg admin sistema.
         */
        return Column::BIGINT('admin_id')
            ->unsigned()
            ->index();
    }

    private function createdAt(): Column
    {
        return Column::DATETIME('created_at')
            ->default('CURRENT_TIMESTAMP');
    }

    private function updatedAt(): Column
    {
        // Aplikacija ažurira ovo polje pri izmeni.
        return Column::DATETIME('updated_at')
            ->default('CURRENT_TIMESTAMP');
    }

    private function lockVersion(): Column
    {
        return Column::INT('lock_version')
            ->unsigned()
            ->default('1');
    }

    private function deletedAt(): Column
    {
        return Column::DATETIME('deleted_at')
            ->nullable();
    }
}