<?php

namespace App\Console\Commands;

use App\Models\BehaviourNotificationRule;
use App\Models\BehaviourRuleGroup;
use App\Models\BehaviourRuleLevel;
use Illuminate\Console\Command;

class MigrateBehaviourRulesToGroupsCommand extends Command
{
    protected $signature = 'behaviour:migrate-rules-to-groups {--dry-run : Show what would change without writing} {--deactivate-legacy : Disable legacy rules after migration}';

    protected $description = 'Migrate legacy behaviour notification rules into rule groups + levels.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $deactivateLegacy = (bool) $this->option('deactivate-legacy');

        $existingGroups = BehaviourRuleGroup::query()->get();
        $existingByKey = [];
        foreach ($existingGroups as $existing) {
            $keyData = [
                'source' => $existing->source,
                'categories' => $this->normalizeList(is_array($existing->categories) ? $existing->categories : []),
                'timeframe' => $existing->timeframe ?? 'rolling_school_days',
                'rolling_days' => $existing->rolling_days,
                'metric' => $existing->metric ?? 'count',
                'counter_mode' => $existing->counter_mode ?? 'cumulative',
                'rollover_every' => $existing->rollover_every,
                'mode' => $existing->mode ?? 'daily_batch',
                'reset_on_term_change' => (bool) ($existing->reset_on_term_change ?? true),
                'year_groups' => $this->normalizeList(is_array($existing->year_groups) ? $existing->year_groups : []),
            ];
            $existingByKey[md5(json_encode($keyData))] = $existing;
        }

        $rules = BehaviourNotificationRule::query()
            ->orderBy('source')
            ->orderBy('category')
            ->orderBy('threshold')
            ->get();

        if ($rules->isEmpty()) {
            $this->info('No legacy behaviour_notification_rules found.');
            return self::SUCCESS;
        }

        $buckets = [];
        foreach ($rules as $rule) {
            $categories = $this->normalizeList($rule->categoriesOrSingle());
            if (empty($categories)) {
                $this->warn("Rule #{$rule->id} has no categories; skipping.");
                continue;
            }

            $yearGroups = $this->normalizeList(is_array($rule->year_groups) ? $rule->year_groups : []);

            $keyData = [
                'source' => $rule->source,
                'categories' => $categories,
                'timeframe' => $rule->timeframe ?? 'rolling_school_days',
                'rolling_days' => $rule->rolling_days,
                'metric' => $rule->metric ?? 'count',
                'counter_mode' => 'cumulative',
                'rollover_every' => null,
                'mode' => $rule->mode ?? 'daily_batch',
                'reset_on_term_change' => (bool) ($rule->reset_on_term_change ?? true),
                'year_groups' => $yearGroups,
            ];
            $bucketKey = md5(json_encode($keyData));

            if (!isset($buckets[$bucketKey])) {
                $buckets[$bucketKey] = [
                    'key' => $keyData,
                    'rules' => [],
                ];
            }
            $buckets[$bucketKey]['rules'][] = $rule;
        }

        $createdGroups = 0;
        $createdLevels = 0;
        $skippedLevels = 0;

