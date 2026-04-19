<?php

namespace NickWelsh\EloquentZero\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use NickWelsh\EloquentZero\Tests\Fixtures\Enums\Status;

class TodoList extends Model
{
    protected $table = 'todo_lists';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => Status::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function todoItems(): HasMany
    {
        return $this->hasMany(TodoItem::class);
    }
}
