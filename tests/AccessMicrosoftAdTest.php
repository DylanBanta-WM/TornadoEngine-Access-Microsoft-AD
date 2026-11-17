<?php

namespace TornadoEngine\AccessMicrosoftAd\Tests;

use App\Models\Setting;
use App\Models\User;
use App\Support\SignIn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;
use TornadoEngine\Access\Models\Exclusion;
use TornadoEngine\Access\Models\Role;
use TornadoEngine\AccessMicrosoftAd\Connection;
use TornadoEngine\AccessMicrosoftAd\Directory;
use TornadoEngine\AccessMicrosoftAd\Models\Rule;
use TornadoEngine\Cron\Cron;
use TornadoEngine\Cron\Models\CronJob;

class AccessMicrosoftAdTest extends TestCase
{
    use RefreshDatabase;

    public FakeDirectory $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = new FakeDirectory;
        $this->app->instance(Directory::class, $this->directory);
        Connection::save(['hosts' => 'dc1.district.local', 'encryption' => 'ldaps', 'base_dn' => 'DC=district,DC=local', 'username' => 'svc', 'password' => 'secret']);
    }

    private function sync(): ?bool
    {
        return app(Cron::class)->run(app(Cron::class)->find('access-microsoft-ad.sync'));
    }

    private function rolesOf(string $email): array
    {
        return User::where('email', $email)->first()?->roles()->orderBy('name')->pluck('name')->all() ?? [];
    }

    private function manager(): User
    {
        return User::factory()->create(['is_admin' => true, 'email' => 'it@district.org']);
    }

    public function test_roles_come_from_ous_groups_and_users(): void
    {
        $staff = Role::firstOrCreate(['name' => 'Staff']);
        $hs = Role::create(['name' => 'High school']);
        $teachers = Role::create(['name' => 'Teachers']);
        $tech = Role::create(['name' => 'Tech']);
        Rule::create(['role_id' => $staff->id, 'type' => 'ou', 'value' => 'OU=Staff,DC=district,DC=local', 'sub_ous' => true]);
        Rule::create(['role_id' => $hs->id, 'type' => 'ou', 'value' => 'ou=HS, ou=Staff, dc=district, dc=local', 'sub_ous' => false]);
        Rule::create(['role_id' => $teachers->id, 'type' => 'group', 'value' => 'CN=Teachers,OU=Groups,DC=district,DC=local']);
        Rule::create(['role_id' => $tech->id, 'type' => 'user', 'value' => 'cy@district.org']);

        $this->assertTrue($this->sync(), (string) CronJob::find('access-microsoft-ad.sync')?->last_message);

        $this->assertSame(['High school', 'Staff', 'Teachers'], $this->rolesOf('ann@district.org'));
        $this->assertSame(['Staff'], $this->rolesOf('bob@district.org'), 'sub-OU of HS, which has no sub-OUs');
        $this->assertSame(['Tech'], $this->rolesOf('cy@district.org'));
        $this->assertNull(User::where('email', 'gone@district.org')->first(), 'disabled accounts get nothing');
        $this->assertSame('Ann Teacher', User::where('email', 'ann@district.org')->value('name'));
    }

    public function test_people_who_leave_or_are_excluded_lose_the_role(): void
    {
        $staff = Role::firstOrCreate(['name' => 'Staff']);
        Rule::create(['role_id' => $staff->id, 'type' => 'ou', 'value' => 'OU=Staff,DC=district,DC=local', 'sub_ous' => true]);
        $this->sync();
        $this->assertSame(['Staff'], $this->rolesOf('bob@district.org'));

        $this->directory->people['bob@district.org']['disabled'] = true;
        Exclusion::create(['email' => 'ann@district.org', 'reason' => 'Service account']);
        $this->sync();

        $this->assertSame([], $this->rolesOf('bob@district.org'));
        $this->assertSame([], $this->rolesOf('ann@district.org'));
    }

    public function test_nothing_changes_when_ad_cant_be_reached(): void
    {
        $staff = Role::firstOrCreate(['name' => 'Staff']);
        Rule::create(['role_id' => $staff->id, 'type' => 'ou', 'value' => 'OU=Staff,DC=district,DC=local', 'sub_ous' => true]);
        $this->sync();

        $this->directory->down = true;
        $this->assertFalse($this->sync());
        $this->assertSame(['Staff'], $this->rolesOf('ann@district.org'));
    }

    public function test_the_connection_is_only_saved_if_it_works(): void
    {
        $manager = $this->manager();
        $settings = ['hosts' => 'dc9.district.local', 'encryption' => 'starttls', 'base_dn' => 'DC=district,DC=local', 'username' => 'svc', 'password' => 'new-secret'];

        $this->directory->problem = 'The domain controller didn\'t accept the service account\'s username and password.';
        $this->actingAs($manager)->from('/access/active-directory')->post('/access/active-directory/connection', $settings)->assertSessionHasErrors('connection');
        $this->assertSame('dc1.district.local', Setting::get('ad.hosts'));

        $this->directory->problem = null;
        $this->actingAs($manager)->post('/access/active-directory/connection', $settings)->assertSessionHas('status');
        $this->assertSame('dc9.district.local', Setting::get('ad.hosts'));
        $this->assertSame('new-secret', Setting::getSecret('ad.password'));
        $this->assertSame(389, Connection::config()['port']);

        $this->actingAs($manager)->get('/access/active-directory')->assertSee('dc9.district.local')->assertDontSee('new-secret');
    }

    public function test_rules_are_checked_against_ad(): void
    {
        $role = Role::create(['name' => 'Teachers']);
        $manager = $this->manager();

        $this->actingAs($manager)->from('/access/active-directory')->post('/access/active-directory/rules', ['role_id' => $role->id, 'type' => 'ou', 'value' => 'OU=Nope,DC=district,DC=local'])
            ->assertSessionHasErrors('value');
        $this->actingAs($manager)->post('/access/active-directory/rules', ['role_id' => $role->id, 'type' => 'group', 'value' => 'Teachers'])->assertSessionHasNoErrors();

        $this->assertSame('CN=Teachers,OU=Groups,DC=district,DC=local', Rule::sole()->value, 'group names become DNs');
        $this->actingAs($manager)->get('/access/active-directory')->assertSee('CN=Teachers,OU=Groups,DC=district,DC=local');
    }

    public function test_people_can_sign_in_with_ad_passwords_when_its_on(): void
    {
        Setting::put('setup.completed_at', now()->toIso8601String());
        SignIn::save(['google']);
        $ann = User::factory()->create(['email' => 'ann@district.org']);

        $this->post('/login', ['email' => 'ann', 'password' => 'ad-password'])->assertNotFound();

        Setting::put('ad.signin', '1');
        $this->get('/login')->assertSee('Email or username');
        $this->post('/login', ['email' => 'ann', 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => 'ann', 'password' => 'ad-password'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($ann);
    }

    public function test_people_a_roles_primary_rule_missed_are_listed(): void
    {
        $teachers = Role::create(['name' => 'Teachers']);
        $group = Rule::create(['role_id' => $teachers->id, 'type' => 'group', 'value' => 'CN=Teachers,OU=Groups,DC=district,DC=local']);
        Rule::create(['role_id' => $teachers->id, 'type' => 'ou', 'value' => 'OU=Staff,DC=district,DC=local', 'sub_ous' => true]);
        $manager = $this->manager();

        $this->actingAs($manager)->post("/access/active-directory/rules/{$group->id}/primary");
        $this->sync();

        $this->actingAs($manager)->get('/access/active-directory')
            ->assertSee('People the primary rule for Teachers missed (1)')
            ->assertSeeInOrder(['Bob Office', 'bob@district.org', 'Caught by the OU OU=Staff,DC=district,DC=local.']);
    }

    public function test_only_people_who_manage_access_can_open_it(): void
    {
        $this->actingAs(User::factory()->create())->get('/access/active-directory')->assertForbidden();
        $this->actingAs($this->manager())->get('/access/users')->assertSee('Active Directory');
    }
}

class FakeDirectory implements Directory
{
    public bool $down = false;

    public ?string $problem = null;

    public array $people = [
        'ann@district.org' => ['email' => 'ann@district.org', 'name' => 'Ann Teacher', 'dn' => 'CN=Ann Teacher,OU=HS,OU=Staff,DC=district,DC=local', 'disabled' => false],
        'bob@district.org' => ['email' => 'bob@district.org', 'name' => 'Bob Office', 'dn' => 'CN=Bob Office,OU=Office,OU=HS,OU=Staff,DC=district,DC=local', 'disabled' => false],
        'cy@district.org' => ['email' => 'cy@district.org', 'name' => 'Cy Tech', 'dn' => 'CN=Cy Tech,OU=Tech,DC=district,DC=local', 'disabled' => false],
        'gone@district.org' => ['email' => 'gone@district.org', 'name' => 'Gone', 'dn' => 'CN=Gone,OU=Staff,DC=district,DC=local', 'disabled' => true],
    ];

    private array $groups = ['CN=Teachers,OU=Groups,DC=district,DC=local' => ['ann@district.org', 'gone@district.org']];

    public function users(): array
    {
        if ($this->down) {
            throw new RuntimeException('Can\'t contact LDAP server');
        }

        return $this->people;
    }

    public function groupMembers(string $groupDn): array
    {
        return $this->groups[$groupDn] ?? [];
    }

    public function findGroup(string $nameOrDn): ?string
    {
        foreach (array_keys($this->groups) as $dn) {
            if ($dn === $nameOrDn || str_starts_with($dn, 'CN='.$nameOrDn.',')) {
                return $dn;
            }
        }

        return null;
    }

    public function exists(string $dn): bool
    {
        return str_contains($dn, 'OU=Staff') || isset($this->groups[$dn]);
    }

    public function check(): ?string
    {
        return $this->problem;
    }

    public function authenticate(string $login, string $password): ?string
    {
        return $login === 'ann' && $password === 'ad-password' ? 'ann@district.org' : null;
    }
}
