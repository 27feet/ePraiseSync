<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Services\FetchBackgroundDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class FetchEpraiseStudentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_arbor_id_and_same_record_id_updates_without_creating_duplicate(): void
    {
        $student = Student::create([
            'record_id' => 1198672,
            'arbor_id' => 2609,
        ]);

        $this->fetchStudentsFromEpraise([
            ['id' => 1198672, 'upn' => 2609],
        ]);

        $student->refresh();

        $this->assertSame($student->getKey(), Student::where('arbor_id', 2609)->value('id'));
        $this->assertSame(1198672, $student->record_id);
        $this->assertSame('2609', $student->arbor_id);
        $this->assertSame(1, Student::where('arbor_id', 2609)->count());
        $this->assertSame(1, Student::count());
    }

    public function test_existing_arbor_id_and_changed_record_id_updates_existing_student(): void
    {
        $student = Student::create([
            'record_id' => 1198672,
            'arbor_id' => 2609,
            'name' => 'Evie-Rose Jenner',
        ]);

        $this->fetchStudentsFromEpraise([
            ['id' => 2399166, 'upn' => 2609],
        ]);

        $student->refresh();

        $this->assertSame($student->getKey(), Student::where('arbor_id', 2609)->value('id'));
        $this->assertSame(2399166, $student->record_id);
        $this->assertSame('2609', $student->arbor_id);
        $this->assertSame('Evie-Rose Jenner', $student->name);
        $this->assertSame(1, Student::where('arbor_id', 2609)->count());
        $this->assertSame(1, Student::count());
    }

    public function test_new_arbor_id_and_new_record_id_creates_student(): void
    {
        $this->fetchStudentsFromEpraise([
            ['id' => 2396454, 'upn' => 2616],
        ]);

        $student = Student::where('arbor_id', 2616)->first();

        $this->assertNotNull($student);
        $this->assertSame(2396454, $student->record_id);
        $this->assertSame('2616', $student->arbor_id);
        $this->assertSame(1, Student::count());
    }

    public function test_existing_arbor_id_with_record_id_owned_by_another_student_logs_and_skips(): void
    {
        Log::shouldReceive('error')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'fetchEpraiseStudents: incoming record_id is already assigned to a different student'
                    && $context['incoming_record_id'] === 2399166
                    && (string) $context['incoming_arbor_id'] === '2609'
                    && $context['existing_student_record_id'] === 1198672
                    && (string) $context['existing_student_arbor_id'] === '2609'
                    && $context['record_id_owner_record_id'] === 2399166
                    && (string) $context['record_id_owner_arbor_id'] === '9999';
            });

        $existingArborStudent = Student::create([
            'record_id' => 1198672,
            'arbor_id' => 2609,
        ]);

        $recordIdOwner = Student::create([
            'record_id' => 2399166,
            'arbor_id' => 9999,
        ]);

        $this->fetchStudentsFromEpraise([
            ['id' => 2399166, 'upn' => 2609],
        ]);

        $existingArborStudent->refresh();
        $recordIdOwner->refresh();

        $this->assertSame(1198672, $existingArborStudent->record_id);
        $this->assertSame('2609', $existingArborStudent->arbor_id);
        $this->assertSame(2399166, $recordIdOwner->record_id);
        $this->assertSame('9999', $recordIdOwner->arbor_id);
        $this->assertSame(2, Student::count());
    }

    /**
     * @param  array<int, array{id: int, upn: int}>  $students
     */
    private function fetchStudentsFromEpraise(array $students): void
    {
        config(['services.epraise.endpoints.students' => 'https://epraise.test/students']);

        Http::fake([
            'https://epraise.test/students' => Http::response($students),
        ]);

        (new FetchBackgroundDataService)->fetchEpraiseStudents(request());
    }
}
