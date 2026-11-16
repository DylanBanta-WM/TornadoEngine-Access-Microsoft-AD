<?php

namespace TornadoEngine\AccessMicrosoftAd\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use TornadoEngine\Access\Models\Role;

/**
 * Gives a role to the users in an OU ("ou", its DN, with sub-OUs if sub_ous), a group's members
 * ("group", its DN), or one user ("user", their email).
 */
class Rule extends Model
{
    public const TYPES = ['ou' => 'OU', 'group' => 'Group', 'user' => 'User'];

    protected $table = 'access_microsoft_ad_rules';

    protected $fillable = ['role_id', 'type', 'value', 'sub_ous', 'matched'];

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** Whether a user with this DN is in this (OU) rule's OU. */
    public function coversDn(string $dn): bool
    {
        $normal = fn (string $dn) => strtolower(preg_replace('/\s*(?<!\\\\),\s*/', ',', trim($dn)));
        $ou = $normal($this->value);
        $parent = preg_split('/(?<!\\\\),/', $normal($dn), 2)[1] ?? '';

        return $parent === $ou || ($this->sub_ous && str_ends_with($parent, ','.$ou));
    }

    protected function casts(): array
    {
        return ['sub_ous' => 'boolean', 'matched' => 'integer'];
    }
}