        foreach ($buckets as $bucket) {
            $groupKey = $bucket['key'];
            $groupRules = $bucket['rules'];

            $groupName = ucfirst($groupKey['source']) . ' - ' . implode(', ', $groupKey['categories']);

            $groupRecipientsStaff = false;
            $groupRecipientsParents = false;
            $groupStaffRoles = [];
            $groupExtraEmails = [];
            $groupActive = false;

            foreach ($groupRules as $rule) {
                $groupRecipientsStaff = $groupRecipientsStaff || (bool) $rule->recipient_staff;
                $groupRecipientsParents = $groupRecipientsParents || (bool) $rule->recipient_parents;
                $groupActive = $groupActive || (bool) $rule->is_active;

                $groupStaffRoles = array_merge($groupStaffRoles, $this->normalizeRoles(is_array($rule->staff_roles) ? $rule->staff_roles : []));
                $groupExtraEmails = array_merge($groupExtraEmails, is_array($rule->extra_emails) ? $rule->extra_emails : []);
            }

            $groupStaffRoles = $this->normalizeList($groupStaffRoles);
            $groupExtraEmails = $this->normalizeList($groupExtraEmails);

            $group = $existingByKey[md5(json_encode($groupKey))] ?? null;

            if ($dryRun) {
                $this->line('Group: ' . $groupName . ' (' . count($groupRules) . ' level(s))');
            } else {
                if (!$group) {
                    $group = BehaviourRuleGroup::create([
                        'name' => $groupName,
                        'source' => $groupKey['source'],
                        'categories' => $groupKey['categories'],
                        'year_groups' => $groupKey['year_groups'],
                        'timeframe' => $groupKey['timeframe'],
                        'rolling_days' => $groupKey['rolling_days'],
                        'metric' => $groupKey['metric'],
                        'counter_mode' => $groupKey['counter_mode'],
                        'rollover_every' => $groupKey['rollover_every'],
                        'mode' => $groupKey['mode'],
                        'reset_on_term_change' => $groupKey['reset_on_term_change'],
                        'mute_lower_on_first_run' => false,
                        'digest_attach_csv' => false,
                        'digest_fields' => null,
                        'staff_roles' => !empty($groupStaffRoles) ? $groupStaffRoles : null,
                        'extra_emails' => !empty($groupExtraEmails) ? $groupExtraEmails : null,
                        'recipient_staff' => $groupRecipientsStaff,
                        'recipient_parents' => $groupRecipientsParents,
                        'is_active' => $groupActive,
                        'last_evaluated_at' => null,
                    ]);
                    $createdGroups++;
                    $existingByKey[md5(json_encode($groupKey))] = $group;
                }
            }

            $levelOrder = 0;
            foreach ($groupRules as $rule) {
                $levelOrder++;
                if ($dryRun) {
                    $this->line("  - Level: threshold={$rule->threshold}, template={$rule->template_key}");
                    continue;
                }

                $existing = BehaviourRuleLevel::query()
                    ->where('behaviour_rule_group_id', $group->id)
                    ->where('threshold', (int) $rule->threshold)
                    ->first();

                if ($existing) {
                    $skippedLevels++;
                    continue;
                }

                $levelExtraEmails = $this->normalizeList(is_array($rule->extra_emails) ? $rule->extra_emails : []);

                BehaviourRuleLevel::create([
                    'behaviour_rule_group_id' => $group->id,
                    'threshold' => (int) $rule->threshold,
                    'template_key' => (string) $rule->template_key,
                    'recipient_staff' => (bool) $rule->recipient_staff,
                    'recipient_parents' => (bool) $rule->recipient_parents,
                    'staff_roles' => $this->normalizeRoles(is_array($rule->staff_roles) ? $rule->staff_roles : []),
                    'extra_emails' => !empty($levelExtraEmails) ? $levelExtraEmails : null,
                    'is_active' => (bool) $rule->is_active,
                    'sort_order' => $levelOrder,
                ]);
                $createdLevels++;
            }
        }

        if ($dryRun) {
            $this->info('Dry run complete. No changes were written.');
            return self::SUCCESS;
        }

        if ($deactivateLegacy) {
            BehaviourNotificationRule::query()->update(['is_active' => false]);
            $this->info('Legacy rules have been deactivated.');
        }

        $this->info("Created {$createdGroups} group(s), {$createdLevels} level(s). Skipped {$skippedLevels} existing level(s)." );

        return self::SUCCESS;
    }

    /**
     * Normalize string arrays, trim, de-dupe, and sort.
     * @param array<int,string> $values
     * @return array<int,string>
     */
    private function normalizeList(array $values): array
    {
        $values = array_filter(array_map(function ($value) {
            $value = is_string($value) ? trim($value) : '';
            return $value !== '' ? $value : null;
        }, $values));

        $values = array_values(array_unique($values));
        sort($values, SORT_NATURAL | SORT_FLAG_CASE);

        return $values;
    }

    /**
     * Normalize staff roles to supported keys.
     * @param array<int,string> $roles
     * @return array<int,string>
     */
    private function normalizeRoles(array $roles): array
    {
        $normalized = [];
        foreach ($roles as $role) {
            if (!is_string($role)) {
                continue;
            }
            $role = mb_strtolower(trim($role));
            $role = str_replace(' ', '_', $role);
            if ($role === 'hoy') {
                $role = 'head_of_year';
            }
            if (in_array($role, ['tutor', 'head_of_year'], true)) {
                $normalized[] = $role;
            }
        }

        return $this->normalizeList($normalized);
    }
}
