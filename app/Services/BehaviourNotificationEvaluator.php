<?php

namespace App\Services;

use App\Models\AcademicCalendarDate;
use App\Models\BehaviourNotificationLog;
use App\Models\BehaviourNotificationRule;
use App\Models\Demerit;
use App\Models\Intervention;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class BehaviourNotificationEvaluator
{
    /**
     * Evaluate active rules as of $asOf (you’ll usually pass “yesterday” at 8am),
     * create a log row for any threshold newly hit, and return how many logs were created.
     */
    public function evaluateAndLog(Carbon $asOf): int
    {
        $created = 0;

        // Load all active rules (old + new fields). Keep stable ordering for predictability.
        $rules = BehaviourNotificationRule::query()
            ->where('is_active', true)
            ->orderBy('source')
            ->orderBy('threshold')
            ->get();

        foreach ($rules as $rule) {
            // ---- 1) Resolve timeframe & window ---------------------------------
            // New mode support: if mode === 'realtime_today' we force timeframe 'today'
            $mode       = $rule->mode        ?? null;  // new (may be null on older rows)
            $metric     = $rule->metric      ?? 'count';
            $catsArray  = is_array($rule->categories ?? null) ? $rule->categories : null; // new
            $yearGroups = is_array($rule->year_groups ?? null) ? $rule->year_groups : null; // new

            // Back-compat: existing rules use 'timeframe' of 'term' or 'rolling_school_days'
            $timeframe = ($mode === 'realtime_today') ? 'today' : ($rule->timeframe ?? 'rolling_school_days');

            // Window resolution:
            // - term: start-of-term -> end-of-term (full term, not to-date)
            // - rolling_school_days: last N school days *ending yesterday*
            // - today: the current school day only (if today is not a school day, skip)
            if ($timeframe === 'term') {
                $window = $this->resolveTermWindow($asOf);
            } elseif ($timeframe === 'rolling_school_days') {
                $yesterday = $asOf->copy()->subDay();
                $window = $this->resolveRollingWindow($yesterday, (int) ($rule->rolling_days ?? 10));
            } else { // 'today'
                $todayRow = AcademicCalendarDate::query()
                    ->where('is_school_day', true)
                    ->whereDate('date', $asOf->toDateString())
                    ->first();

                if (!$todayRow) {
                    continue; // not a school day -> nothing to do
                }

                $d = Carbon::parse($todayRow->date)->startOfDay();
                $window = [
                    'start' => $d,
                    'end'   => $d->copy()->endOfDay(),
                    'dates' => [$d->toDateString()],
                ];
            }

            if (!$window) {
                continue; // nothing to evaluate (no term or no school days)
            }

            // ---- 2) Build base query for this rule (source) --------------------
            /** @var \Illuminate\Database\Eloquent\Builder $q */
            $source = $rule->source; // 'demerit' | 'intervention' | 'point'
            if ($source === 'demerit') {
                $q = Demerit::query();
                $dateColumn = 'date';
            } elseif ($source === 'point' || $source === 'points') {
                $q = \App\Models\Point::query();
                $dateColumn = 'date';
            } elseif ($source === 'intervention') {
                $q = Intervention::query();
                $dateColumn = 'date';
            } else {
                continue; // unknown source
            }

            // Exclude locally DELETED / PARTIALLY_DELETED etc.
            $this->applyStatusExclusions($q, $q->getModel()::class);

            // Window filter (+ exact dates if provided)
            $this->applyWindowFilter($q, $window, $dateColumn);

            // Categories: support JSON array on the rule; fallback to single 'category'
            if ($catsArray && count($catsArray)) {
                $q->whereIn('category', $catsArray);
            } elseif (!empty($rule->category)) {
                $q->where('category', $rule->category);
            }

            // Optional year group targeting: join students by local ePraise id
            if ($yearGroups && count($yearGroups)) {
                $q->join('students', 'students.record_id', '=', $q->getModel()->getTable() . '.student_id')
                    ->whereIn('students.year_group', $yearGroups);
            }

            // Constrain to known students table (consistent with existing code)
            $q->whereIn($q->getModel()->getTable() . '.student_id', Student::query()->pluck('record_id'));

            // ---- 3) Aggregate in SQL (avoid N+1) ------------------------------
            if ($metric === 'sum') {
                // For demerits, points are typically negative — sum absolute to express "total points of demerits"
                $sumExpr = ($source === 'demerit') ? 'SUM(ABS(points))' : 'SUM(points)';
                $agg = $q->selectRaw('' . $q->getModel()->getTable() . '.student_id as sid, ' . $sumExpr . ' as agg_value, COUNT(*) as agg_count')
                    ->groupBy('sid')
                    ->pluck('agg_value', 'sid')
                    ->toArray();
                $counts = $agg; // threshold is applied against sum when metric='sum'
            } else {
                $agg = $q->selectRaw('' . $q->getModel()->getTable() . '.student_id as sid, COUNT(*) as agg_value')
                    ->groupBy('sid')
                    ->pluck('agg_value', 'sid')
                    ->toArray();
                $counts = $agg; // threshold is applied against count when metric='count'
            }

            // ---- 4) If timeframe is 'today', prefetch per-item rows for email body ----
            $itemsByStudent = [];
            if ($timeframe === 'today' && !empty($counts)) {
                $studentIds = array_map('intval', array_keys($counts));

                $itemCols = ['record_id as id', 'student_id', 'category', 'date', 'points'];
                // Reason is only on demerits; include when available
                if ($source === 'demerit') {
                    $itemCols[] = 'reason';
                }

                $itemsQ = ($source === 'demerit' ? Demerit::query()
                    : ($source === 'intervention' ? Intervention::query() : \App\Models\Point::query()));

                $this->applyStatusExclusions($itemsQ, $itemsQ->getModel()::class);
                $this->applyWindowFilter($itemsQ, $window, $dateColumn);

                if ($catsArray && count($catsArray)) {
                    $itemsQ->whereIn('category', $catsArray);
                } elseif (!empty($rule->category)) {
                    $itemsQ->where('category', $rule->category);
                }

                $itemsQ->whereIn('student_id', $studentIds)
                    ->selectRaw(implode(',', $itemCols))
                    ->orderBy('student_id')
                    ->orderBy('date');

                foreach ($itemsQ->get() as $row) {
                    $itemsByStudent[(int) $row->student_id][] = [
                        'id'       => (int) $row->id,
                        'at'       => Carbon::parse($row->date)->format('Y-m-d H:i'),
                        'category' => $row->category,
                        'points'   => (int) $row->points,
                        'reason'   => $source === 'demerit' ? ($row->reason ?? null) : null,
                    ];
                }
            }

            // ---- 5) Emit logs (idempotent per (rule, student, exact window)) ---
            foreach ($counts as $studentId => $value) {
                $threshold = (int) $rule->threshold;

                // Only act if this threshold is reached
                if ((int) $value < $threshold) {
                    continue;
                }

                // Idempotency check (today handled explicitly here)
                $exists = false;
                if ($timeframe === 'today') {
                    $exists = BehaviourNotificationLog::query()
                        ->where('rule_id', $rule->id)
                        ->where('student_id', (int) $studentId)
                        ->where('hit_threshold', $threshold)
                        ->where('window_type', 'today')
                        ->whereDate('window_start_date', $window['start'])
                        ->whereDate('window_end_date', $window['end'])
                        ->exists();
                } else {
                    $exists = $this->alreadyLogged($rule->id, (int) $studentId, $threshold, $window, $timeframe);
                }
                if ($exists) {
                    continue;
                }

                // Build snapshot (when 'today', include per-item details)

                $snapshot = [
                    'source'   => $source,
                    'metric'   => $metric,            // 'count' | 'sum'
                    'category' => $catsArray ?: ($rule->category ?? null),
                    'value'    => (int) $value,
                    'as_of'    => $asOf->copy()->startOfDay()->toDateTimeString(),
                    'rule'     => [
                        'id'        => $rule->id,
                        'threshold' => $threshold,
                    ],
                ];
                if ($timeframe === 'today') {
                    $snapshot['items'] = $itemsByStudent[(int) $studentId] ?? [];
                }

                BehaviourNotificationLog::create([
                    'rule_id'    => $rule->id,
                    'student_id' => (int) $studentId,

                    'source'        => $source,
                    'category'      => is_array($snapshot['category']) ? json_encode($snapshot['category']) : ($snapshot['category'] ?? null),
                    'hit_threshold' => $threshold,

                    'window_type'                 => $timeframe, // 'term' | 'rolling_school_days' | 'today'
                    'window_start_date'           => $window['start'],
                    'window_end_date'             => $window['end'],
                    'window_term_id'              => $window['term_id'] ?? null,
                    'window_school_days_lookback' => $window['lookback'] ?? null,

                    'source_snapshot'     => $snapshot,
                    'recipients_snapshot' => [], // populated when sending
                ]);

                $created++;
            }
        }

        return $created;
    }

    /**
     * Return [student_id => count] for this rule within the given window.
     * $window = ['start'=>Carbon,'end'=>Carbon,'term_id'?=>int,'lookback'?=>int,'dates'?=>array<Y-m-d>]
     */
    protected function countsForRule(BehaviourNotificationRule $rule, array $window): array
    {
        $source   = $rule->source;     // 'demerit' | 'intervention'
        $category = $rule->category;   // epraise label

        if ($source === 'demerit') {
            $q = Demerit::query()->where('category', $category);
            $this->applyStatusExclusions($q, Demerit::class);
            $this->applyWindowFilter($q, $window, 'date');

            // Optional: constrain to known students (your Demerit.student_id stores epraise record_id)
            $q->whereIn('student_id', Student::query()->pluck('record_id'));

            return $q->selectRaw('student_id, COUNT(*) as c')
                ->groupBy('student_id')
                ->pluck('c', 'student_id')
                ->toArray();
        }

        if ($source === 'intervention') {
            $q = Intervention::query()->where('intervention_type', $category);
            $this->applyStatusExclusions($q, Intervention::class);
            $this->applyWindowFilter($q, $window, 'date_added');

            $q->whereIn('student_id', Student::query()->pluck('record_id'));

            return $q->selectRaw('student_id, COUNT(*) as c')
                ->groupBy('student_id')
                ->pluck('c', 'student_id')
                ->toArray();
        }

        return [];
    }

    /**
     * Exclude locally deleted/partially-deleted rows (we count *local* data only).
     */
    protected function applyStatusExclusions(Builder $q, string $modelClass): void
    {
        $exclude = [];

        if (defined($modelClass . '::STATUS_DELETED')) {
            $exclude[] = constant($modelClass . '::STATUS_DELETED');
        }
        if (defined($modelClass . '::STATUS_PARTIALLY_DELETED')) {
            $exclude[] = constant($modelClass . '::STATUS_PARTIALLY_DELETED');
        }

        if ($exclude) {
            $q->whereNotIn('sync_status', $exclude);
        }
    }

    /**
     * Apply a date window to a query against $dateColumn.
     * If $window has 'dates' (rolling school days), also filter by those exact dates.
     */
    protected function applyWindowFilter(Builder $q, array $window, string $dateColumn): void
    {
        $q->whereBetween($dateColumn, [
            $window['start']->copy()->startOfDay(),
            $window['end']->copy()->endOfDay(),
        ]);

        if (!empty($window['dates']) && is_array($window['dates'])) {
            $q->where(function ($qq) use ($dateColumn, $window) {
                foreach ($window['dates'] as $d) {
                    $qq->orWhereDate($dateColumn, '=', $d);
                }
            });
        }
    }


    /**
     * Prevent duplicate logs for the same (rule, student, window, threshold).
     */
    protected function alreadyLogged(int $ruleId, int $studentId, int $threshold, array $window, string $timeframe): bool
    {
        $q = BehaviourNotificationLog::query()
            ->where('rule_id', $ruleId)
            ->where('student_id', $studentId)
            ->where('hit_threshold', $threshold)
            ->whereDate('window_start_date', $window['start'])
            ->whereDate('window_end_date', $window['end']);

        if ($timeframe === 'term') {
            $q->where('window_type', 'term')
                ->where('window_term_id', $window['term_id'] ?? null);
        } else {
            $q->where('window_type', 'rolling_school_days')
                ->where('window_school_days_lookback', $window['lookback'] ?? null);
        }

        return $q->exists();
    }

    /**
     * Current term window for $asOf (start/end are school days).
     * Returns ['start'=>Carbon,'end'=>Carbon,'term_id'=>int] or null.
     */
    protected function resolveTermWindow(Carbon $asOf): ?array
    {
        $pivot = AcademicCalendarDate::query()
            ->where('is_school_day', true)
            ->whereDate('date', '<=', $asOf->toDateString())
            ->orderBy('date', 'desc')
            ->first();

        if (!$pivot || !$pivot->term_id) {
            return null;
        }

        $termId = (int) $pivot->term_id;

        $start = AcademicCalendarDate::query()
            ->where('is_school_day', true)
            ->where('term_id', $termId)
            ->orderBy('date', 'asc')
            ->value('date');

        $end = AcademicCalendarDate::query()
            ->where('is_school_day', true)
            ->where('term_id', $termId)
            ->orderBy('date', 'desc')
            ->value('date');

        if (!$start || !$end) {
            return null;
        }

        return [
            'start'   => Carbon::parse($start)->startOfDay(),
            'end'     => Carbon::parse($end)->startOfDay(),
            'term_id' => $termId,
        ];
    }

    /**
     * Last N school days window (including $asOf), plus exact date list.
     * Returns ['start'=>Carbon,'end'=>Carbon,'lookback'=>int,'dates'=>string[]] or null.
     */
    protected function resolveRollingWindow(Carbon $asOf, int $lookback): ?array
    {
        $dates = AcademicCalendarDate::query()
            ->where('is_school_day', true)
            ->whereDate('date', '<=', $asOf->toDateString())
            ->orderBy('date', 'desc')
            ->limit($lookback)
            ->pluck('date')     // Carbon instances (model cast)
            ->filter()
            ->values()
            ->reverse()
            ->values();

        if ($dates->isEmpty()) {
            return null;
        }

        $start = $dates->first()->copy()->startOfDay();
        $end   = $dates->last()->copy()->startOfDay();

        return [
            'start'    => $start,
            'end'      => $end,
            'lookback' => $lookback,
            'dates'    => $dates->map(fn($d) => $d->toDateString())->all(),
        ];
    }
}
