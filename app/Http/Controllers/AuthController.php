<?php

namespace App\Http\Controllers;

use App\Events;
use App\Exceptions\PrettyPageException;
use App\Models\Player;
use App\Models\User;
use App\Rules;
use App\Services\Auth\AccountActions;
use App\Services\Auth\AccountIdentity;
use App\Services\Auth\AccountMail;
use App\Services\Auth\AccountSecurity;
use App\Services\Auth\AccountThrottle;
use App\Services\Auth\PasswordCipher;
use Blessing\Filter;
use Blessing\Rejection;
use Gregwar\Captcha\CaptchaBuilder;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class AuthController extends Controller
{
    public function __construct(
        private AccountThrottle $throttle,
        private AccountSecurity $security,
        private AccountMail $mail,
        private AccountActions $actions,
    ) {
    }

    public function login(Request $request, Filter $filter)
    {
        $rows = $filter->apply('auth_page_rows:login', [
            'auth.rows.login.notice',
            'auth.rows.login.message',
            'auth.rows.login.form',
            'auth.rows.login.registration-link',
        ]);

        return view('auth.login', [
            'rows' => $rows,
            'extra' => ['tooManyFails' => $this->throttle->loginAttempts(AccountIdentity::source($request)) >= AccountThrottle::CAPTCHA_AFTER],
        ]);
    }

    public function handleLogin(Request $request, Rules\Captcha $captcha, Dispatcher $dispatcher, Filter $filter, PasswordCipher $cipher)
    {
        $source = AccountIdentity::source($request);
        $attempts = $this->throttle->reserve('login', $source, 24, 60);
        $data = $request->validate(['identification' => 'required|string|max:100', 'password' => PasswordCipher::CURRENT_RULE]);
        if ($attempts > AccountThrottle::CAPTCHA_AFTER) {
            $request->validate(['captcha' => ['bail', 'required', 'string', $captcha]]);
        }

        $identification = $data['identification'];
        $secret = $data['password'];
        $authType = AccountIdentity::canMail($identification) ? 'email' : 'username';
        $dispatcher->dispatch('auth.login.attempt', [$identification, null, $authType]);
        event(new Events\UserTryToLogin($identification, $authType));

        $user = $authType === 'email'
            ? AccountIdentity::findByEmail($identification)
            : Player::where('name', $identification)->first()?->user;
        $subject = $user ? 'user:'.$user->uid : 'unknown:'.mb_strtolower($identification, 'UTF-8');
        $this->throttle->subject('login', $subject, 20, 900);
        $can = $filter->apply('can_login', null, [$identification, null]);
        $authenticated = false;
        if (!$user) {
            $cipher->dummyVerify($secret);
        } else {
            $dispatcher->dispatch('auth.login.ready', [$user]);
            if ($user->verifyPassword($secret) && $user->permission !== User::BANNED && !$can instanceof Rejection) {
                $authenticated = $this->security->serialized(function () use ($request, $cipher, $user, $secret) {
                    $locked = User::lockForUpdate()->find($user->uid);
                    if (!$locked || $locked->permission === User::BANNED || $locked->account_version !== $user->account_version
                        || !hash_equals($locked->password, $user->password)) {
                        return false;
                    }
                    if ($cipher->needsRehash($locked->password)) {
                        $locked->password = $this->security->hashNewPassword($secret);
                        if (!$locked->save()) {
                            throw new RuntimeException('Account password upgrade was cancelled.');
                        }
                    }
                    Auth::guard('web')->login($locked, $request->boolean('keep'));

                    return true;
                });
            }
        }
        if (!$authenticated) {
            $dispatcher->dispatch('auth.login.failed', [$user, $attempts]);

            return json(trans('auth.validation.password'), 1, ['login_fails' => $attempts]);
        }

        $current = Auth::guard('web')->user();
        $dispatcher->dispatch('auth.login.succeeded', [$current]);
        event(new Events\UserLoggedIn($current));

        return json(trans('auth.login.success'), 0, [
            'redirectTo' => $request->session()->pull('last_requested_path', url('/user')),
        ]);
    }

    public function logout(Request $request, Dispatcher $dispatcher)
    {
        $user = Auth::guard('web')->user();
        $dispatcher->dispatch('auth.logout.before', [$user]);
        $this->security->serialized(function () use ($user) {
            $current = User::lockForUpdate()->find($user->uid);
            if ($current && $current->account_version === $user->account_version) {
                $this->security->invalidate($current);
            }
        });
        Auth::guard('web')->logoutCurrentDevice();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $dispatcher->dispatch('auth.logout.after', [$user]);

        return json(trans('auth.logout.success'), 0);
    }

    public function register(Filter $filter)
    {
        return view('auth.register', [
            'site_name' => option_localized('site_name'),
            'rows' => $filter->apply('auth_page_rows:register', ['auth.rows.register.notice', 'auth.rows.register.form']),
            'extra' => ['player' => (bool) option('register_with_player_name')],
        ]);
    }

    public function handleRegister(Request $request, Rules\Captcha $captcha, Dispatcher $dispatcher, Filter $filter)
    {
        $source = AccountIdentity::source($request);
        $this->throttle->reserve('register', $source, 10, 600);
        $can = $filter->apply('can_register', null);
        if ($can instanceof Rejection) {
            return json($can->getReason(), 1);
        }

        $withPlayer = (bool) option('register_with_player_name');
        $data = $request->validate([
            'email' => AccountIdentity::emailRules(),
            'password' => PasswordCipher::NEW_RULE,
            'captcha' => ['bail', 'required', 'string', $captcha],
        ] + ($withPlayer ? ['player_name' => [
            'bail', 'required', 'string', 'min:'.(int) option('player_name_length_min'),
            'max:'.min(50, (int) option('player_name_length_max')), new Rules\PlayerName(),
        ]] : ['nickname' => 'required|string|max:50']));
        $email = AccountIdentity::email($data['email']);
        $subject = $data;
        unset($subject['password'], $subject['captcha']);
        $dispatcher->dispatch('auth.registration.attempt', [$subject]);
        $password = $this->security->hashNewPassword($data['password']);
        $maxRegistrations = (int) option('regs_per_ip');
        try {
            $result = DB::transaction(function () use ($dispatcher, $withPlayer, $source, $email, $data, $subject, $password, $maxRegistrations) {
                DB::table('account_registration_sources')->insertOrIgnore(['ip' => $source]);
                DB::table('account_registration_sources')->where('ip', $source)->lockForUpdate()->first();
                if (User::where('ip', $source)->count() >= $maxRegistrations) {
                    return json(trans('auth.register.max', ['regs' => $maxRegistrations]), 1);
                }
                if (AccountIdentity::findByEmail($email)) {
                    throw ValidationException::withMessages(['email' => trans('validation.unique', ['attribute' => trans('auth.email')])]);
                }
                if ($withPlayer && Player::where('name', $data['player_name'])->exists()) {
                    return json(trans('user.player.add.repeated'), 1);
                }
                $dispatcher->dispatch('auth.registration.ready', [$subject]);
                $user = new User();
                $user->email = $email;
                $user->nickname = $data[$withPlayer ? 'player_name' : 'nickname'];
                $user->score = (int) option('user_initial_score');
                $user->password = $password;
                $user->ip = $source;
                $user->permission = User::NORMAL;
                $user->register_at = now();
                $user->last_sign_at = now()->subDay();
                $user->account_version = 1;
                if (!$user->save()) {
                    throw new RuntimeException('Account creation was cancelled.');
                }
                $player = null;
                if ($withPlayer) {
                    $dispatcher->dispatch('player.adding', [$data['player_name'], $user]);
                    $player = new Player();
                    $player->uid = $user->uid;
                    $player->name = $data['player_name'];
                    $player->tid_skin = 0;
                    if (!$player->save()) {
                        throw new RuntimeException('Player creation was cancelled.');
                    }
                }
                DB::afterCommit(function () use ($dispatcher, $user, $player) {
                    $dispatcher->dispatch('auth.registration.completed', [$user]);
                    event(new Events\UserRegistered($user));
                    if ($player) {
                        $dispatcher->dispatch('player.added', [$player, $user]);
                        event(new Events\PlayerWasAdded($player));
                    }
                });

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            if (AccountIdentity::findByEmail($email)) {
                throw ValidationException::withMessages(['email' => trans('validation.unique', ['attribute' => trans('auth.email')])]);
            }

            return json(trans('user.player.add.repeated'), 1);
        }
        if (!$result instanceof User) {
            return $result;
        }
        $dispatcher->dispatch('auth.login.ready', [$result]);
        $this->security->serialized(function () use ($result) {
            $current = $this->security->lockUser($result->uid);
            $this->security->assertCurrent($result, $current);
            Auth::guard('web')->login($current);
        });
        $dispatcher->dispatch('auth.login.succeeded', [$result]);

        return json(trans('auth.register.success'), 0);
    }

    public function forgot()
    {
        if (!$this->mail->enabled()) {
            throw new PrettyPageException(trans('auth.forgot.disabled'), 8);
        }

        return view('auth.forgot');
    }

    public function handleForgot(Request $request, Rules\Captcha $captcha, Dispatcher $dispatcher)
    {
        $source = AccountIdentity::source($request);
        $this->throttle->reserve('forgot', $source, 10, 600);
        $data = $request->validate([
            'email' => AccountIdentity::emailRules(),
            'captcha' => ['bail', 'required', 'string', $captcha],
        ]);
        $email = AccountIdentity::email($data['email']);
        $this->throttle->subject('forgot', $email, 1, 180);
        $dispatcher->dispatch('auth.forgot.attempt', [$email]);
        $this->mail->reset($email);

        return json(trans('auth.forgot.success'), 0);
    }

    public function reset(Request $request, int $uid)
    {
        $user = $this->actions->preview($uid, AccountActions::RESET, $request->query('token'));

        return response()->view('auth.reset', ['user' => $user])->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function handleReset(Dispatcher $dispatcher, Request $request, int $uid)
    {
        $this->throttle->reserve('reset', AccountIdentity::source($request), 20, 600);
        $token = $request->query('token');
        // Prevent a validation redirect/flash path from retaining an invalid action URL.
        $this->actions->preview($uid, AccountActions::RESET, $token);
        ['password' => $password] = $request->validate(['password' => PasswordCipher::NEW_RULE]);
        $this->actions->consume($uid, AccountActions::RESET, $token, function (User $user) use ($dispatcher, $password) {
            $dispatcher->dispatch('auth.reset.before', [$user, null]);
            $this->security->updatePassword($user, $password);
            DB::afterCommit(fn () => $dispatcher->dispatch('auth.reset.after', [$user, null]));
        });
        if ((int) Auth::guard('web')->id() === $uid) {
            Auth::guard('web')->logoutCurrentDevice();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return json(trans('auth.reset.success'), 0)->header('Cache-Control', 'no-store');
    }

    public function captcha(Request $request, CaptchaBuilder $builder)
    {
        $this->throttle->reserve('captcha', AccountIdentity::source($request), 30, 60);
        $builder->build(100, 34, $this->captchaFont());
        $request->session()->put('captcha', ['phrase' => $builder->getPhrase(), 'expires_at' => time() + 300]);
        ob_start();
        try {
            $builder->output();
            $image = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        return response($image, 200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'no-store']);
    }

    private function captchaFont(): ?string
    {
        if (PHP_OS_FAMILY !== 'Windows' || ($cwd = getcwd()) === false) {
            return null;
        }

        $source = (new \ReflectionClass(CaptchaBuilder::class))->getFileName();
        if ($source === false) {
            return null;
        }
        $font = str_replace('\\', '/', dirname($source)).'/Font/captcha'.random_int(0, 5).'.ttf';
        // Windows GD's FreeType path handling may reject a Unicode absolute
        // checkout path. Prefer the same bundled font via the real working directory.
        $from = explode('/', rtrim(str_replace('\\', '/', $cwd), '/'));
        $to = explode('/', $font);
        $shared = 0;
        while (isset($from[$shared], $to[$shared]) && strcasecmp($from[$shared], $to[$shared]) === 0) {
            $shared++;
        }
        $minimumShared = str_starts_with($font, '//') ? 4 : 1;
        if ($shared < $minimumShared) {
            return null;
        }
        $relative = './'.str_repeat('../', count($from) - $shared).implode('/', array_slice($to, $shared));

        return is_file($relative) ? $relative : null;
    }

    public function fillEmail(Request $request)
    {
        $actor = $request->user();
        $this->throttle->reserve('reauth', AccountIdentity::source($request), 20, 600, (string) $actor->uid, 6, 600);
        $data = $request->validate(['email' => AccountIdentity::emailRules(), 'password' => PasswordCipher::CURRENT_RULE]);
        try {
            $updated = $this->security->serialized(function () use ($actor, $data) {
                $user = $this->security->lockUser($actor->uid);
                $this->security->assertCurrent($actor, $user);
                abort_if(AccountIdentity::canMail($user->email), 409);
                if (!$user->verifyPassword($data['password'])) {
                    throw ValidationException::withMessages(['password' => trans('user.profile.email.wrong-password')]);
                }

                return $this->security->updateEmail($user, $data['email']);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => trans('validation.unique', ['attribute' => trans('auth.email')])]);
        }
        if ($updated) {
            Auth::guard('web')->logoutCurrentDevice();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return redirect()->route('auth.login');
    }

    public function verify(Request $request, User $user)
    {
        abort_unless(option('require_verification'), 403, trans('user.verification.disabled'));
        $this->actions->preview($user->uid, AccountActions::VERIFY, $request->query('token'));

        return response()->view('auth.verify')->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function handleVerify(Request $request, User $user)
    {
        abort_unless(option('require_verification'), 403, trans('user.verification.disabled'));
        $this->throttle->reserve('verify', AccountIdentity::source($request), 20, 600);
        $this->actions->consume($user->uid, AccountActions::VERIFY, $request->query('token'), function (User $current) {
            $current->verified = true;
            if (!$current->save()) {
                throw new RuntimeException('Account verification was cancelled.');
            }
        });

        return redirect()->route('user.home')->header('Cache-Control', 'no-store');
    }
}
