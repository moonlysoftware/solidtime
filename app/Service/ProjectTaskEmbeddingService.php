<?php

declare(strict_types=1);

namespace App\Service;

use App\Models\Member;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ProjectTaskEmbeddingService
{
    public function __construct(private PermissionStore $permissions) {}

    public function configured(): bool
    {
        return (bool) config('services.gateway.url') && (bool) config('services.gateway.key');
    }

    public function model(): string
    {
        return config('services.gateway.embedding_model');
    }

    /** @return list<float> */
    public function embed(string $text): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('Embedding gateway is not configured');
        }
        // Qwen supports Matryoshka embeddings: truncate and normalize consistently
        // rather than requiring gateway-specific support for a dimensions parameter.
        $response = Http::withToken(config('services.gateway.key'))
            ->acceptJson()->connectTimeout(2)->timeout(5)
            ->post(rtrim(config('services.gateway.url'), '/').'/embeddings', [
                'model' => $this->model(),
                'input' => $text,
            ]);
        // Do not throw the HTTP response: it can include sensitive request text.
        if (! $response->successful()) {
            throw new RuntimeException('Embedding gateway returned HTTP '.$response->status());
        }
        $values = $response->json('data.0.embedding');
        if (! is_array($values) || count($values) < 1024) {
            throw new RuntimeException('Embedding gateway returned an invalid vector');
        }
        $vector = [];
        foreach (array_slice($values, 0, 1024) as $value) {
            if ((! is_float($value) && ! is_int($value)) || ! is_finite((float) $value)) {
                throw new RuntimeException('Embedding gateway returned an invalid vector');
            }
            $vector[] = (float) $value;
        }
        $norm = sqrt(array_sum(array_map(static fn (float $value): float => $value * $value, $vector)));
        if ($norm <= 0 || ! is_finite($norm)) {
            throw new RuntimeException('Embedding gateway returned a zero or invalid vector');
        }

        return array_map(static fn (float $value): float => $value / $norm, $vector);
    }

    /** @return list<array{project_id: string, project_name: string, task_id: string|null, task_name: string|null}> */
    public function suggest(Organization $organization, User $user, string $description, ?string $excludeTimeEntryId = null): array
    {
        $projects = Project::query()->whereBelongsTo($organization, 'organization')->whereNull('archived_at');
        if (! $this->permissions->userHas($organization, $user, 'projects:view:all')) {
            $projects->visibleByEmployee($user);
        }
        $canViewTasks = $this->permissions->userHas($organization, $user, 'tasks:view');
        $tasks = Task::query()->whereBelongsTo($organization, 'organization')
            ->whereIn('tasks.project_id', (clone $projects)->select('projects.id'))->whereNull('done_at');
        if (! $canViewTasks) {
            $tasks->whereRaw('false');
        } elseif (! $this->permissions->userHas($organization, $user, 'tasks:view:all')) {
            $tasks->visibleByEmployee($user);
        }
        $vector = $this->literal($this->embed($description));
        $matches = DB::transaction(function () use ($organization, $projects, $tasks, $user, $vector, $canViewTasks, $excludeTimeEntryId): Collection {
            // Continue vector scans past candidates excluded by organization or permissions.
            DB::statement("SET LOCAL hnsw.iterative_scan = 'strict_order'");
            $projectMatches = (clone $projects)
                ->join('project_task_embeddings as embeddings', 'embeddings.project_id', '=', 'projects.id')
                ->whereNull('embeddings.task_id')->whereRaw('embeddings.embedding_model = ?', [$this->model()])
                ->selectRaw('projects.id AS project_id, projects.name AS project_name, NULL::uuid AS task_id, NULL::text AS task_name, embeddings.embedding <=> ?::vector AS distance', [$vector])
                ->orderByRaw('embeddings.embedding <=> ?::vector', [$vector])->limit(5)->toBase()->get();
            $matches = $projectMatches;
            if ($canViewTasks) {
                $taskMatches = (clone $tasks)
                    ->join('project_task_embeddings as embeddings', 'embeddings.task_id', '=', 'tasks.id')
                    ->whereRaw('embeddings.embedding_model = ?', [$this->model()])
                    ->selectRaw('tasks.project_id, (SELECT name FROM projects WHERE projects.id = tasks.project_id) AS project_name, tasks.id AS task_id, tasks.name AS task_name, embeddings.embedding <=> ?::vector AS distance', [$vector])
                    ->orderByRaw('embeddings.embedding <=> ?::vector', [$vector])->limit(5)->toBase()->get();
                $matches = $matches->concat($taskMatches);
            }

            $viewAllEntries = $this->permissions->userHas($organization, $user, 'time-entries:view:all');
            if ($viewAllEntries || $this->permissions->userHas($organization, $user, 'time-entries:view:own')) {
                $entries = TimeEntry::query()->whereBelongsTo($organization, 'organization')->workTime()
                    ->whereIn('time_entries.project_id', (clone $projects)->select('projects.id'))
                    ->whereNotNull('time_entries.description')->whereRaw("btrim(time_entries.description) <> ''");
                if (! $viewAllEntries) {
                    $entries->whereIn('time_entries.member_id', Member::query()
                        ->whereBelongsTo($organization, 'organization')->whereBelongsTo($user, 'user')->select('id'));
                }
                if ($excludeTimeEntryId !== null) {
                    $entries->where('time_entries.id', '!=', $excludeTimeEntryId);
                }
                $examples = $entries
                    ->join('time_entry_embeddings as examples', 'examples.time_entry_id', '=', 'time_entries.id')
                    ->join('projects', 'projects.id', '=', 'time_entries.project_id')
                    ->leftJoinSub((clone $tasks)->select('id', 'name', 'project_id'), 'eligible_tasks', static function (JoinClause $join): void {
                        $join->on('eligible_tasks.id', '=', 'time_entries.task_id')
                            ->on('eligible_tasks.project_id', '=', 'time_entries.project_id');
                    })
                    ->whereRaw('examples.embedding_model = ?', [$this->model()])
                    ->selectRaw('time_entries.project_id, projects.name AS project_name, eligible_tasks.id AS task_id, eligible_tasks.name AS task_name, examples.embedding <=> ?::vector AS distance', [$vector])
                    ->orderByRaw('examples.embedding <=> ?::vector', [$vector])->limit(50)->toBase()->get();
                $matches = $matches->concat($examples);
            }

            return $matches;
        });

        return array_values($matches->sortBy('distance')->unique(static fn (object $match): string => $match->project_id.':'.($match->task_id ?? ''))->take(5)->map(static fn (object $match): array => [
            'project_id' => $match->project_id,
            'project_name' => $match->project_name,
            'task_id' => $match->task_id,
            'task_name' => $match->task_name,
        ])->all());
    }

    public function documentText(Project $project, ?Task $task = null, ?string $work = null): string
    {
        return implode("\n", array_filter([
            $project->client !== null ? 'Client: '.$project->client->name : null,
            'Project: '.$project->name,
            $task !== null ? 'Task: '.$task->name : null,
            $work !== null ? 'Work: '.$work : null,
        ]));
    }

    /** @param list<float> $vector */
    public function literal(array $vector): string
    {
        return '['.implode(',', $vector).']';
    }
}
