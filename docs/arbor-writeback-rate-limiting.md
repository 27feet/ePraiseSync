# Arbor rate limiting: final review and Windows deployment

Nothing has been committed, pushed or deployed by this work. No migrations or production
commands/data operations were run. The production baseline supplied is `118ddab979474baa3b7bad93f3c4ddebdd5a0ec9`.

## 1. Global traffic coverage and the 400/minute assumption

The previous implementation's 240/minute cap covered `ArborHttp` but did not cover the
SDK's actual GraphQL requests. The SDK package reads `ARBOR_URL`, `ARBOR_APP_USERNAME`
and `ARBOR_APP_PASSWORD`, exactly the same environment settings as REST. There is no
project evidence of a separate read quota. Treating both as one quota is the conservative
choice; the approximately 400/minute service limit is supplied by the operator, not a
newly verified public Arbor guarantee. Searches of public Arbor material did not establish
separate REST/GraphQL allowances. The developer portal may require a partner login.

All application Arbor traffic found by the source audit now shares one limiter:

| Traffic | Entry points | Protection |
| --- | --- | --- |
| Normal writeback | Nine Create/Update/Delete Point, Demerit and Intervention jobs | `ArborHttp`, shared limiter and record retry wrapper |
| SDK GraphQL queries/mutations | Calendar dates, academic years/terms, behaviour categories, sessions/classes, scales/categories, staff and optional student fetching | SDK transport rebound in `AppServiceProvider` to the same middleware; response API unchanged |
| Legacy repair jobs | `FixPoint`, `FixDemerit`, and the legacy file `Jobs/KillPoints.php` | Already use `ArborHttp`; same limiter, exceptions use their existing queue-failure handling |
| Legacy controllers | `Controllers/KillPoints`, `TestPoints`, `KillConsentTest` | Direct HTTP calls now explicitly use the shared middleware; SDK query also covered |

The calendar fetch's seven speculative token consumptions were removed: each page now
charges exactly once at the real request boundary. `fetchArborStudents` is currently
commented out in the system-data command, but its SDK requests are protected if invoked.
The system-data command records a deferred heartbeat/returns failure if throttled; a later
scheduled/manual invocation retries it. There is no unbounded SDK HTTP retry loop.

The two legacy test controllers retain their existing sandbox/separate-school targets;
none was silently redirected to the production school's URL. These controllers are not
registered as active web routes in the current `routes/web.php`. They nevertheless use
the shared limiter if invoked manually. The consent tool's embedded credential was removed;
that tool is now disabled unless its separate connection settings are explicitly provided.

A spare 160 requests/minute is **not** a defensible allowance for unmetered code: page counts,
manual executions, multiple workers and bulk repair loops vary. Instead, all the traffic
above now competes within **240 total admissions/minute**, leaving approximately 160 below
the operator's 400/minute assumption. Other installations or external programs using the
same Arbor account are outside this application's control unless they share the same
Redis database, prefix and limiter key. Do not claim a global guarantee over external clients.

## 2. Actual calls, credits and retry semantics

`writeback:processAll` retains its 180-credit per-pass budget: Point=1,
Demerit/Intervention=6. It selects eligible records, conditionally changes them to READY
and queues promptly with no pacing sleep. Record credits are not HTTP tokens: complex
jobs can issue point, incident, involvement, two action and cleanup requests. Each actual
HTTP admission costs one token regardless of record type.

| Outcome | Status and retry history |
| --- | --- |
| Only local admission contention, limiter unavailable, or cooperative time-budget yield | Return to REQUIRES create/update/delete, with a future `sync_next_retry_at`. A partly created record returns to REQUIRES update. Attempts, last real failure timestamp and error status/body remain unchanged, even when the counter is already at its cap. No actual failed HTTP request means no new failed attempt. |
| Actual HTTP 429 | Stop that job immediately; preserve partial IDs, HTTP status/body; increment attempts once; set last-failed timestamp and next retry to the later of Retry-After and configured backoff plus jitter. At the cap, remain failed without automatic retry. |
| Other HTTP failure | Existing job failure status/body handling remains; the normal dispatcher allocates increasing backoff/attempts for failed records whose next retry is null. If a real HTTP failure occurred before a later local deferral in the same multi-request job, that failure is preserved and scheduled once rather than being hidden by the local deferral. |

