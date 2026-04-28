<?php

namespace App\Services;

use App\Models\AcademicCalendarDate;
use App\Models\BehaviourNotificationLog;
use App\Models\BehaviourNotificationRule;
use App\Models\BehaviourRuleGroup;
use App\Models\Demerit;
use App\Models\Intervention;
use App\Models\Point;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class BehaviourNotificationEvaluator
{
    /**
     * Evaluate active groups + rules as of $asOf (you’ll usually pass “yesterday” at 8am),
     * create a log row for any threshold newly hit, and return how many logs were created.
     */
    public function evaluateAndLog(Carbon $asOf, bool $persist = true): int
    {
        $created = 0;

        $created += $this->evaluateGroupsAndLog($asOf, $persist);

        if (!config('behaviour_notifications.enable_legacy_rules', false)) {
            return $created;
        }

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
                $categoryColumn = 'category';
            } elseif ($source === 'point' || $source === 'points') {
                $q = \App\Models\Point::query();
                $dateColumn = 'date';
                $categoryColumn = 'category';
            } elseif ($source === 'intervention') {
                $q = Intervention::query();
                $dateColumn = 'date_added';
                $categoryColumn = 'intervention_type';
            } else {
                continue; // unknown source
            }

            // Exclude locally DELETED / PARTIALLY_DELETED etc.
            $this->applyStatusExclusions($q, $q->getModel()::class);

            // Window filter (+ exact dates if provided)
            $this->applyWindowFilter($q, $window, $dateColumn);

            $cats = $rule->categoriesOrSingle();
            if (!empty($cats)) {
                $q->whereIn($categoryColumn, $cats);
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

                if (!empty($cats)) {
                    $itemsQ->whereIn('category', $cats);
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
                    'category' => $cats,
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

                if ($persist) {
                    BehaviourNotificationLog::create([
                        'rule_id'    => $rule->id,
                        'student_id' => (int) $studentId,

                        'source'        => $source,
                        'category'      => $this->categoryForLogColumn($snapshot['category'] ?? null),
                        'hit_threshold' => $threshold,

                        'window_type'                 => $timeframe, // 'term' | 'rolling_school_days' | 'today'
                        'window_start_date'           => $window['start'],
                        'window_end_date'             => $window['end'],
                        'window_term_id'              => $window['term_id'] ?? null,
                        'window_school_days_lookback' => $window['lookback'] ?? null,

                        'source_snapshot'     => $snapshot,
                        'recipients_snapshot' => [], // populated when sending
                    ]);
                }

                $created++;
            }
        }

        return $created;
    }

    /**
     * Evaluate grouped rules (behaviour_rule_groups + levels).
     */
    protected function evaluateGroupsAndLog(Carbon $asOf, bool $persist = true): int
    {
        $created = 0;

        $groups = BehaviourRuleGroup::query()
            ->where('is_active', true)
            ->orderBy('source')
            ->orderBy('id')
            ->get();

        if ($groups->isEmpty()) {
            return 0;
        }

        $knownStudentIds = Student::query()->pluck('record_id');

        foreach ($groups as $group) {
            $levels = $group->levels()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('threshold')
                ->get();

            if ($levels->isEmpty()) {
                continue;
            }

            $mode = $group->mode ?? 'daily_batch';
            $metric = $group->metric ?? 'count';
            $counterMode = ($group->counter_mode ?? 'cumulative') === 'rollover' ? 'rollover' : 'cumulative';
            $rolloverEvery = (int) ($group->rollover_every ?? 0);
            $useRollover = $counterMode === 'rollover' && $rolloverEvery > 0;
            $source = $group->source;

            if ($source === 'intervention') {
                $metric = 'count';
            }

            $categories = is_array($group->categories)
                ? array_values(array_filter($group->categories, fn($v) => $v !== null && $v !== ''))
                : [];
            if (empty($categories)) {
                continue;
            }

            $yearGroups = is_array($group->year_groups ?? null)
                ? array_values(array_filter($group->year_groups, fn($v) => $v !== null && $v !== ''))
                : null;

            $timeframe = ($mode === 'realtime_today') ? 'today' : ($group->timeframe ?? 'rolling_school_days');

            $window = $this->resolveGroupWindow($timeframe, $asOf, (int) ($group->rolling_days ?? 10));
            if (!$window) {
                continue;
            }

            if ($source === 'demerit') {
                $q = Demerit::query();
                $dateColumn = 'date';
                $categoryColumn = 'category';
            } elseif ($source === 'point' || $source === 'points') {
                $q = Point::query();
                $dateColumn = 'date';
                $categoryColumn = 'category';
            } elseif ($source === 'intervention') {
                $q = Intervention::query();
                $dateColumn = 'date_added';
                $categoryColumn = 'intervention_type';
            } else {
                continue;
            }

            $this->applyStatusExclusions($q, $q->getModel()::class);
            $this->applyWindowFilter($q, $window, $dateColumn);
            $q->whereIn($categoryColumn, $categories);

            if ($yearGroups && count($yearGroups)) {
                $q->join('students', 'students.record_id', '=', $q->getModel()->getTable() . '.student_id')
                    ->whereIn('students.year_group', $yearGroups);
            }

            $q->whereIn($q->getModel()->getTable() . '.student_id', $knownStudentIds);

            if ($metric === 'sum') {
                $sumExpr = ($source === 'demerit') ? 'SUM(ABS(points))' : 'SUM(points)';
                $counts = $q->selectRaw($q->getModel()->getTable() . '.student_id as sid, ' . $sumExpr . ' as agg_value')
                    ->groupBy('sid')
                    ->pluck('agg_value', 'sid')
                    ->toArray();
            } else {
                $counts = $q->selectRaw($q->getModel()->getTable() . '.student_id as sid, COUNT(*) as agg_value')
                    ->groupBy('sid')
                    ->pluck('agg_value', 'sid')
                    ->toArray();
            }

            $itemsByStudent = [];
            if ($timeframe === 'today' && !empty($counts)) {
                $studentIds = array_map('intval', array_keys($counts));

                if ($source === 'demerit') {
                    $itemsQ = Demerit::query();
                    $table = $itemsQ->getModel()->getTable();
                    $itemCols = [
                        "{$table}.record_id as id",
                        "{$table}.student_id",
                        "{$table}.category",
                        "{$table}.date",
                        "{$table}.points",
                        "{$table}.reason",
                    ];
                } elseif ($source === 'point' || $source === 'points') {
                    $itemsQ = Point::query();
                    $table = $itemsQ->getModel()->getTable();
                    $itemCols = [
                        "{$table}.record_id as id",
                        "{$table}.student_id",
                        "{$table}.category",
                        "{$table}.date",
                        "{$table}.points",
                    ];
                } else {
                    $itemsQ = Intervention::query();
                    $table = $itemsQ->getModel()->getTable();
                    $itemCols = [
                        "{$table}.record_id as id",
                        "{$table}.student_id",
                        "{$table}.intervention_type as category",
                        "{$table}.date_added as date",
                        "{$table}.comment as reason",
                    ];
                }

                $this->applyStatusExclusions($itemsQ, $itemsQ->getModel()::class);
                $this->applyWindowFilter($itemsQ, $window, $dateColumn);
                $itemsQ->whereIn($categoryColumn, $categories);

                if ($yearGroups && count($yearGroups)) {
                    $itemsQ->join('students', 'students.record_id', '=', $itemsQ->getModel()->getTable() . '.student_id')
                        ->whereIn('students.year_group', $yearGroups);
                }

                $itemsQ->whereIn($itemsQ->getModel()->getTable() . '.student_id', $studentIds)
                    ->selectRaw(implode(',', $itemCols))
                    ->orderBy($itemsQ->getModel()->getTable() . '.student_id')
                    ->orderBy($itemsQ->getModel()->getTable() . '.' . $dateColumn);

                foreach ($itemsQ->get() as $row) {
                    $points = $row->points ?? null;
                    $itemsByStudent[(int) $row->student_id][] = [
                        'id'       => (int) $row->id,
                        'at'       => Carbon::parse($row->date)->format('Y-m-d H:i'),
                        'category' => $row->category,
                        'points'   => is_numeric($points) ? (int) $points : null,
                        'reason'   => $row->reason ?? null,
                    ];
                }
            }

            [$existingByStudent, $maxLoggedByStudent] = $this->existingGroupLogsForWindow(
                (int) $group->id,
                $window,
                $timeframe,
                $useRollover
            );

            $firstRun = $group->last_evaluated_at === null;
            $muteLowerOnFirstRun = (bool) ($group->mute_lower_on_first_run ?? false);

            foreach ($counts as $studentId => $value) {
                $sid = (int) $studentId;
                $rawValue = max(0, (int) $value);
                ['effective' => $effectiveValue, 'cycle' => $counterCycle] = $this->resolveGroupCounterState(
                    $rawValue,
                    $useRollover,
                    $rolloverEvery
                );

                $hitLevels = $levels->filter(fn($level) => (int) $level->threshold <= $effectiveValue)->values();
                if ($hitLevels->isEmpty()) {
                    continue;
                }

                $cycleKey = $useRollover ? $counterCycle : 0;
                $snapshotBase = [
                    'source'      => $source,
                    'metric'      => $metric,
                    'category'    => $categories,
                    'value'       => $effectiveValue,
                    'raw_value'   => $rawValue,
                    'cycle_value' => $effectiveValue,
                    'counter_mode' => $counterMode,
                    'counter_cycle' => $useRollover ? $counterCycle : null,
                    'rollover_every' => $useRollover ? $rolloverEvery : null,
                    'counter'     => [
                        'mode'          => $counterMode,
                        'raw_value'     => $rawValue,
                        'effective_value' => $effectiveValue,
                        'cycle'         => $useRollover ? $counterCycle : null,
                        'rollover_every' => $useRollover ? $rolloverEvery : null,
                    ],
                    'as_of'       => $asOf->copy()->startOfDay()->toDateTimeString(),
                    'year_groups' => $yearGroups,
                    'group'       => [
                        'id'       => $group->id,
                        'name'     => $group->name,
                        'mode'     => $group->mode,
                        'timeframe' => $timeframe,
                    ],
                ];
                if ($timeframe === 'today') {
                    $snapshotBase['items'] = $itemsByStudent[$sid] ?? [];
                }
                if (!empty($window['year_id'])) {
                    $snapshotBase['window_year_id'] = $window['year_id'];
                }

                if ($mode !== 'realtime_today' || ($firstRun && $muteLowerOnFirstRun)) {
                    $level = $hitLevels->last();
                    $levelId = (int) $level->id;
                    if (!empty($existingByStudent[$sid][$cycleKey][$levelId])) {
                        continue;
                    }

                    $snapshot = $snapshotBase;
                    $snapshot['level'] = [
                        'id'        => $level->id,
                        'threshold' => (int) $level->threshold,
                    ];

                    if ($persist) {
                        BehaviourNotificationLog::create([
                            'rule_id' => null,
                            'behaviour_rule_group_id' => $group->id,
                            'behaviour_rule_level_id' => $level->id,
                            'student_id' => $sid,
                            'source' => $source,
                            'category' => $this->categoryForLogColumn($categories),
                            'hit_threshold' => (int) $level->threshold,
                            'counter_cycle' => $useRollover ? $counterCycle : null,
                            'window_type' => $timeframe,
                            'window_start_date' => $window['start'],
                            'window_end_date' => $window['end'],
                            'window_term_id' => $window['term_id'] ?? null,
                            'window_school_days_lookback' => $window['lookback'] ?? null,
                            'source_snapshot' => $snapshot,
                            'recipients_snapshot' => [],
                        ]);
                    }

                    $existingByStudent[$sid][$cycleKey][$levelId] = true;
                    $maxLoggedByStudent[$sid][$cycleKey] = max($maxLoggedByStudent[$sid][$cycleKey] ?? 0, (int) $level->threshold);
                    $created++;
                    continue;
                }

                $maxLogged = $maxLoggedByStudent[$sid][$cycleKey] ?? null;
                foreach ($hitLevels as $level) {
                    $levelId = (int) $level->id;
                    $threshold = (int) $level->threshold;

                    if ($maxLogged !== null && $threshold <= $maxLogged) {
                        continue;
                    }
                    if (!empty($existingByStudent[$sid][$cycleKey][$levelId])) {
                        continue;
                    }

                    $snapshot = $snapshotBase;
                    $snapshot['level'] = [
                        'id'        => $level->id,
                        'threshold' => $threshold,
                    ];

                    if ($persist) {
                        BehaviourNotificationLog::create([
                            'rule_id' => null,
                            'behaviour_rule_group_id' => $group->id,
                            'behaviour_rule_level_id' => $level->id,
                            'student_id' => $sid,
                            'source' => $source,
                            'category' => $this->categoryForLogColumn($categories),
                            'hit_threshold' => $threshold,
                            'counter_cycle' => $useRollover ? $counterCycle : null,
                            'window_type' => $timeframe,
                            'window_start_date' => $window['start'],
                            'window_end_date' => $window['end'],
                            'window_term_id' => $window['term_id'] ?? null,
                            'window_school_days_lookback' => $window['lookback'] ?? null,
                            'source_snapshot' => $snapshot,
                            'recipients_snapshot' => [],
                        ]);
                    }

                    $existingByStudent[$sid][$cycleKey][$levelId] = true;
                    $maxLogged = $threshold;
                    $maxLoggedByStudent[$sid][$cycleKey] = $threshold;
                    $created++;
                }
            }

            if ($persist) {
                $group->forceFill(['last_evaluated_at' => now()])->save();
            }
        }

        return $created;
    }

    /**
     * Resolve a date window for group evaluation.
     */
    protected function resolveGroupWindow(string $timeframe, Carbon $asOf, int $rollingDays): ?array
    {
        if ($timeframe === 'term') {
            return $this->resolveTermWindow($asOf);
        }

        if ($timeframe === 'rolling_school_days') {
            $yesterday = $asOf->copy()->subDay();
            return $this->resolveRollingWindow($yesterday, $rollingDays);
        }

        if ($timeframe === 'academic_year') {
            return $this->resolveAcademicYearWindow($asOf);
        }

        $todayRow = AcademicCalendarDate::query()
            ->where('is_school_day', true)
            ->whereDate('date', $asOf->toDateString())
            ->first();

        if (!$todayRow) {
            return null;
        }

        $d = Carbon::parse($todayRow->date)->startOfDay();
        return [
            'start' => $d,
            'end'   => $d->copy()->endOfDay(),
            'dates' => [$d->toDateString()],
        ];
    }

    /**
     * Academic year window for $asOf (school days only).
     * Returns ['start'=>Carbon,'end'=>Carbon,'year_id'=>int,'dates'=>string[]] or null.
     */
    protected function resolveAcademicYearWindow(Carbon $asOf): ?array
    {
        $pivot = AcademicCalendarDate::query()
            ->where('is_school_day', true)
            ->whereDate('date', '<=', $asOf->toDateString())
            ->orderBy('date', 'desc')
            ->first();

        if (!$pivot || !$pivot->academic_year_id) {
            return null;
        }

        $yearId = (int) $pivot->academic_year_id;

        $dates = AcademicCalendarDate::query()
            ->where('is_school_day', true)
            ->where('academic_year_id', $yearId)
            ->orderBy('date', 'asc')
            ->pluck('date')
            ->filter()
            ->values();

        if ($dates->isEmpty()) {
            return null;
        }

        $start = $dates->first()->copy()->startOfDay();
        $end   = $dates->last()->copy()->startOfDay();

        return [
            'start'   => $start,
            'end'     => $end,
            'year_id' => $yearId,
            'dates'   => $dates->map(fn($d) => $d->toDateString())->all(),
        ];
    }

    /**
     * Prefetch existing group logs for a given window.
     * @return array{0: array<int,array<int,array<int,bool>>>, 1: array<int,array<int,int>>}
     */
    protected function existingGroupLogsForWindow(int $groupId, array $window, string $timeframe, bool $useCounterCycle): array
    {
        $q = BehaviourNotificationLog::query()
            ->where('behaviour_rule_group_id', $groupId)
            ->where('window_type', $timeframe)
            ->whereDate('window_start_date', $window['start'])
            ->whereDate('window_end_date', $window['end']);

        if ($timeframe === 'term') {
            $q->where('window_term_id', $window['term_id'] ?? null);
        } elseif ($timeframe === 'rolling_school_days') {
            $q->where('window_school_days_lookback', $window['lookback'] ?? null);
        }

        $rows = $q->get(['student_id', 'behaviour_rule_level_id', 'hit_threshold', 'counter_cycle']);

        $byStudent = [];
        $maxThreshold = [];
        foreach ($rows as $row) {
            $sid = (int) $row->student_id;
            $cycle = $useCounterCycle ? (int) ($row->counter_cycle ?? 0) : 0;
            $levelId = $row->behaviour_rule_level_id ? (int) $row->behaviour_rule_level_id : null;
            if ($levelId) {
                $byStudent[$sid][$cycle][$levelId] = true;
            }
            $thr = (int) $row->hit_threshold;
            if (!isset($maxThreshold[$sid][$cycle]) || $thr > $maxThreshold[$sid][$cycle]) {
                $maxThreshold[$sid][$cycle] = $thr;
            }
        }

        return [$byStudent, $maxThreshold];
    }

    /**
     * Resolve the effective value used for thresholds and its rollover cycle.
     * @return array{effective:int,cycle:int}
     */
    protected function resolveGroupCounterState(int $rawValue, bool $useRollover, int $rolloverEvery): array
    {
        if (!$useRollover || $rolloverEvery < 1) {
            return [
                'effective' => max(0, $rawValue),
                'cycle' => 0,
            ];
        }

        if ($rawValue <= 0) {
            return [
                'effective' => 0,
                'cycle' => 1,
            ];
        }

        return [
            'effective' => (($rawValue - 1) % $rolloverEvery) + 1,
            'cycle' => intdiv($rawValue - 1, $rolloverEvery) + 1,
        ];
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
     * Keep category text safe for legacy VARCHAR(255) schema while preserving full detail in snapshot.
     */
    protected function categoryForLogColumn(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            $items = array_values(array_filter(array_map(
                static fn ($v) => is_scalar($v) ? trim((string) $v) : null,
                $value
            )));

            if (empty($items)) {
                return null;
            }

            $json = json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($json) && strlen($json) <= 255) {
                return $json;
            }

            $summary = 'Multiple categories (' . count($items) . '): ' . implode(', ', array_slice($items, 0, 3));
            return strlen($summary) > 255 ? substr($summary, 0, 255) : $summary;
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        return strlen($text) > 255 ? substr($text, 0, 255) : $text;
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
