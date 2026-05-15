<?php

namespace Tests\Unit;

use App\Support\MeetingRelayTokenService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class MeetingRelayTokenServiceTest extends TestCase
{
    public function test_issue_and_resolve_live_relay_token(): void
    {
        Cache::flush();

        $issued = MeetingRelayTokenService::issue(42, 7);
        $this->assertNotEmpty($issued['token']);
        $this->assertSame(900, $issued['expires_in']);

        $resolved = MeetingRelayTokenService::resolve($issued['token']);
        $this->assertSame(42, $resolved['meeting_id']);
        $this->assertSame(7, $resolved['user_id']);

        MeetingRelayTokenService::revoke($issued['token']);
        $this->assertNull(MeetingRelayTokenService::resolve($issued['token']));
    }

    public function test_wrong_meeting_id_token_does_not_resolve_for_other_meeting(): void
    {
        Cache::flush();
        $issued = MeetingRelayTokenService::issue(1, 2);
        $resolved = MeetingRelayTokenService::resolve($issued['token']);
        $this->assertSame(1, $resolved['meeting_id']);
    }

    public function test_revoke_for_meeting_clears_all_tokens(): void
    {
        Cache::flush();
        $a = MeetingRelayTokenService::issue(9, 3);
        $b = MeetingRelayTokenService::issue(9, 3);
        $this->assertNotNull(MeetingRelayTokenService::resolve($a['token']));
        $this->assertNotNull(MeetingRelayTokenService::resolve($b['token']));

        MeetingRelayTokenService::revokeForMeeting(9);
        $this->assertNull(MeetingRelayTokenService::resolve($a['token']));
        $this->assertNull(MeetingRelayTokenService::resolve($b['token']));
    }
}
