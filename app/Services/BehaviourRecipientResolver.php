<?php

namespace App\Services;

use App\Models\Student;
use App\Models\BehaviourRuleGroup;
use App\Models\BehaviourRuleLevel;
use App\Models\BehaviourNotificationRule;
use App\Models\BehaviourNotificationStudentRouting;
use App\Models\BehaviourNotificationParentContact;

class BehaviourRecipientResolver
{
    /**
     * Resolve recipients for a given student + rule.
     *
     * Returns:
     * [
     *   'staff'   => ['staff1@school.tld', 'staff2@school.tld', ...],   // single email to all staff
     *   'parents' => [
     *       ['email' => 'p1@example.com', 'title' => 'Mr', 'surname' => 'Smith'],
     *       ['email' => 'p2@example.com', 'title' => 'Ms', 'surname' => 'Jones'],
     *   ],
     *   'meta'    => [ ... debug info ... ],
     * ]
     */
    public function resolveForRule(string|int $studentRecordId, BehaviourNotificationRule $rule): array
    {
        return $this->resolveRecipients(
            (string) $studentRecordId,
            (bool) $rule->recipient_staff,
            (bool) $rule->recipient_parents,
            is_array($rule->staff_roles) ? $rule->staff_roles : [],
            is_array($rule->extra_emails) ? $rule->extra_emails : [],
            [
                'rule_id' => $rule->id,
                'roles_used' => is_array($rule->staff_roles) ? $rule->staff_roles : [],
                'extra_emails_added' => is_array($rule->extra_emails) ? $rule->extra_emails : [],
            ]
        );
    }

    public function resolveForGroupLevel(string|int $studentRecordId, BehaviourRuleGroup $group, BehaviourRuleLevel $level): array
    {
        $levelRoles = is_array($level->staff_roles) ? $level->staff_roles : [];
        $groupRoles = is_array($group->staff_roles) ? $group->staff_roles : [];
        $roles = !empty($levelRoles) ? $levelRoles : $groupRoles;

        $groupExtras = is_array($group->extra_emails) ? $group->extra_emails : [];
        $levelExtras = is_array($level->extra_emails) ? $level->extra_emails : [];
        $extraEmails = array_merge($groupExtras, $levelExtras);

        return $this->resolveRecipients(
            (string) $studentRecordId,
            (bool) $level->recipient_staff,
            (bool) $level->recipient_parents,
            $roles,
            $extraEmails,
            [
                'group_id' => $group->id,
                'level_id' => $level->id,
                'roles_used' => $roles,
                'extra_emails_added' => $extraEmails,
            ]
        );
    }

    // -----------------------------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------------------------

    private function normalizeEmails(array $emails): array
    {
        $out = [];
        foreach ($emails as $e) {
            $norm = $this->normalizeEmail($e);
            if ($norm) $out[] = $norm;
        }
        // Dedupe and reindex
        return array_values(array_unique($out));
    }

    private function normalizeEmail(?string $email): ?string
    {
        $email = $email ? trim(mb_strtolower($email)) : null;
        if (!$email) return null;
        // very light sanity check
        return str_contains($email, '@') ? $email : null;
    }

    private function normalizeRole(?string $role): ?string
    {
        if (!$role) return null;
        $role = mb_strtolower(trim($role));
        $role = str_replace(' ', '_', $role);
        if ($role === 'hoy') {
            $role = 'head_of_year';
        }
        return in_array($role, ['tutor', 'head_of_year'], true) ? $role : null;
    }

    /**
     * Resolve year-group fallback email(s) for staff notifications.
     */
    private function yearGroupFallbackEmails(string|int $studentRecordId): array
    {
        $yearGroup = Student::where('record_id', (string) $studentRecordId)->value('year_group');
        if (!$yearGroup) {
            return [];
        }

        $map = (array) config('behaviour_notifications.year_group_fallback_emails', []);
        if (isset($map[$yearGroup])) {
            return [$map[$yearGroup]];
        }

        $normalized = $this->normalizeYearGroupKey($yearGroup);
        foreach ($map as $key => $email) {
            if ($this->normalizeYearGroupKey($key) === $normalized) {
                return [$email];
            }
        }

        $pattern = (string) config('behaviour_notifications.year_group_fallback_pattern', '');
        if ($pattern && preg_match('/(\\d+)/', $yearGroup, $m)) {
            $year = $m[1];
            return [str_replace('{year}', $year, $pattern)];
        }

        return [];
    }

