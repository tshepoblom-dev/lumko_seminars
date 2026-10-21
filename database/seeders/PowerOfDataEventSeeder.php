<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\ProgrammeSession;
use App\Models\Speaker;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class PowerOfDataEventSeeder extends Seeder
{
    public function run(): void
    {
        $eventDate = Carbon::create(2026, 11, 13, 0, 0, 0, 'Africa/Johannesburg');

        $event = Event::updateOrCreate(
            ['slug' => 'the-power-of-data'],
            [
                'name' => 'The Power of Data',
                'subtitle' => 'How digital transformation and applied data science drive organisations forward',
                'description' => 'A focused morning seminar on digital transformation and applied data science, hosted by Lumko Business Technology Partners.',
                'venue_name' => "St Eve's Spa & Lodge",
                'venue_address' => '21 York Street, Waverley, Bloemfontein',
                'starts_at' => $eventDate->copy()->setTime(8, 0),
                'ends_at' => $eventDate->copy()->setTime(9, 20),
                'online_price' => 0,
                'in_person_price' => 200,
                // In person only; recorded and released afterwards (2026-10-21).
                'offers_online' => false,
                'status' => 'published',
            ]
        );

        $paledi = Speaker::updateOrCreate(
            ['name' => 'Kgotla Paledi'],
            [
                'title' => 'Free State Head of IT',
                'organisation' => 'SALGA',
                'bio' => 'Kgotla Paledi is the Free State Head of IT at SALGA, where he leads digital transformation initiatives and data-driven decision-making processes for local government.',
            ]
        );

        $combrink = Speaker::updateOrCreate(
            ['name' => 'Dr Herkulaas M. Combrink'],
            [
                'title' => 'Medical & Data Scientist',
                'organisation' => 'University of the Free State',
                'bio' => 'Dr Herkulaas M. Combrink is a medical and data scientist at the University of the Free State, specializing in applied data science and its implications for healthcare and public policy.',
            ]
        );

        $day = $eventDate;

        $sessions = [
            [
                'title' => 'Welcome & opening',
                'description' => 'Welcome from Tshepo Blom on behalf of Lumko Business Technology Partners, with context and introduction of speakers.',
                'start' => [8, 0], 'end' => [8, 5],
                'speakers' => [],
                'sort_order' => 1,
            ],
            [
                'title' => 'Data in Action: Driving Digital Transformation',
                'description' => 'Kgotla Paledi presents on how data is leveraged to drive digital transformation within local government, sharing insights and case studies from his experience at SALGA.',
                // 23-minute talk length within a 25-minute slot; the slot
                // end (08:30), not the talk length, is what the programme
                // timeline and control-room "current session" logic use.
                'start' => [8, 5], 'end' => [8, 30],
                'speakers' => [$paledi->id],
                'sort_order' => 2,
            ],
            [
                'title' => 'AI, data science & data sovereignty',
                'description' => 'Dr Herkulaas M. Combrink discusses the role of artificial intelligence, data science and data sovereignty, with a focus on the organizational and societal implications.',
                'start' => [8, 30], 'end' => [8, 55],
                'speakers' => [$combrink->id],
                'sort_order' => 3,
            ],
            [
                'title' => 'Joint discussion / selected audience questions',
                'description' => 'An opportunity for the audience to engage in a discussion with the speakers and ask questions about the topics presented.',
                'start' => [8, 55], 'end' => [9, 7],
                'speakers' => [$paledi->id, $combrink->id],
                'sort_order' => 4,
            ],
            [
                'title' => 'Closing remarks & thank you',
                'description' => 'Closing remarks from the event organizers and a thank you to all attendees and speakers.',
                'start' => [9, 7], 'end' => [9, 10],
                'speakers' => [],
                'sort_order' => 5,
            ],
            [
                'title' => 'Networking & departures',
                'description' => 'An opportunity for attendees to network and connect with speakers and fellow participants.',
                'start' => [9, 10], 'end' => [9, 20],
                'speakers' => [],
                'sort_order' => 6,
            ],
        ];

        // Speakers live in the shared library; link them to this event
        // (in running order) before naming them on sessions.
        $event->speakers()->syncWithoutDetaching([
            $paledi->id => ['sort_order' => 0],
            $combrink->id => ['sort_order' => 1],
        ]);

        foreach ($sessions as $session) {
            $programmeSession = ProgrammeSession::updateOrCreate(
                [
                    'event_id' => $event->id,
                    'sort_order' => $session['sort_order'],
                ],
                [
                    'title' => $session['title'],
                    'description' => $session['description'],
                    'start_time' => $day->copy()->setTime(...$session['start']),
                    'end_time' => $day->copy()->setTime(...$session['end']),
                ]
            );

            $programmeSession->speakers()->sync(
                collect($session['speakers'])->mapWithKeys(fn ($id, $i) => [$id => ['sort_order' => $i]])->all()
            );
        }
    }
}
