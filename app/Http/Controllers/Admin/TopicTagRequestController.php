<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TopicTagRequest;
use App\Services\TopicTagRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Controller handling Topic Tag Request actions (Technical Specification Table 22).
 */
class TopicTagRequestController extends Controller
{
    public function __construct(
        protected TopicTagRequestService $service
    ) {}

    /**
     * Approve a topic tag request (POST /admin/topic-tag-requests/{id}/approve).
     */
    public function approve(Request $request, int $id): JsonResponse|RedirectResponse
    {
        $tagRequest = TopicTagRequest::findOrFail($id);

        $tag = $this->service->approve($tagRequest, $request->user());

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Topic tag request approved successfully.',
                'topic_tag' => $tag,
            ]);
        }

        return back()->with('status', 'Topic tag request approved.');
    }

    /**
     * Reject a topic tag request (POST /admin/topic-tag-requests/{id}/reject).
     */
    public function reject(Request $request, int $id): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $tagRequest = TopicTagRequest::findOrFail($id);

        $this->service->reject($tagRequest, $request->user(), $validated['reason'] ?? null);

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Topic tag request rejected.',
            ]);
        }

        return back()->with('status', 'Topic tag request rejected.');
    }
}
