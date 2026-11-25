<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Plugin;
use App\Services\PluginManager;
use App\Services\PluginPackage;
use App\Services\SafeMarkdown;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class PluginController extends Controller
{
    public function config(Request $request, PluginManager $plugins, string $name)
    {
        abort_unless($request->user()?->permission === User::SUPER_ADMIN, 403);
        $plugin = $plugins->get($name);
        abort_unless($plugin?->isEnabled(), 404, trans('admin.plugins.operations.no-config-notice'));

        if ($class = $plugin->getConfigClass()) {
            return app()->call($class.'@render');
        }

        return $plugin->getConfigView() ?? abort(404, trans('admin.plugins.operations.no-config-notice'));
    }

    public function readme(PluginManager $plugins, string $name)
    {
        $plugin = $plugins->get($name);
        $readme = $plugin?->readReadme();
        abort_if($readme === null, 404, trans('admin.plugins.operations.no-readme-notice'));

        return view('admin.plugin.readme', [
            'title' => $plugin->displayTitle(),
            'content' => app(SafeMarkdown::class)->render($readme),
        ]);
    }

    public function manage(Request $request, PluginManager $plugins)
    {
        abort_unless($request->user()?->permission === User::SUPER_ADMIN, 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'regex:/\A'.PluginPackage::NAME_PATTERN.'\z/'],
            'action' => ['required', Rule::in(['enable', 'disable'])],
        ]);
        $plugin = $plugins->get($data['name']);
        if ($plugin === null) {
            return json(trans('admin.plugins.operations.not-found'), 1);
        }

        if ($data['action'] === 'enable') {
            $result = $plugins->enable($data['name']);
            if (is_array($result)) {
                $reason = $plugins->formatUnresolved($result['unsatisfied'], $result['conflicts']);

                return json(trans('admin.plugins.operations.unsatisfied.notice'), 1, compact('reason'));
            }
            if ($result === false) {
                return json('This exact plugin package has not been approved by deployment.', 1);
            }
            $message = 'admin.plugins.operations.enabled';
        } else {
            if (!$plugins->disable($data['name'])) {
                return json(trans('admin.plugins.operations.not-found'), 1);
            }
            $message = 'admin.plugins.operations.disabled';
        }

        Log::notice('Plugin activation setting changed.', [
            'actor_id' => $request->user()->getAuthIdentifier(),
            'plugin' => $data['name'],
            'sha256' => $plugin->getDigest(),
            'action' => $data['action'],
        ]);

        return json(trans($message, ['plugin' => $plugin->displayTitle()]), 0);
    }

    public function getPluginData(Request $request, PluginManager $plugins)
    {
        $canManage = $request->user()?->permission === User::SUPER_ADMIN;

        return $plugins->all()->map(function (Plugin $plugin) use ($canManage) {
            $icon = ['fa' => 'plug', 'faType' => 'fas', 'bg' => 'navy'];
            $spec = $plugin->getManifestAttr('enchants.icon', []);
            if (is_array($spec)) {
                foreach ($icon as $key => $default) {
                    $value = $spec[$key] ?? null;
                    if (is_string($value) && preg_match('/\A[a-z0-9-]{1,32}\z/', $value)) {
                        $icon[$key] = $value;
                    }
                }
            }

            return [
                'name' => $plugin->name,
                'title' => $plugin->displayTitle(),
                'description' => $plugin->displayText('description'),
                'version' => $plugin->version,
                'approved' => $plugin->isApproved(),
                'can_manage' => $canManage && $plugin->isApproved(),
                'enabled' => $plugin->isEnabled(),
                'readme' => $plugin->getReadme() !== null,
                'config' => $canManage && $plugin->isEnabled() && $plugin->hasConfig(),
                'icon' => $icon,
            ];
        })->values();
    }

    public function asset(PluginManager $plugins, string $name, string $digest, string $asset)
    {
        $plugin = $plugins->get($name);
        abort_unless($plugin?->isEnabled() && hash_equals($plugin->getDigest(), $digest), 404);
        $file = $plugin->getAsset($asset);
        abort_if($file === null, 404);

        // Serve the bytes we actually verified, not a path reopened by the web server.
        $maxBytes = 8 * 1024 * 1024;
        $bytes = @file_get_contents($file['path'], false, null, 0, $maxBytes + 1);
        abort_if($bytes === false || strlen($bytes) > $maxBytes
            || !hash_equals($file['sha256'], hash('sha256', $bytes)), 404);

        return response($bytes, 200, [
            'Content-Type' => $file['type'],
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
