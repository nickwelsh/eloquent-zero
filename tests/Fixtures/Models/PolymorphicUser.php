<?php

namespace NickWelsh\EloquentZero\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class PolymorphicUser extends Model
{
    protected $table = 'polymorphic_users';

    public $timestamps = false;

    protected $guarded = [];

    public function roles(): MorphToMany
    {
        return $this->morphToMany(PolymorphicRole::class, 'model', 'model_has_roles', 'model_id', 'role_id');
    }
}
