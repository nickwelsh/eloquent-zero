<?php

namespace NickWelsh\EloquentZero\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Group extends Model
{
    protected $table = 'groups';

    public $timestamps = false;

    protected $guarded = [];

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Member::class, 'group_member');
    }
}
