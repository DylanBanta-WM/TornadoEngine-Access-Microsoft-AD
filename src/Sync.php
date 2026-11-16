<?php

namespace TornadoEngine\AccessMicrosoftAd;

use TornadoEngine\Access\Imports;
use TornadoEngine\AccessMicrosoftAd\Models\Rule;

/**
 * Gives each rule's role to the enabled AD users who match it, and takes it back from people
 * who don't anymore (through Access's Imports). Everything is read before anything changes, so
 * if the domain controller can't be reached, nothing changes.
 */
class Sync
{
    public const SOURCE = 'microsoft-ad';

    public function __construct(private Directory $directory, private Imports $imports) {}

    public function run(): string
    {
        $rules = Rule::all();
        $people = $rules->isEmpty() ? [] : array_filter($this->directory->users(), fn ($user) => ! $user['disabled']);
        $excluded = $this->imports->excluded();

        $wanted = [];
        $matched = [];
        foreach ($rules as $rule) {
            $emails = match ($rule->type) {
                'user' => [strtolower($rule->value)],
                'group' => $this->directory->groupMembers($rule->value),
                'ou' => array_keys(array_filter($people, fn ($user) => $rule->coversDn($user['dn']))),
            };
            $emails = array_filter($emails, fn ($email) => isset($people[$email]) && ! isset($excluded[$email]));

            $matched[$rule->id] = count($emails);
            foreach ($emails as $email) {
                $wanted[$rule->role_id][$email] = $people[$email]['name'];
            }
        }

        $result = $this->imports->apply(self::SOURCE, $wanted);
        foreach ($matched as $ruleId => $count) {
            Rule::whereKey($ruleId)->update(['matched' => $count]);
        }

        return trans_choice(':count person matched.|:count people matched.', $result['people'])
            .' '.__('Gave :gave, took back :back.', [
                'gave' => trans_choice(':count role|:count roles', $result['gave']),
                'back' => trans_choice(':count role|:count roles', $result['took_back']),
            ])
            .($result['added'] ? ' '.trans_choice('Made :count new account.|Made :count new accounts.', $result['added']) : '');
    }
}
