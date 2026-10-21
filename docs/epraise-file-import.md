# Importing saved ePraise feeds

`epraise:import-file` imports saved API JSON arrays into the local application directly.
It makes no API calls, dispatches no jobs, and disables PHP's execution time limit for
this CLI command. Arbor writeback remains a separate, queued process with its usual rate budget.

The supplied October 2026 CSV reports do **not** contain the unique IDs of individual
points, demerits or interventions. The points report also lacks teachers. UPN identifies
a student, not an individual award or incident. Those reports cannot safely create
records that will later reconcile with API records. Do not invent record IDs or match
solely by student/date/category: multiple legitimate records can share those values.
Use the saved JSON feeds, which include record, student and teacher IDs. A record absent
from the JSON cannot be recovered by this importer; obtaining an ID-bearing export
from ePraise would allow a reliable CSV import in future.

## Usage

### From the interface

Open **Maintenance → Import ePraise File** (`/import-epraise-file`):

1. Select Points, Demerits or Interventions and upload the corresponding JSON file.
2. Click **Preview file** and wait for the totals to finish. Preview does not change records.
3. Review the counts, then click **Import previewed file** to save changes.

Both preview and import use batches of 500 per browser request, with saved progress.
Keep the page open while it runs; returning to the page in the same signed-in browser
session resumes from the saved batch. No queue worker or detached server process is
required. The Import button always uses the saved file from the completed preview.
Changing the form does not alter that file; start another preview to switch files.
If a batch fails, completed batches remain saved. Start a new preview to retry.

Uploads and run snapshots are stored on the private local disk under `epraise-imports/`.
They are accessible only through the authenticated user's import session. Finished
snapshots are retained on disk; remove old files from that directory when no imports
are active if you no longer need them. The page accepts files up to 64 MB. PHP's
`upload_max_filesize` and `post_max_size`, plus the web server request-body limit,
must also allow the size being uploaded. For a 64 MB upload, allow additional room
in `post_max_size` for the upload request. The page requests at least 512 MB PHP memory
for decoding feeds. It does not remove the web server's request timeout: dividing the
work into batches keeps each processing request short.

The Task Monitor precautions below apply to interface imports as well as CLI imports.

### From the command line

Run commands from the deployed application's directory, with the files available on
that server. The paths below refer to the supplied files on this Mac. In production,
use a private directory outside `public/`, and adjust the paths.

Validate without connecting to the database:

```sh
php -d memory_limit=512M artisan epraise:import-file points /Users/mal/Desktop/points_api_json.json --validate-only
php -d memory_limit=512M artisan epraise:import-file demerits /Users/mal/Desktop/demerits_api_json.json --validate-only
php -d memory_limit=512M artisan epraise:import-file interventions /Users/mal/Desktop/interventions_api_json.json --validate-only
```

Preview database changes (the default; nothing is written):

```sh
php -d memory_limit=512M artisan epraise:import-file points /Users/mal/Desktop/points_api_json.json
```

To apply, add `--apply`:

```sh
php -d memory_limit=512M artisan epraise:import-file points /Users/mal/Desktop/points_api_json.json --apply
php -d memory_limit=512M artisan epraise:import-file demerits /Users/mal/Desktop/demerits_api_json.json --apply
php -d memory_limit=512M artisan epraise:import-file interventions /Users/mal/Desktop/interventions_api_json.json --apply
```

The JSON document is decoded in memory; database reads and transactions use batches
of 500. The memory setting above accommodates the supplied feeds. External process
managers or hosting services can impose their own runtime limits.

## Running alongside scheduled syncing

Before applying a historical file, disable **Fetch Logged Items** and **Writeback
(processAll)** in **Maintenance → Task Monitor**. Allow already-running fetches and
queued writeback jobs to finish first. This prevents a live fetch or writer racing
with the import. The importer reports and skips records already marked ready for a
queued job; rerun after those jobs drain. It returns failure if any rows were skipped.

After reviewing the import, re-enable Writeback to send new/changed records to Arbor.
Do not re-enable Fetch Logged Items while its API responses are suspected incomplete:
the current API amendment scan can mark records missing from two responses for deletion.
A file import does not fix that API completeness problem, nor change those task flags.
Task Monitor disable flags currently expire after 30 days.

## Import behaviour

- Existing records match on their original ePraise record ID. Existing Arbor IDs remain intact.
- New records require creation; changed or restored records require update if Arbor IDs
  exist, otherwise creation. The usual writeback processor handles those statuses later.
- Repeating the same file leaves unchanged records' sync status and `updated_at` intact.
- Supplied records have their missing-run counters reset. Existing deletion statuses
  are reversed for records present in the file, except jobs already queued are skipped.
- Records absent from the file are untouched. There is no deletion reconciliation.
- Omitted comments preserve existing comments; explicit `comment: null` clears them.
  These JSON feeds omit comments, so importing them cannot recover comments from the CSVs.
- No student, teacher or category mapping data is imported. Normal setup/mappings must
  exist for successful Arbor writeback.
- The full file is checked for required fields, valid timestamps and duplicate record
  IDs before writing. A later database failure rolls back the current 500-row batch;
  earlier batches remain saved. Rerunning the same file is supported.
- Timestamp handling matches the existing API fetcher, including its Europe/London
  conversion. This command does not change historical timezone behaviour.

## Supplied file validation

The provided feeds passed validation: 72,795 points, 6,470 demerits and 3,839 interventions.
The CSVs contain 72,643 points, 6,461 demerits, 3,108 sanction interventions and 716
support interventions. Counts alone do not establish completeness; export filters
and capture times can differ. No live database import was performed during development.
