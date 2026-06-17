<?php

namespace App\Enums;

enum IntegrationScope: string
{
    case Platform = 'platform';
    case Account = 'account';
}
