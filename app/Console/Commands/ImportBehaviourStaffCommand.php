<?php

namespace App\Console\Commands;

use App\Models\BehaviourNotificationStaffDirectory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ImportBehaviourStaffCommand extends Command
{
    protected $signature = 'behaviour:import-staff-directory';
    protected $description = 'Import staff directory (display name + email) for behaviour notifications';

    public function handle(): int
    {
        $url      = (string) config('services.behaviour_feeds.staff.url');
        $auth     = (string) config('services.behaviour_feeds.staff.password'); // already encoded in env
        $timeout  = (int) config('services.behaviour_feeds.timeout', 30);

        if ($url === '') {
            $this->error('Missing services.behaviour_feeds.staff.url');
            return self::FAILURE;
        }
        if ($auth === '') {
            $this->error('Missing services.behaviour_feeds.staff.password (pre-encoded auth header value)');
            return self::FAILURE;
        }

        // Use the pre-encoded header value as-is; prepend "Basic " only if not present.
        $authHeader = str_starts_with($auth, 'Basic ') ? $auth : ('Basic ' . $auth);

        $this->info('Fetching staff directory…');

        $resp = Http::withHeaders([
            'Authorization' => $authHeader,
        ])
            ->acceptJson()
            ->timeout($timeout)
            ->get($url);

        if (!$resp->successful()) {
            $this->error("Request failed: HTTP {$resp->status()}");
            Log::warning('ImportBehaviourStaffCommand: fetch failed', [
                'status' => $resp->status(),
                'body'   => $resp->body(),
            ]);
            return self::FAILURE;
        }

        $payload = $resp->json();
        if (!is_array($payload)) {
            $this->error('Unexpected payload (not an array).');
            Log::warning('ImportBehaviourStaffCommand: unexpected payload', ['payload' => $payload]);
            return self::FAILURE;
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($payload as $row) {
            if (!is_array($row)) {
                $skipped++;
                continue;
            }

            [$displayName, $email] = $this->extractNameEmail($row);

            if ($displayName === null && $email === null) {
                $skipped++;
                continue;
            }

            $email      = $email ? mb_strtolower(trim($email)) : null;
            $normalized = $this->normalizeName((string) $displayName);

            try {
                if ($email) {
                    $model = BehaviourNotificationStaffDirectory::updateOrCreate(
                        ['email' => $email],
                        [
                            'display_name'    => $displayName ?? $email,
                            'normalized_name' => $normalized,
                        ]
                    );
                } else {
                    $model = BehaviourNotificationStaffDirectory::updateOrCreate(
                        ['normalized_name' => $normalized],
                        [
                            'display_name' => $displayName ?? '(unknown)',
                            'email'        => null,
                        ]
                    );
                }

                $model->wasRecentlyCreated ? $created++ : $updated++;
            } catch (\Throwable $e) {
                $skipped++;
                Log::warning('ImportBehaviourStaffCommand: row upsert failed', [
                    'row'   => $row,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Done. Created: {$created}, Updated: {$updated}, Skipped: {$skipped}");
        return self::SUCCESS;
    }

    private function extractNameEmail(array $row): array
    {
        // Flexible field handling to match your feeds
        $name  = $row['display_name'] ?? $row['displayName'] ?? $row['name'] ?? ($row['Display Name'] ?? null);
        $email = $row['email'] ?? $row['mail'] ?? ($row['Email'] ?? null);

        $name  = is_string($name)  ? trim($name)  : null;
        $email = is_string($email) ? trim($email) : null;

        return [$name, $email];
    }

    private function normalizeName(string $name): ?string
    {
        $name = trim($name);
        if ($name === '') return null;
        $name = preg_replace('/\s+/u', ' ', $name);
        return mb_strtolower($name);
    }
}
