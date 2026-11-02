<?php

declare(strict_types=1);

namespace Tests\Unit\Endpoint\Api\V1;

use App\Jobs\IndexProjectTaskEmbeddings;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Service\ProjectTaskEmbeddingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\Passport;

class ProjectTaskSuggestionEndpointTest extends ApiEndpointTestAbstract
{
    private function configureGateway(int $status = 200): void
    {
        config(['services.gateway.key' => 'test', 'services.gateway.url' => 'https://gateway.test/v1']);
        Http::fake(['gateway.test/*' => Http::response(['data' => [['embedding' => array_merge([1], array_fill(0, 1023, 0))]]], $status)]);
    }

    private function index(Project|Task $model, float $similarity = 1): void
    {
        $vector = array_merge([$similarity, sqrt(1 - $similarity ** 2)], array_fill(0, 1022, 0));
        DB::table('project_task_embeddings')->updateOrInsert([
            'project_id' => $model instanceof Project ? $model->id : $model->project_id,
            'task_id' => $model instanceof Task ? $model->id : null,
        ], [
            'embedding' => app(ProjectTaskEmbeddingService::class)->literal($vector),
            'embedding_model' => 'qwen3-embedding-8b',
            'input_hash' => str_repeat('a', 64),
        ]);
    }

    public function test_matches_are_ranked_limited_and_scoped_to_visible_active_records(): void
    {
        $data = $this->createUserWithPermission(['projects:view', 'tasks:view']);
        Passport::actingAs($data->user);
        $project = Project::factory()->forOrganization($data->organization)->isPublic()->create();
        $this->index($project, 0.2);
        $tasks = Task::factory()->forOrganization($project->organization)->forProject($project)->createMany(6);
        foreach ($tasks as $i => $task) {
            $this->index($task, 0.9 - $i * 0.1);
        }
        $private = Project::factory()->forOrganization($data->organization)->isPrivate()->create();
        $archived = Project::factory()->forOrganization($data->organization)->isPublic()->archived()->create();
        $foreign = Project::factory()->isPublic()->create();
        foreach ([$private, $archived, $foreign] as $excluded) {
            $this->index($excluded);
            $this->index(Task::factory()->forOrganization($excluded->organization)->forProject($excluded)->create());
        }
        $done = Task::factory()->forOrganization($project->organization)->forProject($project)->create(['done_at' => now()]);
        $this->index($done);
        $this->configureGateway();

        $response = $this->postJson(route('api.v1.project-task-suggestions', [$data->organization]), ['description' => 'Fix login']);

        $response->assertOk()->assertJsonCount(5, 'data');
        $this->assertSame($tasks->take(5)->pluck('id')->all(), array_column($response->json('data'), 'task_id'));
        $this->assertSame([$project->id], array_values(array_unique(array_column($response->json('data'), 'project_id'))));
    }

    public function test_endpoint_rejects_users_without_permission_and_other_organizations(): void
    {
        $data = $this->createUserWithPermission();
        Passport::actingAs($data->user);
        $foreign = Project::factory()->create();
        foreach ([$data->organization->id, $foreign->organization_id] as $organization) {
            $this->postJson(route('api.v1.project-task-suggestions', [$organization]), ['description' => 'Fix login'])->assertForbidden();
        }
        Http::assertNothingSent();
    }

