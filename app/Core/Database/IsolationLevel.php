<?php

declare(strict_types=1);

namespace App\Core\Database;

enum IsolationLevel: string
{
    case ReadCommitted = 'READ COMMITTED';
    case RepeatableRead = 'REPEATABLE READ';
    case Serializable = 'SERIALIZABLE';
}
