<?php

namespace App\Enums;

enum FestivalRequirementInputType: string
{
    case File = 'file';
    case ShortText = 'short_text';
    case LongText = 'long_text';
    case Integer = 'integer';
    case Boolean = 'boolean';
    case Agreement = 'agreement';
    case Url = 'url';
    case SingleSelect = 'single_select';
    case MultiSelect = 'multi_select';
    case HelperSelection = 'helper_selection';
}
