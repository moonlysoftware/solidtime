<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Service\ProjectTaskEmbeddingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;

class IndexProjectTaskEmbeddings implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public string $type, public string $id) {}

    public function uniqueId(): string
    {
        return $this->type.':'.$this->id;
    }

    public function handle(ProjectTaskEmbeddingService $embeddings): void
    {
        if (! $embeddings->configured()) {
            return;
        }
        if ($this->type === 'client') {
            Client::find($this->id)?->projects()->eachById(function (Project $project): void {
                self::dispatch('project', $project->id)->afterCommit();
            });

            return;
        }
        $model = $this->type === 'project'
            ? Project::with('client')->find($this->id)
            : Task::with('project.client')->find($this->id);
        if ($model === null) {
            return;
        }
        $project = $model instanceof Project ? $model : $model->project;
        $text = $embeddings->documentText($project, $model instanceof Task ? $model : null);
        $key = $model instanceof Task ? ['task_id' => $model->id] : ['project_id' => $project->id, 'task_id' => null];
        $hash = hash('sha256', $text);
        if (DB::table('project_task_embeddings')->where($key)
            ->where('embedding_model', $embeddings->model())->where('input_hash', $hash)->exists()) {
            return;
        }
        $vector = $embeddings->embed($text);
        DB::table('project_task_embeddings')->updateOrInsert(
            $key,
            [
                'project_id' => $project->id,
                'embedding' => $embeddings->literal($vector),
                'embedding_model' => $embeddings->model(),
                'input_hash' => $hash,
            ]);
        if ($model instanceof Project) {
            $model->tasks()->eachById(function (Task $task): void {
                self::dispatch('task', $task->id)->afterCommit();
            });
            QueueProjectTimeEntryEmbeddings::dispatch($model->id)->afterCommit();
        }
    }
}
