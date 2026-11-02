<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
        Schema::create('project_task_embeddings', function (Blueprint $table): void {
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('task_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $table->string('embedding_model');
            $table->char('input_hash', 64);
        });
        DB::statement('CREATE UNIQUE INDEX project_task_embeddings_project_unique ON project_task_embeddings (project_id) WHERE task_id IS NULL');
        DB::statement('ALTER TABLE project_task_embeddings ADD COLUMN embedding vector(1024) NOT NULL');
        DB::statement('CREATE INDEX project_task_embeddings_vector_index ON project_task_embeddings USING hnsw (embedding vector_cosine_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('project_task_embeddings');
    }
};
