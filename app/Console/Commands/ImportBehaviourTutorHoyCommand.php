<?php

namespace App\Console\Commands;

use App\Models\Student;
use App\Models\BehaviourNotificationStaffDirectory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ImportBehaviourTutorHoyCommand extends Command
{
    protected $signature = 'behaviour:import-tutor-hoy {--dry-run : Parse and resolve only, do not write to DB}';
    protected $description = 'Import tutors & heads-of-year for each student and resolve staff emails for routing';

    public function handle(): int
    {
        $url      = (string) Config::get('services.behaviour_feeds.tutors.url');
        $password = (string) Config::get('services.behaviour_feeds.tutors.password'); // pre-encoded
        $timeout  = (int)   Config::get('services.behaviour_feeds.timeout', 30);

        if (!$url) {
            $this->error('Missing services.behaviour_feeds.tutors.url');
            return self::FAILURE;
        }

        $resp = Http::acceptJson()
            ->timeout($timeout)
            ->withHeaders([
                'Authorization' => 'Basic ' . $password, // pre-encoded per our decision
            ])
            ->get($url);

        if (!$resp->successful()) {
            $this->error('Tutors/HOY feed request failed: HTTP ' . $resp->status());
            $this->line($resp->body());
            return self::FAILURE;
        }

        $rows = $resp->json();
        if (!is_array($rows)) {
            $this->error('Unexpected tutors/HOY payload (not an array).');
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $written = 0;
        $skipped = 0;
        $skippedMissingRecordId = 0;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                $skipped++;
                continue;
            }

            // --- Identify the student (prefer record_id, fall back to arbor_id) ---
            $studentRecordId = $row['student_record_id'] ?? $row['student_id'] ?? $row['student'] ?? null;
            $studentArborId  = $row['student_arbor_id']  ?? $row['upn']        ?? $row['arbor_id'] ?? null;

            $studentRecordId = $this->normalizeId($studentRecordId);
            $studentArborId = $this->normalizeId($studentArborId);

            // If we only have one ID, try to resolve the other from local Student
            if (!$studentRecordId && $studentArborId) {
                $studentRecordId = $this->normalizeId(
                    Student::where('arbor_id', $studentArborId)->value('record_id')
                );
            } elseif (!$studentArborId && $studentRecordId) {
                $studentArborId = $this->normalizeId(
                    Student::where('record_id', $studentRecordId)->value('arbor_id')
                );
            }

            if (!$studentRecordId && !$studentArborId) {
                // Nothing to key by; skip gracefully.
                $skipped++;
                continue;
            }

            // Routing schema requires student_record_id (non-null unique key).
            // If we cannot resolve it, skip this row rather than crashing the import.
            if (!$studentRecordId) {
                $skipped++;
                $skippedMissingRecordId++;
                if ($skippedMissingRecordId <= 5) {
                    $this->warn('Skipping tutors/HOY row with unresolved student_record_id (student_arbor_id=' . ($studentArborId ?? 'null') . ').');
                }
                continue;
            }

            // Pull a display name from the feed if available
            $first = $row['forename'] ?? $row['first_name'] ?? null;
            $last  = $row['surname']  ?? $row['last_name']  ?? null;
            $yearGroup = trim($row['year_group'] ?? $row['YG'] ?? null);
            $displayName = trim(trim((string)$first) . ' ' . trim((string)$last));

            // If we have a name and we know the student, persist it to the Students table
            if (!$dryRun && $displayName) {
                if ($studentRecordId) {
                    \App\Models\Student::where('record_id', $studentRecordId)
                        ->where(function ($q) use ($displayName, $yearGroup) {
                            $q->whereNull('name')->orWhere('name', '!=', $displayName)->orWhere('year_group', '!=', $yearGroup)->orWhereNull('year_group');
                        })
                        ->update(['name' => $displayName, 'year_group' => $yearGroup]);
                } elseif ($studentArborId) {
                    \App\Models\Student::where('arbor_id', $studentArborId)
                        ->where(function ($q) use ($displayName, $yearGroup) {
                            $q->whereNull('name')->orWhere('name', '!=', $displayName)->orWhere('year_group', '!=', $yearGroup)->orWhereNull('year_group');
                        })
                        ->update(['name' => $displayName, 'year_group' => $yearGroup]);
                }
            }


            // --- Extract raw tutor / HOY strings (flexible keys) ---
            $tutorsRaw = $row['tutors'] ?? $row['tutor'] ?? $row['tutor_names'] ?? null;
            $hoyRaw    = $row['heads_of_year'] ?? $row['head_of_year'] ?? $row['hoy'] ?? $row['hoy_names'] ?? null;

            // Normalize into arrays of *display* names (still human-readable).
            $tutorNames = $this->parseNamesFromRaw($tutorsRaw);
            $hoyNames   = $this->parseNamesFromRaw($hoyRaw);

            // Build normalized tokens for matching
            $tutorNorm = array_map([$this, 'normalizeName'], $tutorNames);
            $hoyNorm   = array_map([$this, 'normalizeName'], $hoyNames);

            // Resolve to staff directory rows
            $tutorMatches = $this->resolveStaffByNormalizedNames($tutorNorm);
            $hoyMatches   = $this->resolveStaffByNormalizedNames($hoyNorm);

            // Extract IDs & emails
            $tutorIds    = array_values(array_unique(array_filter(array_map(fn($s) => $s['id'] ?? null, $tutorMatches))));
            $tutorEmails = array_values(array_unique(array_filter(array_map(fn($s) => $s['email'] ?? null, $tutorMatches))));
            $hoyIds      = array_values(array_unique(array_filter(array_map(fn($s) => $s['id'] ?? null, $hoyMatches))));
            $hoyEmails   = array_values(array_unique(array_filter(array_map(fn($s) => $s['email'] ?? null, $hoyMatches))));

            // Prepare DB payload
            $payload = [
                'student_record_id'        => $studentRecordId,
                'student_arbor_id'         => $studentArborId,
                'tutor_names_raw'          => $tutorsRaw ?: null,
                'hoy_names_raw'            => $hoyRaw ?: null,
                'tutor_normalized_names'   => $tutorNorm ? json_encode($tutorNorm) : null,
                'hoy_normalized_names'     => $hoyNorm ? json_encode($hoyNorm) : null,
                'tutor_staff_ids'          => $tutorIds ? json_encode($tutorIds) : null,
                'hoy_staff_ids'            => $hoyIds ? json_encode($hoyIds) : null,
                'tutor_emails'             => $tutorEmails ? json_encode($tutorEmails) : null,
                'hoy_emails'               => $hoyEmails ? json_encode($hoyEmails) : null,
                // extra_staff_emails left null here; can be populated elsewhere if you have a rule-based addition
                'updated_at'               => now(),
            ];

            if ($dryRun) {
                $written++; // count what would be written
                continue;
            }

            // Upsert into behaviour_notification_student_routing
            DB::table('behaviour_notification_student_routing')->updateOrInsert(
                ['student_record_id' => $studentRecordId],
                array_merge($payload, ['created_at' => now()])
            );

            $written++;
        }

        $this->info(sprintf(
            'Tutor/HOY import complete. %d written, %d skipped%s.',
            $written,
            $skipped,
            $dryRun ? ' (dry-run)' : ''
        ));

        if ($skippedMissingRecordId > 0) {
            $this->warn("Skipped {$skippedMissingRecordId} row(s) where student_record_id could not be resolved from feed + students table.");
        }

        return self::SUCCESS;
    }

    /**
     * Split a raw name string like:
     *  - "A Smith and B Jones"
     *  - "A Smith, B Jones and C Brown"
     *  - "A Smith"
     * into an array of display names.
     */
    private function parseNamesFromRaw(?string $raw): array
    {
        if (!$raw) return [];

        // Normalize the delimiters: turn the final " and " into ", "
        // Then explode on ","
        $normalized = str_replace(' and ', ', ', $raw);
        $parts = array_map(fn($s) => trim($s), explode(',', $normalized));

        // Drop empty, preserve display casing
        $parts = array_values(array_filter($parts, fn($s) => $s !== ''));

        return $parts;
    }

    /**
     * Lowercase + collapse internal whitespace for matching.
     */
    private function normalizeName(string $name): string
    {
        $down = mb_strtolower($name);
        // Collapse multiple whitespace to single spaces
        $down = preg_replace('/\s+/u', ' ', $down ?? '') ?? '';
        return trim($down);
    }

    /**
     * Return an array of ['id' => ..., 'email' => ..., 'normalized_name' => ...]
     * for each normalized name we can find in the staff directory.
     */
    private function resolveStaffByNormalizedNames(array $normalizedNames): array
    {
        if (empty($normalizedNames)) return [];

        $matches = BehaviourNotificationStaffDirectory::query()
            ->whereIn('normalized_name', $normalizedNames)
            ->get(['id', 'email', 'normalized_name'])
            ->map(fn($m) => [
                'id'              => $m->id,
                'email'           => $m->email,
                'normalized_name' => $m->normalized_name,
            ])
            ->all();

        return $matches;
    }

    /**
     * Normalize ID-like values to trimmed strings; returns null when empty.
     */
    private function normalizeId(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);
        return $value !== '' ? $value : null;
    }
}
