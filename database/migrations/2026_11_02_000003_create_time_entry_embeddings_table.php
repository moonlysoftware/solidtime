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
        Schema::create('time_entry_embeddings', function (Blueprint $table): void {
            $table->foreignUuid('time_entry_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('embedding_model');
            $table->char('input_hash', 64);
            $table->index(['embedding_model', 'input_hash']);
        });
        DB::statement('ALTER TABLE time_entry_embeddings ADD COLUMN embedding vector(1024) NOT NULL');
        DB::statement('CREATE INDEX time_entry_embeddings_vector_index ON time_entry_embeddings USING hnsw (embedding vector_cosine_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('time_entry_embeddings');
    }
};