A focused test cycles a record through 20 dispatcher/job/local-deferral rounds with
`ARBOR_SYNC_MAX_ATTEMPTS=2`: its attempt counter stays at 1, its previous real 429 evidence
is unchanged, and no HTTP request is sent. A separate test covers a previously authorised
last retry whose counter already equals the cap.

These jobs are acknowledged when their record-level retry state is saved. They are not
kept reserved for a minute-long cooldown, and do not depend on queue `release()` or
queue attempt counts for ordinary sync retries. Keep the writeback scheduler running:
it is what re-dispatches due records. No new retry queue/system was introduced.

Existing remote IDs and action/deletion progress are checkpointed. Jobs refresh the model
and atomically claim their expected READY state. Duplicate deliveries that cannot claim
that state do nothing. Interrupted creates with known remote IDs resume through Update.

## 3. Rolling window, spacing, concurrency and fail-closed behaviour

One Redis Lua script atomically checks/removes expired entries, checks shared cooldown
and last-admission time, and reserves the new slot. Redis TIME is the clock. Two workers
cannot both reserve the same 250 ms admission interval; no process-local PHP counter is
used. Separate-process tests inspect Redis reservation timestamps as well as total budget.
Atomic script reference: <https://redis.io/docs/latest/develop/programmability/eval-intro/>.

A 250 ms gap permits at most four admissions/second (240/minute) in steady state. The
rolling 60,000 ms count independently prevents a window overflow, including configuration
changes or weighted callers. Both constraints must permit a request. They are largely
redundant at the defaults; changing either does not disable the other. Network transit
can change the exact arrival timing at Arbor; the guarantees are on shared request
admissions immediately before sending, not control of the remote network.

An actual 429 publishes a shared cooldown; shorter later observations cannot reduce it.
Retry-After accepts integer seconds or an HTTP-date. Missing/invalid headers default to
60 seconds. Production headers from the reported incident were not supplied/queried;
support is tested, not a claim that Arbor definitely sends the header. Format reference:
<https://www.rfc-editor.org/rfc/rfc9110.html#name-retry-after>.

If Redis admission fails, the HTTP handler is not called. The standard job returns to
REQUIRES with a 60-second deferral and no additional attempt. SDK/legacy callers also
fail closed; the SDK scheduler reports deferral, while old repair jobs retain their
queue exception path. Requests already in flight cannot be recalled by a new cooldown.

## 4. READY recovery safety model

The report selects only READY statuses (10, -10, -20), narrowed by optional filters. It
never infers non-delivery from age or an empty Redis ready list. SQL and Redis do not share
an atomic transaction; publishing can succeed even when the acknowledgement is lost.

On a dispatch exception, the dispatcher stops that pass, logs an error, returns failure
and conditionally annotates a still-READY row only if it has no prior HTTP error evidence.
Existing HTTP error status/body are preserved. It does not revert/re-publish it. If a worker
already claimed/completed it, the diagnostic does not overwrite its current state.
Tests cover both an accepted-but-unacknowledged job and a job already completed before
the publisher sees an error. Abrupt death before publishing may produce no log; the
report still finds the READY row.

Reports perform only reads/output: no record/timestamp, application cache, or audit-log
writes. Report is the default (no `--apply`):

```powershell
php artisan writeback:recover-ready
php artisan writeback:recover-ready --model=interventions --status=10
php artisan writeback:recover-ready --model=interventions --status=10 --id=123 --id=124
```

`--id` means the local SQL row ID, **not** the ePraise record ID, and requires exactly one
model. Repeat model/status/id options to select multiple values; omit them for all models
and all three READY states. Invalid filters fail without changes.

Before applying, the operator must establish that:

1. All scheduled/manual producers are stopped.
2. Pending, delayed, reserved and failed deliveries have been inspected/drained; no old
   payload will later be retried/replayed, including archived jobs from before Redis loss.
3. Every worker is stopped and no Arbor request is in flight.
4. Any uncertain remote writes have been audited before deciding the selected rows can
   safely be retried. Empty Redis alone does not establish this.

