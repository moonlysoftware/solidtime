<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\TimeEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class QueueProjectTimeEntryEmbeddings implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function __construct(public string $projectId, public ?string $afterId = null) {}

    public function uniqueId(): string
    {
        return $this->projectId.':'.$this->afterId;
    }

    public function handle(): void
    {
        $query = TimeEntry::query()->where('project_id', $this->projectId);
        if ($this->afterId !== null) {
            $query->where('id', '>', $this->afterId);
        }
        $ids = $query->orderBy('id')->limit(250)->pluck('id');
        foreach ($ids as $id) {
            IndexTimeEntryEmbedding::dispatch($id)->afterCommit();
        }
        if ($ids->count() === 250) {
            self::dispatch($this->projectId, $ids->last())->afterCommit();
        }
    }
}
