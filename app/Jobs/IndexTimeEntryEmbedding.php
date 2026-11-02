<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\TimeEntryType;
use App\Models\TimeEntry;
use App\Service\ProjectTaskEmbeddingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;

class IndexTimeEntryEmbedding implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public string $id) {}

    public function uniqueId(): string
    {
        return $this->id;
    }

    public function handle(ProjectTaskEmbeddingService $embeddings): void
    {
        if (! $embeddings->configured()) {
            return;
        }
        $entry = TimeEntry::with('project.client')->find($this->id);
        if ($entry === null) {
            return;
        }
        $text = trim($entry->description ?? '');
        $existing = DB::table('time_entry_embeddings')->where('time_entry_id', $entry->id);
        if ($entry->project_id === null || $entry->type !== TimeEntryType::Work || $text === '') {
            $existing->delete();

            return;
        }
        $text = $embeddings->documentText($entry->project, work: $text);
        $hash = hash('sha256', $text);
        $sameText = DB::table('time_entry_embeddings')->where('embedding_model', $embeddings->model())->where('input_hash', $hash);
        if ((clone $sameText)->where('time_entry_id', $entry->id)->exists()) {
            return;
        }
        // Repeated descriptions can share a vector without another gateway request.
        $vector = $sameText->value('embedding') ?? $embeddings->literal($embeddings->embed($text));
        DB::table('time_entry_embeddings')->updateOrInsert(['time_entry_id' => $entry->id], [
            'embedding' => $vector,
            'embedding_model' => $embeddings->model(),
            'input_hash' => $hash,
        ]);
    }
}
