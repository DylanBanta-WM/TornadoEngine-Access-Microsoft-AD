<?php

namespace TornadoEngine\AccessMicrosoftAd\Http;

use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule as ValidationRule;
use Illuminate\View\View;
use Throwable;
use TornadoEngine\Access\Models\Role;
use TornadoEngine\AccessMicrosoftAd\AccessMicrosoftAdServiceProvider;
use TornadoEngine\AccessMicrosoftAd\Connection;
use TornadoEngine\AccessMicrosoftAd\Directory;
use TornadoEngine\AccessMicrosoftAd\LdapDirectory;
use TornadoEngine\AccessMicrosoftAd\Models\Rule;
use TornadoEngine\Cron\Cron;

class AdController extends Controller
{
    public function index(Cron $cron): View
    {
        $job = $cron->find(AccessMicrosoftAdServiceProvider::JOB);

        return view('access-microsoft-ad::index', [
            'configured' => Connection::configured(),
            'saved' => Connection::saved(),
            'signIn' => Connection::signInEnabled(),
            'rules' => Rule::with('role')->get()->sortBy(fn (Rule $rule) => [$rule->role->name, ! $rule->primary, $rule->type, $rule->value]),
            'roles' => Role::orderBy('name')->get(),
            'missed' => array_filter(json_decode(Setting::get('access_microsoft_ad.missed') ?? '[]', true) ?: [], fn ($section, $roleId) => Rule::where('role_id', $roleId)->where('primary', true)->exists(), ARRAY_FILTER_USE_BOTH),
            'settings' => $cron->settings($job),
            'schedule' => $cron->scheduleFor($job),
        ]);
    }

    /**
     * Saves the connection, only if it works.
     */
    public function connection(Request $request): RedirectResponse
    {
        $data = $request->validate(Connection::rules());

        if ($problem = $this->directory($data)->check()) {
            return back()->withErrors(['connection' => $problem])->withInput($request->except('password'));
        }
        Connection::save($data);

        return back()->with('status', __('Connected to Active Directory.'));
    }

    public function signIn(Request $request): RedirectResponse
    {
        Setting::put('ad.signin', $request->boolean('signin') ? '1' : '0');

        return back()->with('status', __('Saved.'));
    }

    public function store(Request $request, Directory $directory): RedirectResponse
    {
        $data = $request->validate([
            'role_id' => ['required', 'integer', ValidationRule::exists('access_roles', 'id')],
            'type' => ['required', ValidationRule::in(array_keys(Rule::TYPES))],
            'value' => ['required', 'string', 'max:500'],
            'sub_ous' => ['boolean'],
        ], [], ['role_id' => __('role'), 'value' => __('value')]);
        $value = trim($data['value']);

        // Check it exists now, so a typo doesn't make every nightly sync fail.
        try {
            $value = match ($data['type']) {
                'ou' => $directory->exists($value) ? $value : null,
                'group' => $directory->findGroup($value),
                'user' => isset($directory->users()[strtolower($value)]) ? strtolower($value) : null,
            };
        } catch (Throwable $e) {
            return back()->withErrors(['value' => __('Couldn\'t reach Active Directory: :reason', ['reason' => $e->getMessage()])])->withInput();
        }
        if ($value === null) {
            return back()->withErrors(['value' => __('Active Directory doesn\'t have that :thing.', ['thing' => strtolower(__(Rule::TYPES[$data['type']]))])])->withInput();
        }

        Rule::firstOrCreate(
            ['role_id' => $data['role_id'], 'type' => $data['type'], 'value' => $value],
            ['sub_ous' => $data['type'] === 'ou' && $request->boolean('sub_ous')],
        );

        return back()->with('status', __('Added. People get the role at the next sync, or click Sync now.'));
    }

    /** Makes this its role's primary rule, or stops it being primary. One per role. */
    public function primary(Rule $rule): RedirectResponse
    {
        $makePrimary = ! $rule->primary;
        Rule::where('role_id', $rule->role_id)->update(['primary' => false]);
        $rule->update(['primary' => $makePrimary]);

        return back()->with('status', __('Saved. Click Sync now to check the other rules against it.'));
    }

    public function destroy(Rule $rule): RedirectResponse
    {
        $rule->delete();

        return back()->with('status', __('Removed. People who only had the role from this rule lose it at the next sync, or click Sync now.'));
    }

    public function sync(Cron $cron): RedirectResponse
    {
        $job = $cron->find(AccessMicrosoftAdServiceProvider::JOB);
        $ok = $cron->run($job, wait: false);

        if ($ok === null) {
            return back()->withErrors(['sync' => __('Another scheduled job is running. Jobs run one at a time, so try again when it finishes.')]);
        }

        $message = $cron->settings($job)->last_message;

        return $ok
            ? back()->with('status', __('Synced. :summary', ['summary' => $message]))
            : back()->withErrors(['sync' => __('The sync failed, so nothing changed: :reason', ['reason' => $message])]);
    }

    /** The directory for settings being checked (tests swap Directory for a stand-in). */
    private function directory(array $data): Directory
    {
        $bound = app(Directory::class);

        return $bound instanceof LdapDirectory ? new LdapDirectory($data) : $bound;
    }
}
