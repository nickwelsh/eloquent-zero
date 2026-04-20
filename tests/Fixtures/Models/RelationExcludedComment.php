<?php

namespace NickWelsh\EloquentZero\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use NickWelsh\EloquentZero\Attributes\ZeroExclude;

#[ZeroExclude(['blog_post_id', 'title'])]
class RelationExcludedComment extends Model
{
    protected $table = 'scoped_comments';

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['secret_text'];

    public function blogPost(): BelongsTo
    {
        return $this->belongsTo(BlogPost::class);
    }
}
