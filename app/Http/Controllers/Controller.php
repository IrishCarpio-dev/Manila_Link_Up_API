<?php

namespace App\Http\Controllers;

use Google\Cloud\Core\Timestamp;

abstract class Controller
{
    protected function formatDoc(mixed $value): mixed
    {
        if ($value instanceof Timestamp) {
            return $value->get()->format('c');
        }
        if (is_array($value)) {
            return array_map([$this, 'formatDoc'], $value);
        }
        return $value;
    }
}
