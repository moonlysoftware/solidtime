<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Jobs\IndexTimeEntryEmbedding;
use App\Jobs\QueueProjectTimeEntryEmbeddings;
use App\Models\Member;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Service\ProjectTaskEmbeddingService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCaseWithDatabase;

class TimeEntryEmbeddingTest extends TestCaseWithDatabase
{
    public function test_examples_reuse_identical_text_skip_unchanged_text_and_remove_cleared_descriptions(): void
    {
        Queue::fake();
        $project = Project::factory()->create();
        $first = TimeEntry::factory()->forProject($project)->create(['description' => 'Fix supplier import']);
        $second = TimeEntry::factory()->forProject($project)->create(['description' => 'Fix supplier import']);
        config(['services.gateway.key' => 'test', 'services.gateway.url' => 'https://gateway.test/v1']);
        Http::fake(['gateway.test/*' => Http::response(['data' => [['embedding' => array_merge([1], array_fill(0, 1023, 0))]]])]);
        $service = app(ProjectTaskEmbeddingService::class);
        foreach ([$first, $second, $first] as $entry) {
            (new IndexTimeEntryEmbedding($entry->id))->handle($service);
        }
        Http::assertSentCount(1);
        $this->assertDatabaseCount('time_entry_embeddings', 2);
        $first->update(['description' => 'Fix the new supplier API']);
        (new IndexTimeEntryEmbedding($first->id))->handle($service);
        Http::assertSentCount(2);
        $first->update(['description' => '']);
        (new IndexTimeEntryEmbedding($first->id))->handle($service);
        $this->assertDatabaseMissing('time_entry_embeddings', ['time_entry_id' => $first->id]);
        $second->update(['project_id' => null]);
        (new IndexTimeEntryEmbedding($second->id))->handle($service);
        $this->assertDatabaseCount('time_entry_embeddings', 0);
        Http::assertSentCount(2);
    }

    public function test_project_history_refresh_is_paged_for_large_projects(): void
    {
        Queue::fake();
        $project = Project::factory()->create();
        $member = Member::factory()->forOrganization($project->organization)->create();
        $entries = TimeEntry::factory()->forMember($member)->forProject($project)->createMany(251);
        (new QueueProjectTimeEntryEmbeddings($project->id))->handle();
        Queue::assertPushed(IndexTimeEntryEmbedding::class, 250);
        Queue::assertPushed(QueueProjectTimeEntryEmbeddings::class, 1);
        $continuation = Queue::pushed(QueueProjectTimeEntryEmbeddings::class)->first();
        $continuation->handle();
        Queue::assertPushed(IndexTimeEntryEmbedding::class, 251);
        $this->assertEqualsCanonicalizing($entries->pluck('id')->all(), Queue::pushed(IndexTimeEntryEmbedding::class)->pluck('id')->all());
    }
}