Only then apply the same reviewed filters:

```powershell
php artisan writeback:recover-ready --model=interventions --status=10 --id=123 --apply --workers-stopped-and-queue-empty
```

The required flag asserts the entire audit above. It is **not** an automated queue check
or proof that records are absent from Arbor. There is no automatic stale-READY recovery.
READY create returns to requires create, or requires update if known remote IDs exist;
READY update/delete return to their matching requires states. Each update is conditional
on the row still having the selected READY status. Error evidence, attempts, existing
cooldown dates and remote IDs are preserved. Each changed row logs run ID, operator,
model, local/ePraise IDs and old/new status, followed by a run/filter summary. Normal
application log retention/access controls should preserve this audit.

Checkpointing cannot make a remote write plus local SQL commit atomic: a lost successful
create response remains ambiguous. Never blindly replay such creates merely because no
remote ID was saved locally. Manual auditing is still required for that case.

## 5. The existing 17 failed Interventions

Use the existing supported automatic retry flow, without SQL edits:

1. Deploy/restart updated workers, retaining the records and their retry history.
2. Enable **Writeback (processAll)** in Task Monitor. Keep incomplete ePraise fetching paused.
3. Let the once-per-minute scheduler run, or invoke `php artisan writeback:processAll` once.
4. Failed status 11 below the maximum, with no next retry time, gets the next configured
   backoff (and attempt allocation). The first pass need not enqueue it immediately.
   Subsequent passes leave future retries alone; when due, they move it to requires create
   and dispatch READY create. Existing known remote IDs route it to update rather than
   recreate. A new real 429 then uses the shared cooldown/Retry-After handling.

If a future retry time already exists, let it become due. At/above the attempt cap, no
new automatic retry is scheduled. The existing **Maintenance → Sync Recovery → Requeue
failed Interventions** action is the supported deliberate reset for that type, after
reviewing the cause. It resets **all failed Interventions**, their counters and error
fields, not just these 17. The separate “Requeue max-attempt failures” action affects all
three types. Neither broad reset should be used merely to bypass an active cooldown.

These are database sync failures, usually acknowledged queue jobs, so `queue:retry all`
is not their normal recovery mechanism. It can also replay old uncertain deliveries.
`writeback:recover-ready` does not select status 11 and is not the tool for these 17 rows.

## 6. Exact defaults and recommended production settings

For this native Windows worker, keep the existing queue-level `--tries=3` and use:

```powershell
php artisan queue:work redis --queue=default --sleep=3 --tries=3 --timeout=90
```

Set Redis `retry_after` to **180**, not 90. Equality with the 90-second worker timeout was
unsafe. A 45-second cooperative request budget, 15-second HTTP timeout and a 1-second
local wait threshold fit below 90/180. Windows without pcntl does not enforce Laravel's
signal-based `--timeout`: safety does not depend on it. Confirm the service wrapper's
stop policy permits active jobs to finish; do not force-kill active PHP workers.
Laravel reference: <https://laravel.com/docs/12.x/queues#job-expirations-and-timeouts>.

The limiter loop uses elapsed monotonic time, including Redis round trips. A dedicated
`arbor_limiter` connection uses 2-second connect/read timeouts, no configured reconnect
retries, and the existing Redis host/database/prefix. One Redis operation may extend
past the loop deadline by its bounded I/O time (roughly up to 2+2 seconds); the worker is
never deliberately held through a full minute-long cooldown. Each new HTTP call must fit
its full timeout inside the remaining job budget. This is a cooperative budget, not a
hard OS deadline for unrelated database hangs or arbitrary external SDK code.

The exact new settings below have the **same code default and recommended production
value**, unless annotated. Keep the existing working Redis client, host, DB, credentials,
URL and prefix; do not change queue namespaces while a backlog exists. If using a custom
limiter connection, give it equivalent bounded I/O options; URL query parameters must
not override them with infinite timeouts.

