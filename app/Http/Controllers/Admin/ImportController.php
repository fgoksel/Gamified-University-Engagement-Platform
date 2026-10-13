<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ImportCourseEnrollmentsJob;
use App\Jobs\ImportOrganizersJob;
use App\Jobs\ImportStudentsJob;
use App\Models\Topic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Controller handling Neptun CSV imports for Organizers and Students.
 * Technical Specification Table 22 & Sections 5.6, 7.3.1.
 */
class ImportController extends Controller
{
    /**
     * Bulk import organizers via CSV (POST /admin/import/organizers).
     */
    public function organizers(Request $request): JsonResponse|RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:10240'], // 10MB max per Tech Spec 7.3.1
        ]);

        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, ['csv', 'txt'])) {
            if ($request->wantsJson()) {
                return response()->json([
                    'message' => 'The file must be a CSV file.',
                    'errors' => ['file' => ['The file must be a CSV file.']],
                ], 422);
            }

            return back()->withErrors(['file' => 'The file must be a CSV file.']);
        }

        $path = $file->store('imports', 'local');

        ImportOrganizersJob::dispatch($path, $request->user()->id);

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Organizer import queued successfully.',
                'path' => $path,
            ], 202);
        }

        return back()->with('status', 'Organizer import queued successfully.');
    }

    /**
     * Bulk import students via CSV (POST /admin/import/students).
     */
    public function students(Request $request): JsonResponse|RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:10240'], // 10MB max per Tech Spec 7.3.1
        ]);

        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, ['csv', 'txt'])) {
            if ($request->wantsJson()) {
                return response()->json([
                    'message' => 'The file must be a CSV file.',
                    'errors' => ['file' => ['The file must be a CSV file.']],
                ], 422);
            }

            return back()->withErrors(['file' => 'The file must be a CSV file.']);
        }

        $path = $file->store('imports', 'local');

        ImportStudentsJob::dispatch($path, $request->user()->id);

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Student import queued successfully.',
                'path' => $path,
            ], 202);
        }

        return back()->with('status', 'Student import queued successfully.');
    }

    /**
     * Download sample CSV template for organizers (UC-3.1.1).
     */
    public function sampleOrganizersCsv(): StreamedResponse
    {
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="organizers_sample.csv"',
        ];

        return response()->stream(function () {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Full Name', 'Email', 'Faculty']);
            fputcsv($output, ['Dr. Kovács István', 'kovacs.istvan@pte.hu', 'Faculty of Engineering and Information Technology']);
            fputcsv($output, ['Nagy Katalin', 'nagy.katalin@pte.hu', 'Faculty of Sciences']);
            fclose($output);
        }, 200, $headers);
    }

    /**
     * Download sample CSV template for students (UC-3.2.1).
     */
    public function sampleStudentsCsv(): StreamedResponse
    {
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="students_sample.csv"',
        ];

        return response()->stream(function () {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Full Name', 'Email', 'Neptun Code', 'Major', 'Year of Study']);
            fputcsv($output, ['Minta Péter', 'minta.peter@student.pte.hu', 'ABC123', 'Computer Science BSc', 2]);
            fputcsv($output, ['Kiss Anna', 'kiss.anna@student.pte.hu', 'XYZ789', 'Electrical Engineering BSc', 1]);
            fclose($output);
        }, 200, $headers);
    }

    /**
     * Bulk import course enrolments via CSV (POST /admin/import/enrolments).
     * Technical Specification Table 22 & Sections 5.6, 7.3.1; Task #29.
     */
    public function enrolments(Request $request): JsonResponse|RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:10240'], // 10MB max per Tech Spec 7.3.1
            'semester_id' => ['nullable', 'integer', 'exists:semesters,id'],
            'target_topic_id' => ['nullable', 'integer', 'exists:topics,id'],
            'additional_topic_ids' => ['nullable', 'array'],
            'additional_topic_ids.*' => ['integer', 'exists:topics,id'],
        ]);

        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, ['csv', 'txt'])) {
            if ($request->wantsJson()) {
                return response()->json([
                    'message' => 'The file must be a CSV file.',
                    'errors' => ['file' => ['The file must be a CSV file.']],
                ], 422);
            }

            return back()->withErrors(['file' => 'The file must be a CSV file.']);
        }

        $path = $file->store('imports', 'local');
        $semesterId = $request->input('semester_id') ? (int) $request->input('semester_id') : null;
        $targetTopicId = $request->input('target_topic_id') ? (int) $request->input('target_topic_id') : null;
        $additionalTopicIds = array_map('intval', (array) $request->input('additional_topic_ids', []));

        ImportCourseEnrollmentsJob::dispatch(
            $path,
            $request->user()->id,
            semesterId: $semesterId,
            additionalTopicIds: $additionalTopicIds,
            targetTopicId: $targetTopicId,
        );

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Course enrolment import queued successfully.',
                'path' => $path,
            ], 202);
        }

        return back()->with('status', 'Course enrolment import queued successfully.');
    }

    /**
     * Get same-branch ancestor and descendant nodes for a topic (Task #29, 13 Oct 2026 agreement).
     */
    public function branchNodes(Topic $topic): JsonResponse
    {
        $ancestorIds = DB::table('topic_closure')
            ->where('descendant_id', $topic->id)
            ->where('distance', '>', 0)
            ->orderBy('distance', 'asc')
            ->pluck('ancestor_id')
            ->all();

        $ancestors = Topic::query()
            ->whereIn('id', $ancestorIds)
            ->notArchived()
            ->get(['id', 'title', 'code', 'depth'])
            ->sortBy(fn ($t) => array_search($t->id, $ancestorIds))
            ->values();

        $descendantIds = DB::table('topic_closure')
            ->where('ancestor_id', $topic->id)
            ->where('distance', '>', 0)
            ->orderBy('distance', 'asc')
            ->pluck('descendant_id')
            ->all();

        $descendants = Topic::query()
            ->whereIn('id', $descendantIds)
            ->notArchived()
            ->get(['id', 'title', 'code', 'depth'])
            ->values();

        return response()->json([
            'topic' => [
                'id' => $topic->id,
                'title' => $topic->title,
                'code' => $topic->code,
            ],
            'nodes_above' => $ancestors,
            'nodes_below' => $descendants,
        ]);
    }

    /**
     * Download sample CSV template for course enrolments (UC-3.2.3).
     */
    public function sampleEnrolmentsCsv(): StreamedResponse
    {
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="enrolments_sample.csv"',
        ];

        return response()->stream(function () {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Neptun Code', 'Course Code', 'Course Name']);
            fputcsv($output, ['ABC123', 'BMEVIDB101', 'Database Systems']);
            fputcsv($output, ['XYZ789', 'BMEVIDB101', 'Database Systems']);
            fclose($output);
        }, 200, $headers);
    }
}
