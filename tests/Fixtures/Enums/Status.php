<?php

namespace NickWelsh\EloquentZero\Tests\Fixtures\Enums;

enum Status: string
{
    case Active = 'active';
    case Backlog = 'backlog';
    case Archived = 'archived';
}
