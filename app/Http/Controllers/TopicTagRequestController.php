<?php

namespace App\Http\Controllers;

use App\Models\TopicTag;
use App\Models\TopicTagRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Teacher Topic Tag request submission and history (UC-1.9, Task #47).
 */
class TopicTagRequestController extends Controller
{
    /**
     * List topic tag requests submitted by the current teacher.
     */
    public function index(Request $request): Response|JsonResponse
    {
        $requests = TopicTagRequest::where('requested_by_id', $request->user()->id)
            ->with('resolvedTopicTag')
            ->latest()
            ->get();

        if ($request->wantsJson()) {
            return response()->json($requests);
        }

        return Inertia::render('TopicTagRequests/Index', [
            'requests' => $requests,
        ]);
    }

    /**
     * Submit a new topic tag request (UC-1.9).
     */
    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'proposed_name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', Rule::in(['academic', 'scientific', 'sports', 'community'])],
            'justification' => ['required', 'string', 'max:2000'],
        ], [
            'proposed_name.required' => __('tag_requests.flash.please_complete_all_fields_before'),
            'category.required' => __('tag_requests.flash.please_complete_all_fields_before'),
            'category.in' => __('tag_requests.flash.please_select_a_valid_category'),
            'justification.required' => __('tag_requests.flash.please_complete_all_fields_before'),
        ]);

        $proposedName = trim($validated['proposed_name']);

        // UC-1.9 Exception: Duplicate Proposed Name with existing live Topic Tag
        if (TopicTag::where('name', $proposedName)->exists()) {
            throw ValidationException::withMessages([
                'proposed_name' => __('tag_requests.flash.a_topic_tag'),
            ]);
        }

        // Check if there is already a pending request with the same proposed name
        if (TopicTagRequest::where('proposed_name', $proposedName)->where('status', 'pending')->exists()) {
            throw ValidationException::withMessages([
                'proposed_name' => __('tag_requests.flash.a_request_for_this_topic'),
            ]);
        }

        $tagRequest = TopicTagRequest::create([
            'proposed_name' => $proposedName,
            'category' => $validated['category'],
            'justification' => trim($validated['justification']),
            'status' => 'pending',
            'requested_by_id' => $request->user()->id,
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'message' => __('tag_requests.flash.topic_tag_request_submitted_successfully'),
                'request' => $tagRequest,
            ], 201);
        }

        return redirect()->back()->with('success', __('tag_requests.flash.topic_tag_request_submitted_successfully'));
    }
}
