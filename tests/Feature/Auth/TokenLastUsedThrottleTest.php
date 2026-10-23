<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\PersonalAccessToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * personal_access_tokens.last_used_at is kept as an activity trail, but
 * written at most every 5 minutes instead of on every authenticated request.
 */
final class TokenLastUsedThrottleTest extends QueueTestCase
{
    private function stampedAt(PersonalAccessToken $token): ?string
    {
        return DB::table('personal_access_tokens')->where('id', $token->id)->value('last_used_at');
    }

    #[Test]
    public function a_token_is_stamped_on_first_use_and_then_at_most_every_five_minutes(): void
    {
        $this->travelTo(Carbon::parse('2026-10-23 08:00:00'));
        $user = $this->makeUser();
        $plain = $user->createToken('phone')->plainTextToken;
        $token = PersonalAccessToken::findToken($plain);
        $this->assertInstanceOf(PersonalAccessToken::class, $token, 'Sanctum uses the throttled model');
        $hit = function () use ($plain) {
            // A fresh guard per request, as in production: the test app would
            // otherwise keep the user authenticated by the first request.
            $this->app['auth']->forgetGuards();

            return $this->withHeader('Authorization', 'Bearer '.$plain)->postJson('/api/v1/auth/user');
        };

        $hit();
        $this->assertSame('2026-10-23 08:00:00', $this->stampedAt($token), 'first use is recorded');

        $this->travelTo(Carbon::parse('2026-10-23 08:03:00'));
        $hit();
        $this->assertSame('2026-10-23 08:00:00', $this->stampedAt($token), 'a use within 5 minutes writes nothing');

        $this->travelTo(Carbon::parse('2026-10-23 08:06:00'));
        $hit();
        $this->assertSame('2026-10-23 08:06:00', $this->stampedAt($token), 'a later use refreshes it');
    }

    #[Test]
    public function any_other_change_to_a_token_still_saves(): void
    {
        $user = $this->makeUser();
        $token = PersonalAccessToken::findToken($user->createToken('phone')->plainTextToken);
        $token->forceFill(['last_used_at' => now()])->save();

        $token->forceFill(['name' => 'renamed', 'last_used_at' => now()])->save();

        $this->assertSame('renamed', DB::table('personal_access_tokens')->where('id', $token->id)->value('name'));
    }
}
