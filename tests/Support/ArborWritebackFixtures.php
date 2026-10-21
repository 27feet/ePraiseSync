<?php

namespace Tests\Support;

use App\Models\ArborBehaviourCategory;
use App\Models\ArborPointCategory;
use App\Models\ArborPointScale;
use App\Models\ArborStaff;
use App\Models\EpraiseDemeritCategory;
use App\Models\EpraiseInterventionCategory;
use App\Models\EpraisePointCategory;
use App\Models\Point;
use App\Models\Student;
use App\Models\Teacher;

trait ArborWritebackFixtures
{
    private function mappingFixtures(): void
    {
        config(['services.arbor.base_url' => 'https://arbor.test', 'services.arbor.sync.retry_jitter_percent' => 0]);
        Student::create(['record_id' => 12, 'arbor_id' => '120']);
        Teacher::create(['record_id' => 34, 'email' => 'teacher@example.test']);
        ArborStaff::create(['arbor_id' => 340, 'email' => 'teacher@example.test']);
        ArborPointScale::create(['arbor_id' => 56]);
        $point = ArborPointCategory::create(['arbor_id' => 78, 'arbor_point_scale_id' => 56]);
        $behaviour = ArborBehaviourCategory::create(['arbor_id' => 90, 'severity' => 1, 'active' => true]);
        EpraisePointCategory::create(['epraise_name' => 'Test', 'arbor_point_category_id' => $point->id]);
        EpraiseDemeritCategory::create(['epraise_name' => 'Test', 'arbor_point_category_id' => $point->id, 'arbor_behaviour_category_id' => $behaviour->id]);
        EpraiseInterventionCategory::create(['epraise_name' => 'Test', 'arbor_point_category_id' => $point->id, 'arbor_behaviour_category_id' => $behaviour->id, 'default_points' => 1]);
    }

    private function point(array $changes = []): Point
    {
        return Point::create(array_merge([
            'record_id' => 123, 'student_id' => 12, 'teacher_id' => 34, 'class_id' => 0,
            'subject' => 'Other', 'category' => 'Test', 'date' => '2026-10-20 12:00:00',
            'points' => 1, 'sync_status' => 10,
        ], $changes));
    }

    private function complexRecord(string $class, array $changes = [])
    {
        $data = [
            'record_id' => 123, 'student_id' => 12, 'teacher_id' => 34,
            'subject' => 'Other', 'sync_status' => 10,
        ];
        if ($class === \App\Models\Intervention::class) {
            $data += ['demerit_id' => 0, 'intervention_type' => 'Test', 'date_added' => '2026-10-20 12:00:00'];
        } else {
            $data += ['class_id' => 0, 'category' => 'Test', 'date' => '2026-10-20 12:00:00', 'points' => 1];
        }

        return $class::create(array_merge($data, $changes));
    }
}
