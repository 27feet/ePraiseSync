<?php

namespace App\Services;

use App\Models\Student;
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
        // ---------- Locate routing row (record_id primary, fall back via arbor_id) ----------
        $routing = BehaviourNotificationStudentRouting::forStudentRecord((string) $studentRecordId)->first();

        $usedArborId   = null;
        $fallbackUsed  = false;

        if (!$routing) {
            $usedArborId = Student::where('record_id', (string) $studentRecordId)->value('arbor_id');
            if ($usedArborId) {
                $routing = BehaviourNotificationStudentRouting::forStudentArbor((string) $usedArborId)->first();
                $fallbackUsed = (bool) $routing;
            }
        } else {
            $usedArborId = $routing->student_arbor_id;
        }

        // ---------- STAFF ----------
        $staffEmails = [];
        if ($rule->recipient_staff) {
            $roles = is_array($rule->staff_roles) ? $rule->staff_roles : [];

            // If roles not specified, include all available staff emails from routing.
            if (empty($roles)) {
                $staffEmails = $routing ? $routing->allStaffEmails() : [];
            } else {
                $accum = [];
                foreach ($roles as $role) {
                    $role = mb_strtolower(trim($role));
                    if ($role === 'tutor') {
                        $accum = array_merge($accum, $routing?->tutor_emails ?? []);
                    } elseif ($role === 'head_of_year' || $role === 'hoy') {
                        $accum = array_merge($accum, $routing?->hoy_emails ?? []);
                    }
                }
                $staffEmails = $accum;
            }

            // Add fixed extra emails from the rule (if any)
            if (is_array($rule->extra_emails) && !empty($rule->extra_emails)) {
                $staffEmails = array_merge($staffEmails, $rule->extra_emails);
            }

            // Normalize & dedupe
            $staffEmails = $this->normalizeEmails($staffEmails);
        }

        // ---------- PARENTS ----------
        $parentRecipients = [];
        if ($rule->recipient_parents) {
            // Parents are looked up by ARBOR/UPN id
            $arborId = $usedArborId ?: Student::where('record_id', (string) $studentRecordId)->value('arbor_id');

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
            'meta'    => [
                'routing_row_id' => $routing?->id,
                'student_record_id' => (string) $studentRecordId,
                'used_arbor_id'  => $usedArborId,
                'fallback_used'  => $fallbackUsed,
                'roles_used'     => is_array($rule->staff_roles) ? $rule->staff_roles : [],
                'extra_emails_added' => is_array($rule->extra_emails) ? $rule->extra_emails : [],
            ],
        ];
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
