<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesEventWorld;
use Tests\TestCase;

/**
 * Event → Attendees → "Export CSV": every registration answer for the
 * attendees matching the current filters, audited as a PII export.
 */
class AttendeeExportTest extends TestCase
{
    use CreatesEventWorld;
    use RefreshDatabase;

    /** @return array<int, array<int, string>> */
    private function csvRows(string $body): array
    {
        $body = preg_replace('/^\xEF\xBB\xBF/', '', $body);

        return array_map('str_getcsv', preg_split('/\r?\n/', trim($body)));
    }

    public function test_admin_downloads_every_answer_for_this_events_registrants_only(): void
    {
        $event = $this->makeEvent(['slug' => 'power-of-data']);
        $registration = $this->registerAttendee($event, 'online', ['first_name' => 'Lerato', 'last_name' => 'Mokoena']);
        $registration->update([
            'interests' => ['AI', 'Automation'],
            'improvement_area' => ['Reporting'],
            'industry' => 'Healthcare',
            'organisation_size' => '51-200',
            'organisation_challenge' => 'Too many spreadsheets',
        ]);
        $this->registerAttendee($this->makeEvent(), 'online', ['first_name' => 'Elsewhere']);

        $response = $this->actingAs($this->makeSuperAdmin())->get(route('admin.attendees.export', $event));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attendees-power-of-data-', $response->headers->get('Content-Disposition'));

        $body = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);

        $rows = $this->csvRows($body);
        $header = $rows[0];
        $this->assertCount(2, $rows, 'header plus one registrant; the other event is excluded');

        $row = array_combine($header, $rows[1]);
        $this->assertSame('Lerato', $row['First name']);
        $this->assertSame('Healthcare', $row['Industry']);
        $this->assertSame('51-200', $row['Organisation size']);
        $this->assertSame('AI; Automation', $row['Interests']);
        $this->assertSame('Reporting', $row['Wants to improve']);
        $this->assertSame('Too many spreadsheets', $row['Organisation challenge']);
        $this->assertSame('Yes', $row['Account set up']);
    }

    public function test_the_export_follows_the_list_filters(): void
    {
        $event = $this->makeEvent();
        $this->registerAttendee($event, 'online');
        $this->registerAttendee($event, 'in_person');

        $body = $this->actingAs($this->makeSuperAdmin())
            ->get(route('admin.attendees.export', ['event' => $event, 'mode' => 'in_person']))
            ->streamedContent();

        $this->assertCount(2, $this->csvRows($body));
    }

    public function test_spreadsheet_formulas_in_answers_are_neutralised(): void
    {
        $event = $this->makeEvent();
        $registration = $this->registerAttendee($event);
        $registration->update(['organisation_challenge' => '=HYPERLINK("http://evil")']);

        $rows = $this->csvRows($this->actingAs($this->makeSuperAdmin())->get(route('admin.attendees.export', $event))->streamedContent());
        $row = array_combine($rows[0], $rows[1]);

        $this->assertSame('\'=HYPERLINK("http://evil")', $row['Organisation challenge']);
    }

    public function test_each_export_is_audited(): void
    {
        $event = $this->makeEvent();
        $this->registerAttendee($event);

        $this->actingAs($this->makeSuperAdmin())->get(route('admin.attendees.export', ['event' => $event, 'mode' => 'online']))->streamedContent();

        $log = AuditLog::where('action', 'attendees.exported')->firstOrFail();
        $this->assertSame($event->id, $log->event_id);
        $this->assertSame(['filters' => ['mode' => 'online'], 'rows' => 1], $log->after);
    }

    public function test_a_manager_of_another_event_cannot_export(): void
    {
        $event = $this->makeEvent();
        $manager = $this->makeManagerFor($this->makeEvent());

        $this->actingAs($manager)->get(route('admin.attendees.export', $event))->assertForbidden();
    }

    public function test_exports_are_rate_limited(): void
    {
        $event = $this->makeEvent();
        $admin = $this->makeSuperAdmin();

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($admin)->get(route('admin.attendees.export', $event))->assertOk();
        }

        $this->actingAs($admin)->get(route('admin.attendees.export', $event))->assertStatus(429);
    }
}
