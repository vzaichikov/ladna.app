<?php

namespace App\Enums;

enum AccountMode: string
{
    case Live = 'live';
    case DemoReadonly = 'demo_readonly';
    case Internal = 'internal';
}