```dotenv
REDIS_QUEUE_RETRY_AFTER=180
ARBOR_RATE_LIMIT_REDIS_CONNECTION=arbor_limiter
ARBOR_REDIS_TIMEOUT_SECONDS=2
ARBOR_REDIS_READ_TIMEOUT_SECONDS=2
ARBOR_RATE_LIMIT_KEY=arbor:writeback
ARBOR_RATE_LIMIT_REQUESTS=240
ARBOR_RATE_LIMIT_WINDOW_MS=60000
ARBOR_RATE_LIMIT_MIN_INTERVAL_MS=250
ARBOR_RATE_LIMIT_MAX_WAIT_MS=1000
ARBOR_RATE_LIMIT_COOLDOWN_SECONDS=60
ARBOR_CONNECT_TIMEOUT_SECONDS=5
ARBOR_REQUEST_TIMEOUT_SECONDS=15
ARBOR_WRITEBACK_JOB_BUDGET_SECONDS=45
ARBOR_SYNC_MAX_ATTEMPTS=10
ARBOR_SYNC_RETRY_DELAYS=60,180,600,1800,3600,7200,14400,21600,28800,43200
ARBOR_SYNC_RETRY_JITTER_PERCENT=0.15
```

Also confirm existing `QUEUE_CONNECTION=redis` (the sample development `.env.example`
uses database, not the recommended production driver). All instances using these Arbor
credentials must share the limiter's Redis database/prefix/key. The SDK continues to use
its existing `laravelarbor` configuration, which defaults to the same three `ARBOR_*`
authentication settings as REST. Custom published SDK configuration must be checked too.

Optional legacy consent settings have **null/empty defaults** and are **recommended unset**:

```dotenv
ARBOR_LEGACY_CONSENT_URL=
ARBOR_LEGACY_CONSENT_USERNAME=
ARBOR_LEGACY_CONSENT_PASSWORD=
```

They are not required for normal syncing. The previously embedded credential needs
rotation because deleting it from current code does not remove it from repository history.

## 7. Windows deployment commands (instructions only; not executed)

The earlier Windows server's paths, scheduler names, worker wrapper and web server were
not supplied in this session. The following are exact PowerShell commands with explicit
site-specific variables. Do not substitute guessed service/task names. For multiple
producers/workers, repeat the corresponding commands for every instance. No migration,
Composer dependency installation/update or frontend build is required for these changes.

Before starting: commit/review the complete change set and make that commit available to
the server through your approved process. Do not deploy only `git diff`'s tracked files:
new files are currently untracked too. Back up the database, server `.env` and old commit.

In **Administrator PowerShell**, fill in the real values:

```powershell
$AppPath = 'C:\REPLACE\ePraiseSync'
$PhpExe = 'C:\REPLACE\php.exe'
$WorkerService = 'REPLACE_WITH_EXISTING_WORKER_SERVICE_NAME'
$SchedulerTask = 'REPLACE_WITH_EXISTING_SCHEDULER_TASK_NAME'
$SchedulerTaskPath = '\'  # Replace if the task is in a folder.
$TargetCommit = 'REPLACE_WITH_YOUR_NEW_REVIEWED_COMMIT_SHA'
$ErrorActionPreference = 'Stop'
if ($TargetCommit -notmatch '^[0-9a-fA-F]{7,40}$') { throw 'Set the reviewed commit SHA before starting.' }
Set-Location $AppPath

# PowerShell 5.1 does not automatically throw on native program failure.
function Invoke-Checked([scriptblock]$Command) {
    & $Command
    if ($LASTEXITCODE -ne 0) { throw "Command failed with exit code $LASTEXITCODE; stop deployment." }
}

Invoke-Checked { git status --short }
Invoke-Checked { git rev-parse HEAD }
Invoke-Checked { & $PhpExe -v }
Invoke-Checked { git fetch origin }
Invoke-Checked { git show --no-patch --oneline $TargetCommit }
```

Stop if Git reports unpreserved server edits. Record the old SHA, take the database
backup using your existing backup tool, and privately copy `.env`. In Task Monitor,
disable Fetch Logged Items, Writeback and Fetch System Data before maintenance. Disable
all app scheduler tasks; disabling a task does not stop a run already in progress:

