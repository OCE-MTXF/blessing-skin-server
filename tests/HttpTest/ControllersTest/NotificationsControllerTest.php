<?php

namespace Tests;

use App\Models\User;
use App\Notifications;
use Illuminate\Support\Facades\Notification;
use Laravel\Passport\Passport;
use App\Services\SafeMarkdown;

class NotificationsControllerTest extends TestCase
{
    public function testSend()
    {
        $admin = User::factory()->admin()->create();
        $normal = User::factory()->create();
        Notification::fake();

        $this->actingAs($admin)
            ->post('/admin/notifications/send', [
                'receiver' => 'all',
                'title' => 'all users',
                'content' => null,
            ])
            ->assertRedirect('/admin')
            ->assertSessionHas('sentResult', trans('admin.notifications.send.success'));
        Notification::assertSentTo(
            [$admin, $normal],
            Notifications\SiteMessage::class,
            function ($notification) {
                $this->assertEquals('all users', $notification->title);

                return true;
            }
        );

        Notification::fake();
        Notification::assertNothingSent();
        $this->post('/admin/notifications/send', [
            'receiver' => 'normal',
            'title' => 'normal only',
            'content' => 'hi',
        ]);
        Notification::assertSentTo(
            $normal,
            Notifications\SiteMessage::class,
            function ($notification) {
                $this->assertEquals('normal only', $notification->title);
                $this->assertEquals('hi', $notification->content);

                return true;
            }
        );
        Notification::assertNotSentTo($admin, Notifications\SiteMessage::class);

        Notification::fake();
        Notification::assertNothingSent();
        $this->post('/admin/notifications/send', [
            'receiver' => 'uid',
            'title' => 'uid',
            'content' => null,
            'uid' => $normal->uid,
        ]);
        Notification::assertSentTo(
            $normal,
            Notifications\SiteMessage::class,
            function ($notification) {
                $this->assertEquals('uid', $notification->title);

                return true;
            }
        );
        Notification::assertNotSentTo($admin, Notifications\SiteMessage::class);

        Notification::fake();
        Notification::assertNothingSent();
        $this->post('/admin/notifications/send', [
            'receiver' => 'email',
            'title' => 'email',
            'content' => null,
            'email' => $normal->email,
        ]);
        Notification::assertSentTo(
            $normal,
            Notifications\SiteMessage::class,
            function ($notification) {
                $this->assertEquals('email', $notification->title);

                return true;
            }
        );
        Notification::assertNotSentTo($admin, Notifications\SiteMessage::class);
    }

    public function testAll()
    {
        $user = User::factory()->create();

        $notification = new Notifications\SiteMessage('title', 'content');
        Notification::send([$user], $notification);

        $id = $user->unreadNotifications->first()->id;

        Passport::actingAs($user, ['Notification.Read'], 'oauth');
        $authorization = ['Authorization' => 'Bearer test-fixture'];
        $this->getJson('/api/user/notifications')->assertUnauthorized();
        $this->getJson('/api/user/notifications', $authorization)
            ->assertExactJson([['id' => $id, 'title' => 'title']]);
        $url = '/api/user/notifications/'.$id;
        $this->getJson($url, $authorization)->assertOk()->assertJsonPath('title', 'title');
        $this->postJson($url, [], $authorization)->assertForbidden();
        $item = $user->notifications()->findOrFail($id);
        self::assertNull($item->read_at);

        Passport::actingAs($user, ['Notification.Acknowledge'], 'oauth');
        $this->getJson('/api/user/notifications', $authorization)->assertForbidden();
        $this->postJson($url, [], $authorization)->assertOk();
        self::assertNotNull($item->refresh()->read_at);

        $other = User::factory()->create();
        Passport::actingAs($other, ['Notification.Read', 'Notification.Acknowledge'], 'oauth');
        $this->getJson($url, $authorization)->assertNotFound();
        $this->postJson($url, [], $authorization)->assertNotFound();
    }

    public function testRead()
    {
        /** @var User */
        $user = User::factory()->create();
        $user->notify(new Notifications\SiteMessage('Hyouka', 'Kotenbu?'));
        $user->refresh();
        $notification = $user->unreadNotifications->first();

        $this->actingAs($user)->get('/user')->assertSee('Hyouka');

        $path = '/user/notifications/'.$notification->id;
        $this->postJson($path)->assertOk()->assertJson([
            'title' => $notification->data['title'],
            'content' => app(SafeMarkdown::class)->render($notification->data['content']),
            'time' => $notification->created_at->toDateTimeString(),
        ]);
        $readAt = $notification->refresh()->read_at;
        self::assertNotNull($readAt);
        $this->travel(2)->minutes();
        $this->postJson($path)->assertOk();
        self::assertEquals($readAt, $notification->refresh()->read_at);
    }
}
