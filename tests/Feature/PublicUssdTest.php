<?php

namespace Tests\Feature;

use App\Jobs\SendSms;
use App\Models\Agent;
use App\Models\Coordinator;
use App\Models\DashboardDelivery;
use App\Models\Incident;
use App\Models\PollingUnit;
use App\Models\User;
use App\Models\Volunteer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithUssd;
use Tests\TestCase;

/**
 * The USSD service is open to anyone: the public can report incidents and
 * sign up to help ("How can you help?"); agents can sign up too.
 */
class PublicUssdTest extends TestCase
{
    use InteractsWithUssd, RefreshDatabase;

    private const CALLER = '+2348099999999';

    private const LGAS = ['Abakaliki', 'Afikpo North', 'Afikpo South', 'Ebonyi', 'Ezza North', 'Ezza South', 'Ikwo', 'Ishielu', 'Ivo', 'Izzi', 'Ohaozara', 'Ohaukwu', 'Onicha'];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->setUpUssd(); // PU 110101001 in Abakaliki / Amachi Ward
        config(['services.dashboard' => ['url' => 'https://dashboard.test/api', 'token' => 't', 'secret' => 's', 'timeout' => 5]]);

        // The real register's shape: every LGA with wards "<LGA> Ward 01"...
        $code = 212000000;
        foreach (self::LGAS as $lga) {
            foreach (range(1, $lga === 'Ikwo' ? 15 : 3) as $ward) {
                PollingUnit::factory()->create(['code' => (string) $code++, 'name' => "PU {$code}", 'lga' => $lga, 'ward' => sprintf('%s Ward %02d', $lga, $ward), 'registered_voters' => 500]);
            }
        }
        Coordinator::create(['name' => 'State Lead', 'phone_number' => '08020000003']);
    }

    private function dial(string $text, string $phone = self::CALLER): TestResponse
    {
        return $this->post('/api/ussd', ['sessionId' => 'ATUid_p', 'serviceCode' => '*384*123#', 'phoneNumber' => $phone, 'text' => $text])->assertOk();
    }

    public function test_the_public_menu_and_election_info(): void
    {
        $this->dial('')->assertContent("CON Election Shield\n1. Report Incident\n2. How can you help?\n3. Election info\n4. Exit");
        $this->dial('3')->assertSee('END Governorship election', false);
        $this->dial('4')->assertContent('END Thank you');
        $this->dial('5')->assertSee("CON Invalid input.\nElection Shield", false);
        // Agent-only actions are not offered to the public.
        $this->assertStringNotContainsString('Submit Result', $this->dial('')->getContent());
    }

    public function test_a_member_of_the_public_reports_an_incident_by_lga_and_ward(): void
    {
        $this->dial('1')->assertSee('CON Incident Type:', false);
        $lgaScreen = $this->dial('1*1')->getContent();
        $this->assertStringStartsWith("CON Where? Choose LGA:\n1.Abakaliki\n2.Afikpo North", $lgaScreen);
        $this->assertStringContainsString('0.More', $lgaScreen);

        // Next page: numbers run on; "0" again goes back to the start.
        $more = $this->dial('1*1*0')->getContent();
        $this->assertStringContainsString('13.Onicha', $more);
        $this->assertStringContainsString('0.Back to start', $more);
        $this->assertStringStartsWith("CON Where? Choose LGA:\n1.Abakaliki", $this->dial('1*1*0*0')->getContent());
        // Any number works on any page: 13 (Onicha) from the first page.
        $this->assertStringStartsWith('CON Onicha: choose ward', $this->dial('1*1*13')->getContent());

        // Ikwo (7), then its 15 wards over two screens.
        $wards = $this->dial('1*1*7')->getContent();
        $this->assertStringStartsWith("CON Ikwo: choose ward\n1.Ward 01\n2.Ward 02", $wards);
        $this->assertStringContainsString('0.More', $wards);
        $this->assertStringContainsString('15.Ward 15', $this->dial('1*1*7*0')->getContent());

        $this->dial('1*1*7*3')->assertContent("CON PU code (if you know it)\nor 0 to skip:");
        $this->dial('1*1*7*3*0')->assertContent('CON Short Note:');
        $this->dial('1*1*7*3*0*Thugs at the school')->assertContent("CON Confirm Report:\nViolence\nWard 03, Ikwo\n1. Submit\n2. Cancel");
        $end = $this->dial('1*1*7*3*0*Thugs at the school*1')->getContent();
        $this->assertMatchesRegularExpression('/^END Report received ✔\nThank you.\nRef: IN\d+$/u', $end);

        $incident = Incident::query()->sole();
        $this->assertSame(['public', self::CALLER, 'Ikwo', 'Ikwo Ward 03', null, null], [$incident->source, $incident->reporter_phone, $incident->lga, $incident->ward, $incident->polling_unit_code, $incident->agent_id]);

        // Public reports never text coordinators, even urgent ones; the web app gets them flagged.
        Queue::assertNotPushed(SendSms::class);
        $event = DashboardDelivery::query()->where('event', 'incident.reported')->sole()->payload;
        $this->assertSame(['public', self::CALLER, null, 'Ikwo', 'Ikwo Ward 03'], [$event['source'], $event['reporter_phone'], $event['agent'], $event['polling_unit']['lga'], $event['polling_unit']['ward']]);
    }

    public function test_a_known_pu_code_sets_the_place(): void
    {
        $this->dial('1*4*1*1*'.self::PU.'*Delay at PU*1');
        $incident = Incident::query()->sole();
        $this->assertSame([self::PU, 'Abakaliki', 'Amachi Ward'], [$incident->polling_unit_code, $incident->lga, $incident->ward]);
        $this->dial('1*4*1*1*99')->assertContent("CON PU code not found.\nEnter PU code or 0 to skip:");
        $this->dial('1*4*1*1*99*0')->assertContent('CON Short Note:');
    }

    public function test_the_public_can_report_a_few_times_a_day_only(): void
    {
        config(['ussd.public_daily_incident_limit' => 2]);
        $this->dial('1*5*1*1*0*One*1');
        $this->dial('1*5*1*1*0*Two*1');
        $this->dial('1')->assertSee('END Daily report limit reached.', false);
        $this->assertSame(2, Incident::query()->count());
        // Another number is not affected; agents are never limited.
        $this->dial('1', '+2348077777777')->assertSee('CON Incident Type:', false);
    }

    public function test_a_member_of_the_public_signs_up_to_help(): void
    {
        $roles = $this->dial('2')->getContent();
        $this->assertSame("CON How can you help?\nChoose any, e.g. 135\n1.Canvass my ward\n2.PU agent\n3.Share on WhatsApp\n4.Mobilise women\n5.Mobilise youth\n6.Transport/logistics\n7.Legal/media/medical/IT\n8.Other", $roles);

        $this->dial('2*19')->assertSee("CON Invalid. Type e.g. 135:\n1.Canvass my ward", false);
        $this->dial('2*1375')->assertContent("CON Which skills?\nChoose any, e.g. 13\n1.Legal\n2.Media\n3.Medical\n4.IT\n5.Other");
        $this->dial('2*1375*14')->assertSee('CON Your LGA:', false);
        $this->dial('2*1375*14*1*2')->assertContent('CON Your full name:');
        $this->dial('2*1375*14*1*2*A1')->assertContent("CON Invalid name.\nEnter your full name:");
        $this->dial('2*1375*14*1*2*Chioma  Uche')->assertContent("CON Contact number:\n08099999999\n1. Use this number\n2. Use another number");
        $this->dial('2*1375*14*1*2*Chioma Uche*2*123')->assertSee('CON Invalid number.', false);
        $this->dial('2*1375*14*1*2*Chioma Uche*2*0803 123 4567')->assertContent("CON Confirm:\nChioma Uche\nWard 02, Abakaliki\n08031234567\nHelp: Canvass my ward, Share on WhatsApp, Mobilise youth, Legal/media…\n1. Submit\n2. Cancel");
        $this->dial('2*1375*14*1*2*Chioma Uche*2*0803 123 4567*1')->assertSee("END Thank you, Chioma! ✔\nWe will contact you on\n08031234567.\nRef: VL", false);

        $volunteer = Volunteer::query()->sole();
        $this->assertSame([self::CALLER, '+2348031234567', 'Chioma Uche', 'Abakaliki', 'Abakaliki Ward 02', ['canvass', 'share', 'youth', 'professional'], ['legal', 'it'], false], [$volunteer->phone_number, $volunteer->contact_phone, $volunteer->name, $volunteer->lga, $volunteer->ward, $volunteer->roles, $volunteer->skills, $volunteer->is_agent]);
        $this->assertSame(['Canvass in my ward', 'Share on WhatsApp and social media', 'Mobilise youth', 'Professional skills (legal, media, medical, IT)'], $volunteer->roleLabels());
        $this->assertSame('volunteer.registered', DashboardDelivery::query()->sole()->event);

        // Anything else: a note. The same contact number again updates that volunteer (and tells the web app again).
        $this->travel(1)->minutes();
        $this->dial('2*8')->assertContent("CON Anything else?\nTell us how you can help:");
        $this->dial('2*8*I can print posters*7*2*Chioma Uche*2*08031234567*1');
        $volunteer = Volunteer::query()->sole();
        $this->assertSame([['other'], 'I can print posters', 'Ikwo', null, '+2348031234567'], [$volunteer->roles, $volunteer->other, $volunteer->lga, $volunteer->skills, $volunteer->contact_phone]);
        $this->assertSame(2, DashboardDelivery::query()->where('event', 'volunteer.registered')->count());

        // The same phone signing up someone else (another contact number) adds a new volunteer.
        $this->dial('2*6*7*2*Emeka Eze*1*1')->assertSee('END Thank you, Emeka!', false);
        $this->assertSame(['Chioma Uche' => '+2348031234567', 'Emeka Eze' => self::CALLER], Volunteer::query()->orderBy('id')->pluck('contact_phone', 'name')->all());
        $this->assertSame([self::CALLER, self::CALLER], Volunteer::query()->pluck('phone_number')->all());
    }

    public function test_one_phone_can_sign_up_a_limited_number_of_people_a_day(): void
    {
        config(['ussd.volunteer_daily_limit' => 2]);
        $this->dial('2*1*1*1*Ada One*2*08031111111*1');
        $this->dial('2*1*1*1*Ada Two*2*08032222222*1');
        $this->dial('2')->assertSee('END Daily sign-up limit reached', false);
        $this->assertSame(2, Volunteer::query()->count());
        $this->dial('2', '+2348077777777')->assertSee('CON How can you help?', false); // another phone
    }

    public function test_agents_can_sign_up_to_help_too(): void
    {
        $this->ussd('6')->assertSee('CON How can you help?', false);
        $this->ussd('6*2*1*1*Ada Obi*1*1')->assertSee('END Thank you, Ada!', false);
        $this->assertTrue(Volunteer::query()->sole()->is_agent);
    }

    public function test_every_new_screen_fits_on_a_ussd_display(): void
    {
        foreach (['', '1', '1*1', '1*1*0', '1*1*7', '1*1*7*3', '1*1*7*3*0', '1*1*7*3*0*'.str_repeat('a', 30), '1*1*99', '1*1*7*99',
            '2', '2*99', '2*7', '2*7*9', '2*8', '2*12345678', '2*12345678*12345', '2*12345678*12345*'.str_repeat('b', 160), '2*1*1*1*'.str_repeat('Zed ', 15), '2*1*1*1*Ada Obi', '2*1*1*1*Ada Obi*2*1', '2*1*1*1*'.str_repeat('Longname ', 6).'*1', '3'] as $text) {
            $body = $this->dial($text)->getContent();
            $this->assertLessThanOrEqual(182, mb_strlen($body), "Screen too long for [{$text}]: {$body}");
        }
        foreach (['6', '6*12345678', '6*1*1*1*Ada Obi*1'] as $text) {
            $body = $this->ussd($text)->getContent();
            $this->assertLessThanOrEqual(182, mb_strlen($body), "Screen too long for agent [{$text}]: {$body}");
        }
    }

    public function test_the_real_register_ward_lists_fit_on_a_ussd_display(): void
    {
        PollingUnit::query()->delete();
        $this->artisan('pu:import', ['file' => database_path('data/ebonyi_polling_units.csv')])->assertSuccessful();

        foreach (range(1, 13) as $lga) {
            $text = "1*1*{$lga}";
            for ($page = 1; $page <= 6; $page++) {
                $body = $this->dial($text)->getContent();
                $this->assertLessThanOrEqual(182, mb_strlen($body), "Screen too long for [{$text}]: {$body}");
                $this->assertLessThanOrEqual(182, mb_strlen($this->dial($text.'*999')->getContent()), "Error screen too long for [{$text}]");
                if (! str_contains($body, '0.More')) {
                    break;
                }
                $text .= '*0';
            }
            $this->assertStringNotContainsString('0.More', $body, "LGA {$lga} has more ward pages than expected");
        }

        // Choosing a ward on a later page still lands on that ward (Ikwo has 20).
        $this->dial('1*1*7*20')->assertSee('PU code (if you know it)', false);
    }

    public function test_the_admin_console_and_the_data_api_show_public_reports_and_volunteers(): void
    {
        $this->dial('1*1*7*3*0*Thugs at the school*1');   // public, Ikwo Ward 03
        $this->ussd('2*1*'.self::PU.'*Fight at PU*1');     // agent, Abakaliki
        $this->dial('2*15*7*2*Chioma Uche*1*1');           // volunteer in Ikwo Ward 02
        config(['election.api_token' => 'api-secret']);
        $api = ['Authorization' => 'Bearer api-secret'];

        // Data API: Ikwo includes the public report (it has no PU); source filter; volunteers feed.
        $this->getJson('/api/incidents?lga=Ikwo', $api)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.source', 'public')->assertJsonPath('data.0.reporter_phone', self::CALLER);
        $this->getJson('/api/incidents?source=agent', $api)->assertJsonCount(1, 'data')->assertJsonPath('data.0.agent.name', 'Ada Obi');
        $this->getJson('/api/volunteers?role=youth', $api)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Chioma Uche')->assertJsonPath('data.0.role_labels', ['Canvass in my ward', 'Mobilise youth']);
        $this->getJson('/api/volunteers?role=transport', $api)->assertJsonCount(0, 'data');

        // Admin console.
        $this->actingAs(User::factory()->admin()->create());
        $this->get('/admin/incidents')->assertOk()->assertSee('Member of the public')->assertSee('Public (unverified)')->assertSee('Ada Obi');
        $this->get('/admin/incidents?source=public')->assertOk()->assertDontSee('Fight at PU');
        $this->get('/admin/incidents?lga=Ikwo')->assertOk()->assertSee('Thugs at the school');
        $this->get('/admin/volunteers')->assertOk()->assertSee('Chioma Uche')->assertSee('Mobilise youth')->assertSee('Ikwo Ward 02');
        $this->get('/admin/volunteers?role=transport')->assertOk()->assertDontSee('Chioma Uche');
        $csv = $this->get('/admin/volunteers/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('Chioma Uche', $csv);
        $this->assertStringContainsString('Canvass in my ward; Mobilise youth', $csv);
        $this->assertStringContainsString('Member of the public', $this->get('/admin/incidents')->getContent());
        $this->assertStringContainsString('Public,,'.self::CALLER, $this->get('/admin/incidents/export')->streamedContent());
    }
}