```powershell
Disable-ScheduledTask -TaskName $SchedulerTask -TaskPath $SchedulerTaskPath
Get-ScheduledTask -TaskName $SchedulerTask -TaskPath $SchedulerTaskPath
Invoke-Checked { & $PhpExe artisan down --retry=60 }
Invoke-Checked { & $PhpExe artisan queue:pause redis:default }
Invoke-Checked { & $PhpExe artisan queue:monitor redis:default --max=2147483647 }
```

`queue:pause` is available in the locked Laravel version. It adds a queue pause flag;
it does not clear Task Monitor flags. Normal workers without `--force` also respect
maintenance mode. Wait for current scheduler/manual operations to finish and for all
workers to be idle with reserved jobs zero; inspect more than one poll and the worker's
logs. Pending jobs may remain: do not drain them with old, unprotected workers just to
get an empty queue. Do not use Stop-Process/Stop-ScheduledTask to kill active writes.
Then stop the idle worker service:

```powershell
Stop-Service -Name $WorkerService
Get-Service -Name $WorkerService
```

If the worker is run by a Scheduled Task rather than a service, disable its trigger too;
use that installation's graceful stop mechanism after the current job has finished, and
later Enable-/Start-ScheduledTask for that worker instead of service commands. An NSSM
or WinSW service normally accepts the Stop-/Start-Service commands above. Verify its
existing arguments/working directory rather than adding a second competing worker.

Install the reviewed commit; do not reset away server changes:

```powershell
Invoke-Checked { git fetch origin }
Invoke-Checked { git show --no-patch --oneline $TargetCommit }
Invoke-Checked { git merge --ff-only $TargetCommit }

# Dependencies/lockfile are unchanged. Refresh autoloading without package scripts/install.
Invoke-Checked { composer dump-autoload --no-dev --optimize --no-scripts }

# Edit the production .env to the settings above, preserving secrets and existing namespaces.
$EnvFile = Join-Path $AppPath '.env'
Start-Process -FilePath notepad.exe -ArgumentList ('"{0}"' -f $EnvFile) -Wait
# Ensure edits are saved before continuing.

Invoke-Checked { & $PhpExe artisan config:clear }
Invoke-Checked { & $PhpExe artisan route:clear }
Invoke-Checked { & $PhpExe artisan view:clear }
Invoke-Checked { & $PhpExe artisan event:clear }
Invoke-Checked { & $PhpExe artisan filament:optimize-clear }
Invoke-Checked { & $PhpExe artisan config:cache }
Invoke-Checked { & $PhpExe artisan event:cache }
Invoke-Checked { & $PhpExe artisan route:cache }
Invoke-Checked { & $PhpExe artisan view:cache }
Invoke-Checked { & $PhpExe artisan filament:optimize }
Invoke-Checked { & $PhpExe artisan help writeback:recover-ready }
```

Do **not** use `cache:clear`, plain `optimize:clear`, Redis FLUSHDB/FLUSHALL, `key:generate`,
queue-clearing commands or migrations for this update. Keep `.env`, storage and existing
Windows ACLs/service identity. The application and worker accounts need access to the
same Redis instance and writable storage/bootstrap cache directories.

If this site uses IIS, recycle only its actual application pool, not the whole server:

```powershell
Import-Module WebAdministration
Restart-WebAppPool -Name 'REPLACE_WITH_EXISTING_APP_POOL_NAME'
```

For Apache/another web stack, use its existing site/PHP reload procedure instead; do not
run IIS commands on a non-IIS installation or blindly restart other sites' services.
Update the existing worker wrapper's arguments to the exact queue:work command in section 6.
Wait one previous limiter window (60 seconds) since the last old-code Arbor request and
any known Retry-After deadline on the first switch; old cache counters are not migrated.
Then resume normal processing:

```powershell
Invoke-Checked { & $PhpExe artisan up }
Invoke-Checked { & $PhpExe artisan queue:resume redis:default }
Start-Service -Name $WorkerService
Get-Service -Name $WorkerService
Invoke-Checked { & $PhpExe artisan queue:monitor redis:default --max=2147483647 }
Get-Content .\storage\logs\laravel.log -Tail 100
```

