<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\IndexProjectTaskEmbeddings;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Service\ProjectTaskEmbeddingService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class IndexProjectTaskEmbeddingsCommand extends Command
{
    protected $signature = 'embeddings:index {--recent : Only scan records updated in the last hour}';

    protected $description = 'Verify the embedding gateway, then queue changed project/task embeddings';

    public function handle(ProjectTaskEmbeddingService $embeddings): int
    {
        $since = $this->option('recent') ? now()->subHour() : null;
        try {
            $embeddings->embed('Project and task search');
        } catch (Throwable) {
            $this->error('Embedding gateway verification failed. Check GATEWAY_URL, GATEWAY_API_KEY and GATEWAY_EMBEDDING_MODEL.');

            return self::FAILURE;
        }
        $sources = ['project' => Project::class, 'task' => Task::class];
        if ($since !== null) {
            $sources['client'] = Client::class;
        }
        foreach ($sources as $type => $class) {
            $query = $class::query();
            if ($since !== null) {
                $query->where('updated_at', '>=', $since);
            }
            $query->select('id')->eachById(function (Model $model) use ($type): void {
                IndexProjectTaskEmbeddings::dispatch($type, $model->getKey())->afterCommit();
            });
        }
        $this->info('Project and task indexing queued. Run your queue worker to process it.');

        return self::SUCCESS;
    }
}
