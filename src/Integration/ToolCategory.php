<?php

namespace App\Integration;

enum ToolCategory: string
{
    case READ = 'read';
    case WRITE = 'write';
    case DELETE = 'delete';
}
