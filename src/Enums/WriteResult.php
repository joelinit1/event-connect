<?php

namespace Jma\Connect\Enums;

enum WriteResult: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
    case Skipped = 'skipped';
}
