<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\NotificationType;
use App\Models\FirebaseToken;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\PlatformNotification;
use App\Services\Notifications\FcmSender;
use Illuminate\Support\Facades\Lang;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Queues\QueueTestCase;

/**
 * Crew read the app in Swahili, passengers in English, and one person may carry
 * both. The language therefore belongs to the DEVICE token, not the account.
 */
final class DeviceLocaleTest extends QueueTestCase
{
    private function fakeSender(): FcmSender
    {
        return new class extends FcmSender
        {
            /** @var array<int, array<string, mixed>> */
            public array $sent = [];

            public function send(string $token, string $title, string $body, array $data): bool
            {
                $this->sent[] = compact('token', 'title', 'body', 'data');

                return true;
            }
        };
    }

    // ------------------------------------------------------------ registration

    #[Test]
    public function registering_a_device_stores_its_language(): void
    {
        $user = $this->makeUser([], $this->makeSacco());
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/auth/notifications/devices', [
            'token' => 'fabc123',
            'platform' => 'ANDROID',
            'locale' => 'sw',
        ])->assertOk();

        $this->assertSame('sw', FirebaseToken::where('firebase_token', 'fabc123')->value('locale'));
    }

    #[Test]
    public function changing_language_updates_the_same_token_rather_than_adding_one(): void
    {
        // The app re-sends the SAME token when the crew switches language, so
        // this has to stay an upsert or a phone accumulates rows and gets two
        // copies of every push.
        $user = $this->makeUser([], $this->makeSacco());
        Sanctum::actingAs($user);

        foreach (['en', 'sw'] as $locale) {
            $this->postJson('/api/v1/auth/notifications/devices', [
                'token' => 'fabc123', 'platform' => 'ANDROID', 'locale' => $locale,
            ])->assertOk();
        }

        $this->assertSame(1, FirebaseToken::where('firebase_token', 'fabc123')->count());
        $this->assertSame('sw', FirebaseToken::where('firebase_token', 'fabc123')->value('locale'));
    }

    #[Test]
    public function a_device_that_sends_no_language_is_english(): void
    {
        $user = $this->makeUser([], $this->makeSacco());
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/auth/notifications/devices', ['token' => 'fabc123'])->assertOk();

        $this->assertSame('en', FirebaseToken::where('firebase_token', 'fabc123')->value('locale'));
    }

    #[Test]
    public function an_unsupported_language_is_rejected(): void
    {
        // 422, not the contract's 400: this endpoint already used ->validate()
        // before the contract was written, and the app ships against it.

        $user = $this->makeUser([], $this->makeSacco());
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/auth/notifications/devices', [
            'token' => 'fabc123', 'locale' => 'fr',
        ])->assertStatus(422);
    }

    // ------------------------------------------------------------------- push

    #[Test]
    public function a_push_is_written_in_the_language_of_the_phone_it_goes_to(): void
    {
        Lang::addLines(['test.hello' => 'Found it'], 'en');
        Lang::addLines(['test.hello' => 'Imepatikana'], 'sw');

        $user = $this->makeUser([], $this->makeSacco());
        FirebaseToken::create(['firebase_token' => 'tok-sw', 'user_id' => $user->id, 'locale' => 'sw']);

        $sender = $this->fakeSender();
        (new FcmChannel($sender))->send($user, new PlatformNotification(
            type: NotificationType::System,
            title: 'Found it',
            message: 'Found it',
            titleKey: 'test.hello',
            messageKey: 'test.hello',
        ));

        $this->assertSame('Imepatikana', $sender->sent[0]['title']);
    }

    #[Test]
    public function one_person_with_two_phones_gets_each_phone_its_own_language(): void
    {
        // The case that makes this per-token rather than per-user: a SACCO
        // manager with a Swahili work phone and an English personal one.
        Lang::addLines(['test.hello' => 'Found it'], 'en');
        Lang::addLines(['test.hello' => 'Imepatikana'], 'sw');

        $user = $this->makeUser([], $this->makeSacco());
        FirebaseToken::create(['firebase_token' => 'tok-en', 'user_id' => $user->id, 'locale' => 'en']);
        FirebaseToken::create(['firebase_token' => 'tok-sw', 'user_id' => $user->id, 'locale' => 'sw']);

        $sender = $this->fakeSender();
        (new FcmChannel($sender))->send($user, new PlatformNotification(
            type: NotificationType::System,
            title: 'Found it',
            message: 'Found it',
            titleKey: 'test.hello',
            messageKey: 'test.hello',
        ));

        $byToken = collect($sender->sent)->keyBy('token');
        $this->assertSame('Found it', $byToken['tok-en']['title']);
        $this->assertSame('Imepatikana', $byToken['tok-sw']['title']);
    }

    #[Test]
    public function a_notification_with_no_translation_keys_still_sends_its_literal_text(): void
    {
        // Every notification that existed before this change passes title and
        // message only; none of them may start pushing an untranslated key.
        $user = $this->makeUser([], $this->makeSacco());
        FirebaseToken::create(['firebase_token' => 'tok-sw', 'user_id' => $user->id, 'locale' => 'sw']);

        $sender = $this->fakeSender();
        (new FcmChannel($sender))->send($user, new PlatformNotification(
            type: NotificationType::System,
            title: 'Your booking is paid',
            message: 'Seat 4B on KDC 482M.',
        ));

        $this->assertSame('Your booking is paid', $sender->sent[0]['title']);
        $this->assertSame('Seat 4B on KDC 482M.', $sender->sent[0]['body']);
    }

    #[Test]
    public function a_null_locale_on_an_existing_token_falls_back_to_english(): void
    {
        // Every token registered before the migration has locale NULL.
        Lang::addLines(['test.hello' => 'Found it'], 'en');
        Lang::addLines(['test.hello' => 'Imepatikana'], 'sw');

        $user = $this->makeUser([], $this->makeSacco());
        FirebaseToken::create(['firebase_token' => 'tok-old', 'user_id' => $user->id]);

        $sender = $this->fakeSender();
        (new FcmChannel($sender))->send($user, new PlatformNotification(
            type: NotificationType::System,
            title: 'Found it',
            message: 'Found it',
            titleKey: 'test.hello',
            messageKey: 'test.hello',
        ));

        $this->assertSame('Found it', $sender->sent[0]['title']);
    }

    // -------------------------------------------------------- Accept-Language

    #[Test]
    public function the_shipped_swahili_catalogue_actually_resolves(): void
    {
        // Guards against a typo in lang/sw/notifications.php silently falling
        // through to the key, which would push the literal "notifications.…".
        $en = (string) __('notifications.lost_item.found.title', ['category' => 'phone'], 'en');
        $sw = (string) __('notifications.lost_item.found.title', ['category' => 'Simu'], 'sw');

        $this->assertStringNotContainsString('notifications.', $en);
        $this->assertStringNotContainsString('notifications.', $sw);
        $this->assertNotSame($en, $sw);
    }
}
