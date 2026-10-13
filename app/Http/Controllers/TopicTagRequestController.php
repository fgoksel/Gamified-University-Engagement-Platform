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
            'proposed_name.required' => 'Please complete all fields before submitting.',
            'category.required' => 'Please complete all fields before submitting.',
            'category.in' => 'Please select a valid category (academic, scientific, sports, or community).',
            'justification.required' => 'Please complete all fields before submitting.',
        ]);

        $proposedName = trim($validated['proposed_name']);

        // UC-1.9 Exception: Duplicate Proposed Name with existing live Topic Tag
        if (TopicTag::where('name', $proposedName)->exists()) {
            throw ValidationException::withMessages([
                'proposed_name' => 'A topic tag with this name already exists. Please select it from the dropdown instead.',
            ]);
        }

        // Check if there is already a pending request with the same proposed name
        if (TopicTagRequest::where('proposed_name', $proposedName)->where('status', 'pending')->exists()) {
            throw ValidationException::withMessages([
                'proposed_name' => 'A request for this topic tag is already pending approval.',
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
                'message' => 'Topic tag request submitted successfully. It will be reviewed by an administrator.',
                'request' => $tagRequest,
            ], 201);
        }

        return redirect()->back()->with('success', 'Topic tag request submitted successfully. It will be reviewed by an administrator.');
    }
}
