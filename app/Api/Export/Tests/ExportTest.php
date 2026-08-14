<?php

use App\Api\Export\Jobs\DeleteExpiredExports;
use App\Api\Export\Jobs\ProcessExport;
use App\Api\Export\Services\TodoExportService;
use App\Api\Todos\Models\Todo;
use App\Shared\Models\ExportJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

describe('Export endpoints', function () {
    it('starts an export and reports status', function () {
        Queue::fake();
        Storage::fake('user_data');
        $user = makeUser();

        $store = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/export', [
                'features' => ['todos'],
            ])
            ->assertStatus(201)
            ->assertJsonPath('success', true);

        $jobId = $store->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/export/status')
            ->assertStatus(200)
            ->assertJsonPath('data.id', $jobId)
            ->assertJsonPath('data.status', 'pending');

        Queue::assertPushed(ProcessExport::class);
    });

    it('rejects invalid features', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/export', ['features' => ['invalid']])
            ->assertStatus(422);
    });

    it('limits exports to once per day', function () {
        $user = makeUser();
        ExportJob::create([
            'user_id' => $user->id,
            'status' => 'completed',
            'features' => ['todos'],
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/export', ['features' => ['todos']])
            ->assertStatus(429);
    });

    it('returns 404 for downloads of unknown jobs', function () {
        $user = makeUser();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/export/999999/download')
            ->assertStatus(404);
    });
});

describe('ProcessExport job', function () {
    it('runs the export and completes the job', function () {
        Storage::fake('user_data');
        $user = makeUser();
        Todo::factory()->forUser($user)->count(2)->create();

        $job = ExportJob::create([
            'user_id' => $user->id,
            'status' => 'pending',
            'features' => ['todos'],
        ]);

        app(ProcessExport::class, ['userId' => $user->id, 'jobId' => $job->id])->handle();

        expect($job->fresh()->status)->toBe('completed');
        expect($job->fresh()->fileExists())->toBeTrue();
    });

    it('marks the job as failed on errors', function () {
        Storage::fake('user_data');
        $user = makeUser();

        $job = ExportJob::create([
            'user_id' => $user->id,
            'status' => 'pending',
            'features' => ['todos'],
        ]);

        $this->mock(\App\Api\Export\Services\TodoExportService::class, function ($mock) {
            $mock->shouldReceive('export')->andThrow(new \Exception('boom'));
        });

        try {
            app(ProcessExport::class, ['userId' => $user->id, 'jobId' => $job->id])->handle();
        } catch (\Throwable) {
            // expected
        }

        expect($job->fresh()->status)->toBe('failed');
    });
});

describe('ExportService feature services', function () {
    it('TodoExportService writes a csv', function () {
        Storage::fake('user_data');
        $user = makeUser();
        Todo::factory()->forUser($user)->create(['title' => 'Export me', 'status' => 'pending']);

        $path = storage_path('app/public/user/todos.csv');
        @unlink($path);
        app(TodoExportService::class)->export($user, $path);

        $content = file_get_contents($path);
        expect($content)->toContain('Export me');
        expect($content)->toContain('Title');
    });
});

describe('DeleteExpiredExports job', function () {
    it('deletes expired export files', function () {
        Storage::fake('user_data');
        $user = makeUser();

        $job = ExportJob::create([
            'user_id' => $user->id,
            'status' => 'completed',
            'features' => ['todos'],
        ]);
        Storage::disk('user_data')->put($job->file_path, 'zip-bytes');
        \Illuminate\Support\Facades\DB::table('export_jobs')->where('id', $job->id)->update(['created_at' => now()->subDays(10)]);

        app(DeleteExpiredExports::class)->handle();

        expect(ExportJob::find($job->id))->toBeNull();
        Storage::disk('user_data')->assertMissing($job->file_path);
    });
});
