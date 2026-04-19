<?php

namespace NickWelsh\EloquentZero\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaleBelongsToComment extends Model
{
    protected $table = 'stale_belongs_to_comments';

    public $timestamps = false;

    protected $guarded = [];

    public function blogPost(): BelongsTo
    {
        return $this->belongsTo(BlogPost::class, 'missing_blog_post_id');
    }
}