    private function normalizeYearGroupKey(string $value): string
    {
        return preg_replace('/\\s+/', '', mb_strtolower(trim($value))) ?? '';
    }

    /**
     * Resolve recipients using a role + email config.
     * @param array<int,string> $roles
     * @param array<int,string> $extraEmails
     */
    private function resolveRecipients(
        string $studentRecordId,
        bool $recipientStaff,
        bool $recipientParents,
        array $roles,
        array $extraEmails,
        array $metaExtras
    ): array {
        // ---------- Locate routing row (record_id primary, fall back via arbor_id) ----------
        $routing = BehaviourNotificationStudentRouting::forStudentRecord($studentRecordId)->first();

        $usedArborId   = null;
        $fallbackUsed  = false;

        if (!$routing) {
            $usedArborId = Student::where('record_id', $studentRecordId)->value('arbor_id');
            if ($usedArborId) {
                $routing = BehaviourNotificationStudentRouting::forStudentArbor((string) $usedArborId)->first();
                $fallbackUsed = (bool) $routing;
            }
        } else {
            $usedArborId = $routing->student_arbor_id;
        }

        // ---------- STAFF ----------
        $staffEmails = [];
        if ($recipientStaff) {
            $roles = array_values(array_filter(array_map(fn($r) => $this->normalizeRole($r), $roles)));

            // Default to tutor + head_of_year when no roles specified.
            if (empty($roles)) {
                $roles = ['tutor', 'head_of_year'];
            }

            $tutorEmails = $routing?->tutor_emails ?? [];
            $hoyEmails = $routing?->hoy_emails ?? [];
            $accum = [];

            $wantsTutor = in_array('tutor', $roles, true);
            $wantsHoy = in_array('head_of_year', $roles, true);

            if ($wantsTutor) {
                $accum = array_merge($accum, $tutorEmails);
                if (empty($tutorEmails)) {
                    $accum = array_merge($accum, $hoyEmails);
                    if (empty($hoyEmails)) {
                        $accum = array_merge($accum, $this->yearGroupFallbackEmails($studentRecordId));
                    }
                }
            }

            if ($wantsHoy) {
                $accum = array_merge($accum, $hoyEmails);
                if (empty($hoyEmails)) {
                    $accum = array_merge($accum, $this->yearGroupFallbackEmails($studentRecordId));
                }
            }

            $accum = array_merge($accum, $routing?->extra_staff_emails ?? []);
            $staffEmails = $accum;

            if (!empty($extraEmails)) {
                $staffEmails = array_merge($staffEmails, $extraEmails);
            }

            // Normalize & dedupe
            $staffEmails = $this->normalizeEmails($staffEmails);
        }

        // ---------- PARENTS ----------
        $parentRecipients = [];
        if ($recipientParents) {
            // Parents are looked up by ARBOR/UPN id
            $arborId = $usedArborId ?: Student::where('record_id', $studentRecordId)->value('arbor_id');

            if ($arborId) {
                $contacts = BehaviourNotificationParentContact::query()
                    ->where('student_arbor_id', (string) $arborId)
                    ->get(['email', 'title', 'surname']);

                foreach ($contacts as $c) {
                    $email = $this->normalizeEmail($c->email);
                    if ($email) {
                        // keep title/surname for templating; each parent gets their own email
                        $parentRecipients[] = [
                            'email'   => $email,
                            'title'   => $c->title,
                            'surname' => $c->surname,
                        ];
                    }
                }

                // Dedupe by email while preserving title/surname; parents can share email across records.
                $parentRecipients = $this->uniqueByEmail($parentRecipients);
            }
        }

        return [
            'staff'   => $staffEmails,
            'parents' => $parentRecipients,
            'meta'    => array_merge([
                'routing_row_id' => $routing?->id,
                'student_record_id' => $studentRecordId,
                'used_arbor_id'  => $usedArborId,
                'fallback_used'  => $fallbackUsed,
            ], $metaExtras),
        ];
    }
    /**
     * Deduplicate parent recipients by email while keeping the first title/surname seen.
     * @param array<int,array{email:string,title:?string,surname:?string}> $rows
     * @return array<int,array{email:string,title:?string,surname:?string}>
     */
    private function uniqueByEmail(array $rows): array
    {
        $seen = [];
        $out  = [];
        foreach ($rows as $r) {
            $key = $r['email'];
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[] = $r;
        }
        return $out;
    }
}
