<?php

namespace App\Http\Controllers\Api\Content;

use App\Http\Controllers\Api\BaseController;
use App\Models\Content\CareerApplication;
use App\Models\Content\CareerJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class CareerController extends BaseController
{
    // ── Public Careers ──────────────────────────────────────────────────────────

    public function jobs(Request $request): JsonResponse
    {
        $query = CareerJob::query()->where('status', 'open')->latest('id');

        if ($request->filled('department')) {
            $query->where('department', (string) $request->input('department'));
        }

        if ($request->filled('type')) {
            $query->where('type', (string) $request->input('type'));
        }

        $paginator = $query->paginate($request->integer('per_page', 20));

        return $this->success([
            'items' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }

    public function showJob(string $slug): JsonResponse
    {
        $job = CareerJob::query()->where('slug', $slug)->where('status', 'open')->firstOrFail();

        return $this->success($job);
    }

    public function apply(Request $request, ?CareerJob $job = null): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:50'],
            'cv_file' => ['nullable', 'file', 'mimes:pdf,doc,docx', 'max:10240'],
            'cv_url' => ['required_without:cv_file', 'nullable', 'string', 'max:500'],
            'cover_letter' => ['nullable', 'string'],
            'portfolio_url' => ['nullable', 'url', 'max:500'],
        ]);

        $cvPath = null;
        $cvUrl = $validated['cv_url'] ?? null;

        if ($request->hasFile('cv_file')) {
            $stored = $request->file('cv_file')->store('resumes', 'private');
            $cvPath = $stored;
            if (empty($cvUrl)) {
                $cvUrl = $stored;
            }
        }

        $application = CareerApplication::create([
            'career_job_id' => $job?->id,
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'cv_url' => $cvUrl ?? '',
            'cv_path' => $cvPath,
            'cover_letter' => $validated['cover_letter'] ?? null,
            'portfolio_url' => $validated['portfolio_url'] ?? null,
            'status' => 'pending',
        ]);

        return $this->success($application, 'Application submitted successfully.', 201);
    }

    // ── Admin Careers ───────────────────────────────────────────────────────────

    public function adminJobs(Request $request): JsonResponse
    {
        $paginator = CareerJob::query()->latest('id')->paginate($request->integer('per_page', 20));

        return $this->success([
            'items' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }

    public function storeJob(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:100'],
            'location' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'string', 'max:50'],
            'description' => ['required', 'string'],
            'requirements' => ['nullable', 'string'],
            'status' => ['nullable', 'in:open,closed'],
        ]);

        $slug = Str::slug($validated['title']).'-'.strtolower(Str::random(4));

        $job = CareerJob::create([
            'title' => $validated['title'],
            'slug' => $slug,
            'department' => $validated['department'] ?? 'Engineering',
            'location' => $validated['location'] ?? 'Remote',
            'type' => $validated['type'] ?? 'full-time',
            'description' => $validated['description'],
            'requirements' => $validated['requirements'] ?? null,
            'status' => $validated['status'] ?? 'open',
        ]);

        return $this->success($job, 'Career job posting created.', 201);
    }

    public function updateJob(Request $request, CareerJob $job): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:100'],
            'location' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'string', 'max:50'],
            'description' => ['sometimes', 'string'],
            'requirements' => ['nullable', 'string'],
            'status' => ['nullable', 'in:open,closed'],
        ]);

        $job->update($validated);

        return $this->success($job->fresh(), 'Career job posting updated.');
    }

    public function applications(Request $request): JsonResponse
    {
        $query = CareerApplication::query()->with('job')->latest('id');

        if ($request->filled('career_job_id')) {
            $query->where('career_job_id', $request->integer('career_job_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', (string) $request->input('status'));
        }

        $paginator = $query->paginate($request->integer('per_page', 20));

        return $this->success([
            'items' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }

    public function updateApplicationStatus(Request $request, CareerApplication $application): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:pending,reviewed,shortlisted,rejected'],
        ]);

        $application->update(['status' => $validated['status']]);

        return $this->success($application->fresh(), 'Application status updated.');
    }

    public function downloadCv(CareerApplication $application): Response
    {
        $path = $application->cv_path ?: $application->cv_url;

        if ($path && Storage::disk('private')->exists($path)) {
            return Storage::disk('private')->download($path, basename($path));
        }

        if ($path && Storage::disk('local')->exists($path)) {
            return Storage::disk('local')->download($path, basename($path));
        }

        if (filter_var($application->cv_url, FILTER_VALIDATE_URL)) {
            return redirect()->away($application->cv_url);
        }

        return $this->error('Resume file not found.', 404);
    }
}
