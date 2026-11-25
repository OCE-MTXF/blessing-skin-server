<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\SiteMessage;
use App\Services\Auth\AccountIdentity;
use App\Services\SafeMarkdown;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

class NotificationsController extends Controller
{
    public function send(Request $request)
    {
        $data = $request->validate([
            'receiver' => 'required|in:all,normal,uid,email',
            'uid' => 'required_if:receiver,uid|nullable|integer|min:1',
            'email' => 'required_if:receiver,email|nullable|string|email|max:100',
            'title' => 'required|string|max:20',
            'content' => 'nullable|string|max:4096',
        ]);

        $notification = new SiteMessage($data['title'], $data['content'] ?? '');
        $users = User::query();
        switch ($data['receiver']) {
            case 'normal':
                $users->where('permission', User::NORMAL);
                break;
            case 'uid':
                $users->whereKey($data['uid'])->firstOrFail();
                break;
            case 'email':
                $users->where('email_key', AccountIdentity::emailKey($data['email']))->firstOrFail();
                break;
        }
        $users->chunkById(250, static function (Collection $chunk) use ($notification): void {
            Notification::send($chunk, $notification);
        });

        if ($request->is('api/*')) {
            return response()->json(['message' => trans('admin.notifications.send.success')], 202);
        }
        $request->session()->flash('sentResult', trans('admin.notifications.send.success'));

        return redirect()->route('admin.view');
    }

    public function all(Request $request)
    {
        return response()->json($request->user()->unreadNotifications()->limit(50)->get()
            ->map(static fn (DatabaseNotification $notification) => [
                'id' => $notification->id,
                'title' => self::text($notification, 'title'),
            ]))->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, string $id, SafeMarkdown $markdown)
    {
        $notification = $request->user()->notifications()->findOrFail($id);

        return $this->details($notification, $markdown);
    }

    public function read(Request $request, string $id, SafeMarkdown $markdown)
    {
        $query = $request->user()->notifications();
        $notification = $query->findOrFail($id);
        $query->whereKey($id)->whereNull('read_at')->update(['read_at' => now()]);

        return $this->details($notification, $markdown);
    }

    private function details(DatabaseNotification $notification, SafeMarkdown $markdown)
    {
        return response()->json([
            'title' => self::text($notification, 'title'),
            'content' => $markdown->render(self::text($notification, 'content')),
            'time' => $notification->created_at->toDateTimeString(),
        ])->header('Cache-Control', 'private, no-store');
    }

    private static function text(DatabaseNotification $notification, string $key): string
    {
        $value = $notification->data[$key] ?? null;

        return is_string($value) ? $value : '';
    }
}