At this stage keep producers paused if auditing old READY rows: updated workers can now
safely consume the existing queue. Before applying READY recovery, audit all queue states,
failed payloads and remote uncertainty, pause the queue again, wait for idle, stop workers,
then apply only reviewed filters. Resume/start workers afterwards.

When ready, enable Writeback (and intended Fetch System Data) in Task Monitor, then:

```powershell
Enable-ScheduledTask -TaskName $SchedulerTask -TaskPath $SchedulerTaskPath
Get-ScheduledTask -TaskName $SchedulerTask -TaskPath $SchedulerTaskPath
Invoke-Checked { & $PhpExe artisan writeback:processAll }
```

Keep Fetch Logged Items disabled while ePraise API completeness is unresolved. Verify new
jobs settle to success or have sensible delayed retries, and that 429s publish cooldowns.
If update/cache commands fail, leave the site in maintenance and workers stopped; restore
the prior reviewed code and `.env`, rebuild targeted caches, and restart as above. Do not
blindly roll back database/imported/remote data to fix a code-deployment error.

## 8. Verification and authoritative file totals

Tests use fake Arbor HTTP and in-memory SQLite. Real Redis tests require a separate,
disposable local test instance, never production. They exercise an actual preloaded mixed
Redis queue with Laravel's native worker, independent PHP worker processes, 250 ms slots,
rolling expiry, GraphQL/REST/legacy aggregate budgets, and 429 cooldowns. Windows service
wrappers and the production Memurai build still need environment verification.

PowerShell on a development/test machine with isolated Redis on port 16389:

```powershell
$env:ARBOR_TEST_REDIS_PORT = '16389'
php artisan test --compact --filter='ArborWritebackTest|ArborRateLimiterRedisTest'
php artisan test --compact
Remove-Item Env:ARBOR_TEST_REDIS_PORT
```

Without the environment variable, the six real-Redis tests are skipped. No test sends
live Arbor traffic. The existing ExampleTest's 200-vs-login-302 failure was independently
reproduced on unchanged `118ddab`; it is outside this fix and was not silently changed.

`git diff --stat` only counts tracked modifications, while `git status --short` may collapse
untracked directories into one line. `git ls-files --others --exclude-standard` enumerates
all new leaf files. All new files must be included in the eventual commit, alongside the
tracked changes. Final execution results and exact aggregate file totals are recorded below.

## Related findings (not silently mixed into normal writeback)

- The unused legacy consent controller had an embedded credential. The literal was removed
  and the tool requires explicit separate configuration; rotate that credential independently.
- `app/Jobs/KillPoints.php` declares a duplicate `FixPoint` class instead of matching its
  filename. It is not used by the normal dispatcher. It was not renamed/re-enabled here;
  audit legacy repair tools before invoking them. The normal nine jobs are covered.
- Legacy repair exceptions use queue-level retries, not the standard record wrapper. They
  cannot bypass the global limiter, even when `--tries=3` retries them, but their old business
  semantics and singular endpoints were not rewritten as part of rate limiting.
- Absolute exactly-once remote writes and indefinite database hangs cannot be solved by
  rate limiting. The operator delivery audit remains necessary after ambiguous creates.

## Final executed verification and Git manifest

Validation on 2026-10-21 (isolated Redis 8.0.2, fake Arbor HTTP, in-memory SQLite):

- Focused: **32 passed, 488 assertions**, including six real-Redis integration tests.
- Full suite: **54 passed, 1 pre-existing failure, 597 assertions**. The unchanged feature
  ExampleTest expects 200 for `/` but Filament returns a guest login redirect (302).
  This same failure was reproduced independently on unchanged `118ddab` during the prior review.
- `git diff --check` passed. No production Windows/Memurai verification is claimed.
- No commits, pushes, deployments, migrations, production HTTP or production data changes.

Authoritative aggregate: **30 files: 22 tracked modifications + 8 new files;
1837 insertions, 159 deletions**. The aggregate counts every untracked leaf
file as added content; nothing has been staged merely to obtain these totals.

Actual `git status --short`:

