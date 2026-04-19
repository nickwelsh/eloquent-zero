<?php

namespace NickWelsh\EloquentZero\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class DangerousMethodModel extends Model
{
    protected $table = 'dangerous_method_models';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<int, string>
     */
    public function recoveryCodes(): array
    {
        throw new RuntimeException('should not be called');
    }
}