    public function test_gateway_failure_returns_no_suggestions(): void
    {
        $data = $this->createUserWithPermission(['projects:view']);
        Passport::actingAs($data->user);
        $this->configureGateway(503);

        $this->postJson(route('api.v1.project-task-suggestions', [$data->organization]), ['description' => 'Fix login'])
            ->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_indexing_uses_names_and_refreshes_tasks_after_project_changes(): void
    {
        $client = Client::factory()->create(['name' => 'Moonly']);
        $project = Project::factory()->forOrganization($client->organization)->forClient($client)->create([
            'name' => 'Website',
        ]);
        $task = Task::factory()->forOrganization($project->organization)->forProject($project)->create(['name' => 'Login']);
        $this->configureGateway();
        Queue::fake();

        (new IndexProjectTaskEmbeddings('project', $project->id))->handle(app(ProjectTaskEmbeddingService::class));
        (new IndexProjectTaskEmbeddings('task', $task->id))->handle(app(ProjectTaskEmbeddingService::class));

        $this->assertDatabaseHas('project_task_embeddings', ['project_id' => $project->id, 'task_id' => null]);
        Http::assertSent(fn ($request): bool => str_contains($request['input'], 'Moonly')
            && str_contains($request['input'], 'Website') && str_contains($request['input'], 'Login'));
        Queue::assertPushed(IndexProjectTaskEmbeddings::class, fn ($job): bool => $job->type === 'task' && $job->id === $task->id);
        $project->update(['name' => 'Updated portal']);
        (new IndexProjectTaskEmbeddings('client', $client->id))->handle(app(ProjectTaskEmbeddingService::class));
        Queue::assertPushed(IndexProjectTaskEmbeddings::class, fn ($job): bool => $job->type === 'project' && $job->id === $project->id);
        $client->update(['name' => 'Moonly Software']);
        (new IndexProjectTaskEmbeddings('project', $project->id))->handle(app(ProjectTaskEmbeddingService::class));
        Http::assertSent(fn ($request): bool => str_contains($request['input'], 'Moonly Software'));
    }

    public function test_unchanged_names_are_not_reembedded_but_name_and_model_changes_are(): void
    {
        $project = Project::factory()->create(['name' => 'Portal']);
        $task = Task::factory()->forOrganization($project->organization)->forProject($project)->create(['name' => 'Login']);
        $this->configureGateway();
        Queue::fake();
        $service = app(ProjectTaskEmbeddingService::class);
        foreach (['project' => $project->id, 'task' => $task->id] as $type => $id) {
            (new IndexProjectTaskEmbeddings($type, $id))->handle($service);
            (new IndexProjectTaskEmbeddings($type, $id))->handle($service);
        }
        Http::assertSentCount(2);
        $task->update(['spent_time' => 120]);
        (new IndexProjectTaskEmbeddings('task', $task->id))->handle($service);
        Http::assertSentCount(2);
        $task->update(['name' => 'OAuth login']);
        (new IndexProjectTaskEmbeddings('task', $task->id))->handle($service);
        Http::assertSentCount(3);
        $project->update(['name' => 'New portal']);
        (new IndexProjectTaskEmbeddings('task', $task->id))->handle($service);
        Http::assertSentCount(4);
        config(['services.gateway.embedding_model' => 'replacement-model']);
        (new IndexProjectTaskEmbeddings('task', $task->id))->handle($service);
        Http::assertSentCount(5);
    }

    public function test_recent_scan_only_queues_recent_projects_tasks_and_clients(): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 12:00:00 UTC'));
        $old = now()->subHours(2);
        $oldProject = Project::factory()->create(['updated_at' => $old]);
        $recentProject = Project::factory()->create(['updated_at' => now()->subMinutes(5)]);
        $oldTask = Task::factory()->forOrganization($oldProject->organization)->forProject($oldProject)->create(['updated_at' => $old]);
        $recentTask = Task::factory()->forOrganization($oldProject->organization)->forProject($oldProject)->create(['updated_at' => now()->subHour()]);
        $recentClient = Client::factory()->create(['updated_at' => now()->subMinutes(10)]);
        $oldClient = Client::factory()->create(['updated_at' => $old]);
        $this->configureGateway();
        Queue::fake();
        $this->artisan('embeddings:index', ['--recent' => true])->assertSuccessful();
        foreach (['project' => $recentProject->id, 'task' => $recentTask->id, 'client' => $recentClient->id] as $type => $id) {
            Queue::assertPushed(IndexProjectTaskEmbeddings::class, fn ($job): bool => $job->type === $type && $job->id === $id);
        }
        foreach ([$oldProject->id, $oldTask->id, $oldClient->id] as $id) {
            Queue::assertNotPushed(IndexProjectTaskEmbeddings::class, fn ($job): bool => $job->id === $id);
        }
        Queue::assertPushed(IndexProjectTaskEmbeddings::class, 3);
    }

    public function test_backfill_verifies_gateway_before_queueing(): void
    {
        Queue::fake();
        $this->configureGateway(403);
        $this->artisan('embeddings:index')->assertFailed();
        Queue::assertNothingPushed();
    }
}