```text
 M .env.example
 M app/Console/Commands/FetchSystemDataCommand.php
 M app/Console/Commands/ProcessWritebackCommand.php
 M app/Http/Controllers/KillConsentTest.php
 M app/Http/Controllers/KillPoints.php
 M app/Http/Controllers/TestPoints.php
 M app/Jobs/CreateDemerit.php
 M app/Jobs/CreateIntervention.php
 M app/Jobs/CreatePoint.php
 M app/Jobs/DeleteDemerit.php
 M app/Jobs/DeleteIntervention.php
 M app/Jobs/DeletePoint.php
 M app/Jobs/UpdateDemerit.php
 M app/Jobs/UpdateIntervention.php
 M app/Jobs/UpdatePoint.php
 M app/Providers/AppServiceProvider.php
 M app/Services/FetchBackgroundDataService.php
 M app/Support/ArborHttp.php
 M app/Support/ArborRateLimiter.php
 M config/database.php
 M config/queue.php
 M config/services.php
?? app/Console/Commands/RecoverReadyWritebacksCommand.php
?? app/Jobs/Concerns/
?? app/Support/ArborSyncRetry.php
?? app/Support/ArborThrottledException.php
?? docs/arbor-writeback-rate-limiting.md
?? tests/Feature/ArborRateLimiterRedisTest.php
?? tests/Feature/ArborWritebackTest.php
?? tests/Support/
```

Actual `git diff --stat` (tracked files only):

```text
 .env.example                                     |  24 +++++
 app/Console/Commands/FetchSystemDataCommand.php  |  33 +++---
 app/Console/Commands/ProcessWritebackCommand.php |  85 ++++++----------
 app/Http/Controllers/KillConsentTest.php         |   8 +-
 app/Http/Controllers/KillPoints.php              |   2 +-
 app/Http/Controllers/TestPoints.php              |   2 +-
 app/Jobs/CreateDemerit.php                       |  17 +++-
 app/Jobs/CreateIntervention.php                  |  17 +++-
 app/Jobs/CreatePoint.php                         |   7 +-
 app/Jobs/DeleteDemerit.php                       |  13 ++-
 app/Jobs/DeleteIntervention.php                  |  11 +-
 app/Jobs/DeletePoint.php                         |   7 +-
 app/Jobs/UpdateDemerit.php                       |  17 +++-
 app/Jobs/UpdateIntervention.php                  |  17 +++-
 app/Jobs/UpdatePoint.php                         |   7 +-
 app/Providers/AppServiceProvider.php             |   3 +-
 app/Services/FetchBackgroundDataService.php      |   8 --
 app/Support/ArborHttp.php                        |  49 ++++++++-
 app/Support/ArborRateLimiter.php                 | 123 ++++++++++++++---------
 config/database.php                              |  14 +++
 config/queue.php                                 |   2 +-
 config/services.php                              |  18 ++++
 22 files changed, 325 insertions(+), 159 deletions(-)
```

Complete expanded changed-file list (includes new files hidden behind `??` directory entries):

```text
.env.example
app/Console/Commands/FetchSystemDataCommand.php
app/Console/Commands/ProcessWritebackCommand.php
app/Console/Commands/RecoverReadyWritebacksCommand.php
app/Http/Controllers/KillConsentTest.php
app/Http/Controllers/KillPoints.php
app/Http/Controllers/TestPoints.php
app/Jobs/Concerns/HandlesArborWriteback.php
app/Jobs/CreateDemerit.php
app/Jobs/CreateIntervention.php
app/Jobs/CreatePoint.php
app/Jobs/DeleteDemerit.php
app/Jobs/DeleteIntervention.php
app/Jobs/DeletePoint.php
app/Jobs/UpdateDemerit.php
app/Jobs/UpdateIntervention.php
app/Jobs/UpdatePoint.php
app/Providers/AppServiceProvider.php
app/Services/FetchBackgroundDataService.php
app/Support/ArborHttp.php
app/Support/ArborRateLimiter.php
app/Support/ArborSyncRetry.php
app/Support/ArborThrottledException.php
config/database.php
config/queue.php
config/services.php
docs/arbor-writeback-rate-limiting.md
tests/Feature/ArborRateLimiterRedisTest.php
tests/Feature/ArborWritebackTest.php
tests/Support/ArborWritebackFixtures.php
```
