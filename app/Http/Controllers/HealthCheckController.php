<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class HealthCheckController
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'queue' => $this->checkQueue(),
            'mail' => $this->checkMail(),
            'backup' => $this->checkBackup(),
        ];

        $allOk = collect($checks)->every(fn (array $c) => $c['status'] === 'ok');

        return response()->json([
            'status' => $allOk ? 'ok' : 'fail',
            'checks' => $checks,
        ], $allOk ? 200 : 503);
    }

    private function checkDatabase(): array
    {
        try {
            DB::connection()->getPdo();

            return ['status' => 'ok'];
        } catch (Throwable $e) {
            return ['status' => 'fail', 'message' => $e->getMessage()];
        }
    }

    private function checkQueue(): array
    {
        $driver = config('queue.default');

        return ['status' => 'ok', 'driver' => $driver];
    }

    private function checkMail(): array
    {
        $mailer = config('mail.default');
        if (! $mailer) {
            return ['status' => 'fail', 'message' => 'No mail driver configured'];
        }

        return ['status' => 'ok', 'driver' => $mailer];
    }

    private function checkBackup(): array
    {
        $disks = config('backup.backup.destination.disks', ['local']);
        $disk = $disks[0] ?? 'local';

        try {
            $path = '_health-'.uniqid().'.tmp';
            Storage::disk($disk)->put($path, 'ok');
            Storage::disk($disk)->delete($path);

            return ['status' => 'ok', 'disk' => $disk];
        } catch (Throwable $e) {
            return ['status' => 'fail', 'message' => $e->getMessage()];
        }
    }
}
