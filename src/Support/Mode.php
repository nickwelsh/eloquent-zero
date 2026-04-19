<?php

namespace NickWelsh\EloquentZero\Support;

enum Mode: string
{
    case OptIn = 'opt_in';
    case OptOut = 'opt_out';
}
