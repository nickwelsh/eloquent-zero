<?php

namespace NickWelsh\EloquentZero\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Member extends Model
{
    protected $table = 'members';

    public $timestamps = false;

    protected $guarded = [];

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'group_member');
    }
}
