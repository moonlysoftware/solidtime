<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Service\ProjectTaskEmbeddingService;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class ProjectTaskEmbeddingServiceTest extends TestCase
{
    public function test_gateway_vectors_are_truncated_and_normalized(): void
    {
        config(['services.gateway.key' => 'test', 'services.gateway.url' => 'https://gateway.test/v1']);
        Http::fake(['gateway.test/*' => Http::response(['data' => [['embedding' => array_fill(0, 4096, 1)]]])]);

        $vector = app(ProjectTaskEmbeddingService::class)->embed('Fix the login page');

        $this->assertCount(1024, $vector);
        $this->assertSame(1 / 32, $vector[0]);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://gateway.test/v1/embeddings'
            && $request['model'] === 'qwen3-embedding-8b'
            && $request['input'] === 'Fix the login page');
    }

    public function test_invalid_vectors_are_rejected(): void
    {
        config(['services.gateway.key' => 'test']);
        Http::fake(['*' => Http::response(['data' => [['embedding' => array_fill(0, 1024, 0)]]])]);

        $this->expectException(RuntimeException::class);
        app(ProjectTaskEmbeddingService::class)->embed('Fix login');
    }

    public function test_gateway_error_body_is_not_included_in_the_exception(): void
    {
        config(['services.gateway.key' => 'test']);
        Http::fake(['*' => Http::response(['error' => 'private entry text'], 503)]);

        $this->expectExceptionMessage('Embedding gateway returned HTTP 503');
        app(ProjectTaskEmbeddingService::class)->embed('Fix login');
    }
}
