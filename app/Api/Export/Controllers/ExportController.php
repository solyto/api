<?php

namespace App\Api\Export\Controllers;

use App\Api\ApiResponse;
use App\Api\Export\Jobs\ProcessExport;
use App\Api\Export\Services\ExportService;
use App\Shared\Models\ExportJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ExportController
{
    public function store(Request $request): JsonResponse
    {
        $features = $request->input('features', ExportService::FEATURES);

        if (!is_array($features) || empty($features)) {
            return ApiResponse::error('Features must be a non-empty array.', 422);
        }

        $validFeatures = ExportService::FEATURES;
        $invalid = array_diff($features, $validFeatures);
        if (!empty($invalid)) {
            return ApiResponse::error('Invalid features: '.implode(', ', $invalid), 422);
        }

        $recentExport = ExportJob::where('user_id', $request->user()->id)
            ->where('created_at', '>=', now()->subDay())
            ->exists();

        if ($recentExport) {
            return ApiResponse::error(
                'You can only export once every 24 hours. Please try again later.',
                429
            );
        }

        $job = ExportJob::create([
            'user_id' => $request->user()->id,
            'status' => 'pending',
            'features' => $features,
        ]);

        ProcessExport::dispatch($request->user()->id, $job->id);

        return ApiResponse::success([
            'id' => $job->id,
            'status' => $job->status,
            'features' => $job->features,
        ], 'Export started.', 201);
    }

    public function status(Request $request): JsonResponse
    {
        $latest = ExportJob::forUser($request->user()->id)
            ->latest()
            ->first();

        if (!$latest) {
            return ApiResponse::success(null, 'No exports found.');
        }

        $data = [
            'id' => $latest->id,
            'status' => $latest->status,
            'features' => $latest->features,
            'created_at' => $latest->created_at->toIso8601String(),
        ];

        if ($latest->status === 'completed') {
            if (!$latest->fileExists()) {
                $latest->update(['status' => 'failed']);
                $data['status'] = 'failed';
            } else {
                $data['expires_at'] = $latest->expires_at;
                $data['is_expired'] = $latest->isExpired();
            }
        }

        return ApiResponse::success($data, 'Export status.');
    }

    public function download(Request $request, int $id)
    {
        $job = ExportJob::forUser($request->user()->id)->find($id);

        if (!$job) {
            return ApiResponse::notFound('Export not found.');
        }

        if ($job->status !== 'completed') {
            return ApiResponse::error('Export is not ready yet.', 400);
        }

        if ($job->isExpired()) {
            return ApiResponse::error('Export has expired.', 410);
        }

        if (!$job->fileExists()) {
            return ApiResponse::notFound('Export file not found.');
        }

        $absolutePath = Storage::disk('user_data')->path($job->file_path);

        return response()->download(
            $absolutePath,
            'export_'.$job->id.'.zip',
            ['Content-Type' => 'application/zip']
        );
    }
}
