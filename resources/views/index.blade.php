@php
    $select = 'mt-1 block w-full min-h-11 rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 text-base focus:border-indigo-500 focus:ring-indigo-500';
    $placeholders = ['ou' => 'OU=Staff,DC=district,DC=local', 'group' => 'Teachers', 'user' => 'name@district.org'];
@endphp

<x-admin-layout :title="__('Users and roles')">
    @include('access::tabs')

    @if ($errors->has('sync'))
        <p class="rounded-md bg-red-50 dark:bg-red-900/30 px-4 py-3 text-sm text-red-800 dark:text-red-300">{{ $errors->first('sync') }}</p>
    @endif

    <x-settings-card :title="__('Connection')">
        @if (Route::has('docs.show'))
            <p><a href="{{ route('docs.show', 'active-directory') }}" class="font-medium text-indigo-600 dark:text-indigo-400 underline">{{ __('How to set up Active Directory') }}</a></p>
        @endif

        <div x-data="{ editing: @js(! $configured || $errors->hasAny(['connection', 'hosts', 'base_dn', 'username', 'password', 'ca_cert'])) }" class="space-y-4">
            @if ($configured)
                <div x-show="! editing" class="space-y-4">
                    <dl class="space-y-1 break-all">
                        <div><dt class="inline font-medium text-gray-900 dark:text-gray-100">{{ __('Server:') }}</dt> <dd class="inline">{{ $saved['hosts'] }} ({{ $saved['encryption'] === 'ldaps' ? 'LDAPS' : 'STARTTLS' }})</dd></div>
                        <div><dt class="inline font-medium text-gray-900 dark:text-gray-100">{{ __('Base DN:') }}</dt> <dd class="inline">{{ $saved['base_dn'] }}</dd></div>
                        <div><dt class="inline font-medium text-gray-900 dark:text-gray-100">{{ __('Service account:') }}</dt> <dd class="inline">{{ $saved['username'] }}</dd></div>
                    </dl>
                    <div class="flex justify-end">
                        <x-secondary-button type="button" x-on:click="editing = true">{{ __('Change') }}</x-secondary-button>
                    </div>
                </div>
            @endif

            <form x-show="editing" @if ($configured) x-cloak @endif method="POST" action="{{ route('access.ad.connection') }}" class="space-y-4">
                @csrf
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div class="sm:col-span-2">
                        <x-input-label for="hosts" :value="__('Domain controllers')" />
                        <x-text-input id="hosts" name="hosts" class="mt-1 block w-full" type="text" :value="old('hosts', $saved['hosts'])" required placeholder="dc1.district.local, dc2.district.local" autocapitalize="off" />
                        <x-input-error :messages="$errors->get('hosts')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="encryption" :value="__('Encryption')" />
                        <select id="encryption" name="encryption" class="{{ $select }}">
                            <option value="ldaps" @selected(old('encryption', $saved['encryption'] ?: 'ldaps') === 'ldaps')>LDAPS (636)</option>
                            <option value="starttls" @selected(old('encryption', $saved['encryption']) === 'starttls')>STARTTLS (389)</option>
                        </select>
                    </div>
                </div>
                <div>
                    <x-input-label for="base_dn" :value="__('Base DN')" />
                    <x-text-input id="base_dn" name="base_dn" class="mt-1 block w-full" type="text" :value="old('base_dn', $saved['base_dn'])" required placeholder="DC=district,DC=local" autocapitalize="off" />
                    <x-input-error :messages="$errors->get('base_dn')" class="mt-2" />
                </div>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <x-input-label for="username" :value="__('Service account')" />
                        <x-text-input id="username" name="username" class="mt-1 block w-full" type="text" :value="old('username', $saved['username'])" required placeholder="svc-tornado@district.local" autocapitalize="off" autocomplete="off" />
                    </div>
                    <div>
                        <x-input-label for="ad_password" :value="__('Password')" />
                        <x-text-input id="ad_password" name="password" class="mt-1 block w-full" type="password" required autocomplete="off" />
                    </div>
                </div>
                <div>
                    <x-input-label for="ca_cert" :value="__('CA certificate (optional)')" />
                    <textarea id="ca_cert" name="ca_cert" rows="3" placeholder="-----BEGIN CERTIFICATE-----" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 font-mono text-xs focus:border-indigo-500 focus:ring-indigo-500">{{ old('ca_cert', $saved['ca_cert']) }}</textarea>
                    <x-input-error :messages="$errors->get('ca_cert')" class="mt-2" />
                </div>
                <x-input-error :messages="$errors->get('connection')" />
                <div class="flex justify-end gap-3">
                    @if ($configured)
                        <x-secondary-button type="button" x-on:click="editing = false">{{ __('Cancel') }}</x-secondary-button>
                    @endif
                    <x-primary-button>{{ __('Check and save') }}</x-primary-button>
                </div>
            </form>
        </div>
    </x-settings-card>

    @if ($configured)
        <x-settings-card :title="__('Roles from Active Directory')">
            @if ($rules->isNotEmpty())
                <ul class="divide-y divide-gray-200 dark:divide-gray-700 rounded-md border border-gray-200 dark:border-gray-700">
                    @foreach ($rules as $rule)
                        <li class="flex flex-col gap-1 px-3 py-2 sm:flex-row sm:items-center sm:justify-between sm:gap-3">
                            <div class="min-w-0">
                                <p class="font-medium text-gray-900 dark:text-gray-100">{{ $rule->role->name }}</p>
                                <p class="break-all text-gray-600 dark:text-gray-400">{{ __(\TornadoEngine\AccessMicrosoftAd\Models\Rule::TYPES[$rule->type]) }}: <code class="font-mono">{{ $rule->value }}</code>@if ($rule->type === 'ou' && $rule->sub_ous) {{ __('and sub-OUs') }}@endif</p>
                                @if ($rule->matched !== null)
                                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ trans_choice(':count person at the last sync|:count people at the last sync', $rule->matched) }}</p>
                                @endif
                            </div>
                            <form method="POST" action="{{ route('access.ad.rules.destroy', $rule) }}" class="-ms-2 sm:ms-0">
                                @csrf
                                @method('DELETE')
                                <button class="min-h-11 px-2 text-sm text-red-700 dark:text-red-400 underline">{{ __('Remove') }}</button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($roles->isEmpty())
                <p>{!! __('Make a role on the :roles tab first.', ['roles' => '<a href="'.route('access.roles.index').'" class="font-medium text-indigo-600 dark:text-indigo-400 underline">'.e(__('Roles')).'</a>']) !!}</p>
            @else
                <form method="POST" action="{{ route('access.ad.rules.store') }}" class="space-y-3" x-data="{ type: @js(old('type', 'ou')) }">
                    @csrf
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div>
                            <x-input-label for="role_id" :value="__('Role')" />
                            <select id="role_id" name="role_id" class="{{ $select }}" required>
                                @foreach ($roles as $role)
                                    <option value="{{ $role->id }}" @selected((int) old('role_id') === $role->id)>{{ $role->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <x-input-label for="type" :value="__('Given to')" />
                            <select id="type" name="type" x-model="type" class="{{ $select }}">
                                @foreach (\TornadoEngine\AccessMicrosoftAd\Models\Rule::TYPES as $type => $label)
                                    <option value="{{ $type }}">{{ __($label) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <x-input-label for="value" x-text="{ ou: @js(__('OU (distinguished name)')), group: @js(__('Group name or DN')), user: @js(__('Email address')) }[type]">{{ __('Value') }}</x-input-label>
                            <x-text-input id="value" name="value" class="mt-1 block w-full" type="text" :value="old('value')" required autocapitalize="off" spellcheck="false" x-bind:placeholder="{{ Js::from($placeholders) }}[type]" />
                        </div>
                    </div>
                    <x-input-error :messages="$errors->get('value')" />
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <label x-show="type === 'ou'" class="inline-flex min-h-11 items-center gap-2">
                            <input type="hidden" name="sub_ous" value="0">
                            <input type="checkbox" name="sub_ous" value="1" checked class="rounded text-indigo-600 border-gray-300 dark:border-gray-700 dark:bg-gray-900 focus:ring-indigo-500">
                            <span>{{ __('Include sub-OUs') }}</span>
                        </label>
                        <x-primary-button class="ms-auto">{{ __('Add rule') }}</x-primary-button>
                    </div>
                </form>
            @endif
        </x-settings-card>

        <x-settings-card :title="__('Sync')">
            <p>
                @if ($settings->last_run_at)
                    {{ __('Last sync :time:', ['time' => $settings->last_run_at->diffForHumans()]) }}
                    <span @class(['font-medium', 'text-green-700 dark:text-green-400' => $settings->last_status === 'ok', 'text-red-700 dark:text-red-400' => $settings->last_status !== 'ok'])>{{ $settings->last_status === 'ok' ? __('worked') : __('failed') }}</span>
                    @if ($settings->last_message) — {{ $settings->last_message }}@endif
                @else
                    {{ __('Hasn\'t synced yet.') }}
                @endif
            </p>
            <p class="text-gray-600 dark:text-gray-400">
                {{ $settings->enabled ? __('Runs on its own: :when', ['when' => \TornadoEngine\Cron\Explain::describe($schedule)]) : __('The nightly sync is turned off.') }}
                @can('administration')
                    <a href="{{ route('administration.tab', 'cron') }}" class="font-medium text-indigo-600 dark:text-indigo-400 underline">{{ __('Change on Scheduled jobs') }}</a>
                @endcan
            </p>
            <form method="POST" action="{{ route('access.ad.sync') }}" class="flex justify-end">
                @csrf
                <x-primary-button>{{ __('Sync now') }}</x-primary-button>
            </form>
        </x-settings-card>

        <x-settings-card :title="__('Sign-in')">
            <form method="POST" action="{{ route('access.ad.signin') }}" class="space-y-3">
                @csrf
                <label class="flex min-h-11 items-center gap-3">
                    <input type="hidden" name="signin" value="0">
                    <input type="checkbox" name="signin" value="1" class="rounded text-indigo-600 border-gray-300 dark:border-gray-700 dark:bg-gray-900 focus:ring-indigo-500" @checked($signIn)>
                    <span>{{ __('Sign in with Active Directory usernames and passwords') }}</span>
                </label>
                <p class="text-gray-600 dark:text-gray-400">{{ __('Only for districts that don\'t use Microsoft sign-in (Entra ID).') }}</p>
                <div class="flex justify-end">
                    <x-secondary-button type="submit">{{ __('Save') }}</x-secondary-button>
                </div>
            </form>
        </x-settings-card>
    @endif
</x-admin-layout>
