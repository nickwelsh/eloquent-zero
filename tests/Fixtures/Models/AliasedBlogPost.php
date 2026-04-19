<?php

namespace NickWelsh\EloquentZero\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use NickWelsh\EloquentZero\Attributes\ZeroName;

#[ZeroName('ThePosts')]
class AliasedBlogPost extends Model
{
    protected $table = 'blog_posts';

    public $timestamps = false;

    protected $guarded = [];
}
