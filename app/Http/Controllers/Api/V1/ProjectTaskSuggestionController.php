<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\Organization;
use App\Service\ProjectTaskEmbeddingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class ProjectTaskSuggestionController extends Controller
{
    public function __invoke(Organization $organization, Request $request, ProjectTaskEmbeddingService $embeddings): JsonResponse
    {
        $this->checkPermission($organization, 'projects:view');
        $data = $request->validate([
            'description' => ['required', 'string', 'max:5000'],
            'exclude_time_entry_id' => ['nullable', 'uuid'],
        ]);
        if (! $embeddings->configured() || trim($data['description']) === '') {
            return response()->json(['data' => []]);
        }
        try {
            return response()->json(['data' => $embeddings->suggest($organization, $this->user(), $data['description'], $data['exclude_time_entry_id'] ?? null)]);
        } catch (Throwable) {
            // Suggestions are optional; a gateway outage must not prevent tracking time.
            return response()->json(['data' => []]);
        }
    }
}
