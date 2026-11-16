@php
    $code = 'rounded bg-gray-100 dark:bg-gray-900 px-1 py-0.5 font-mono text-[13px] text-gray-900 dark:text-gray-100';
@endphp

<x-setup.header :title="__('Set up Active Directory')" />

<div class="mt-6 px-6 sm:px-8 pb-6 sm:pb-8 space-y-6 text-sm text-gray-700 dark:text-gray-300">
    <section class="space-y-2">
        <h2 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('1. Make a read-only service account') }}</h2>
        <ol class="list-decimal ps-5 space-y-1">
            <li>{{ __('In Active Directory Users and Computers, create a user, for example svc-tornado.') }}</li>
            <li>{{ __('Give it a long, random password. Tick Password never expires, and untick User must change password at next logon.') }}</li>
            <li>{{ __('Don\'t add it to any groups. An ordinary domain user can already read users and groups.') }}</li>
        </ol>
    </section>

    <section class="space-y-2">
        <h2 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('2. Check LDAPS') }}</h2>
        <ol class="list-decimal ps-5 space-y-1">
            <li>{!! __('The Tornado Engine server needs to reach a domain controller on port :port.', ['port' => '<code class="'.$code.'">636</code>']) !!}</li>
            <li>{{ __('The domain controller needs a certificate for LDAPS. With an enterprise certificate authority (AD CS), domain controllers usually have one already.') }}</li>
        </ol>
    </section>

    <section class="space-y-2">
        <h2 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('3. Export your CA certificate') }}</h2>
        <p>{{ __('Needed when the domain controller\'s certificate comes from your own certificate authority.') }}</p>
        <ol class="list-decimal ps-5 space-y-1">
            <li>{!! __('On a domain-joined computer, run :cmd.', ['cmd' => '<code class="'.$code.'">certlm.msc</code>']) !!}</li>
            <li>{{ __('Open Trusted Root Certification Authorities → Certificates, and find your CA.') }}</li>
            <li>{{ __('Right-click it → All Tasks → Export. Choose Base-64 encoded X.509 (.CER).') }}</li>
            <li>{{ __('Open the file in Notepad and copy everything, including the BEGIN and END lines.') }}</li>
        </ol>
    </section>

    <section class="space-y-2">
        <h2 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('4. Connect') }}</h2>
        <p>{{ __('In Users and roles → Active Directory, enter:') }}</p>
        <ul class="list-disc ps-5 space-y-1">
            <li>{!! __('Domain controllers: their names, for example :example.', ['example' => '<code class="'.$code.'">dc1.district.local</code>']) !!}</li>
            <li>{!! __('Base DN: your domain, for example :example.', ['example' => '<code class="'.$code.'">DC=district,DC=local</code>']) !!}</li>
            <li>{!! __('Service account: :example, and its password.', ['example' => '<code class="'.$code.'">svc-tornado@district.local</code>']) !!}</li>
            <li>{{ __('CA certificate: what you copied in step 3.') }}</li>
        </ul>
    </section>

    <section class="space-y-2">
        <h2 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('5. Add rules') }}</h2>
        <ul class="list-disc ps-5 space-y-1">
            <li>{!! __('OUs use their distinguished name, for example :example. In Active Directory Users and Computers, turn on View → Advanced Features, open the OU\'s Properties → Attribute Editor, and copy distinguishedName.', ['example' => '<code class="'.$code.'">OU=Staff,DC=district,DC=local</code>']) !!}</li>
            <li>{{ __('Groups can use their name or distinguished name. Members of groups inside the group count.') }}</li>
            <li>{{ __('Users use their email address.') }}</li>
        </ul>
    </section>
</div>
