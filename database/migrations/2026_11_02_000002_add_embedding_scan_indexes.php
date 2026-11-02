<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        foreach (['projects', 'tasks', 'clients'] as $table) {
            DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS {$table}_embedding_scan_index ON {$table} (updated_at)");
        }
    }

    public function down(): void
    {
        foreach (['projects', 'tasks', 'clients'] as $table) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$table}_embedding_scan_index");
        }
    }
};
