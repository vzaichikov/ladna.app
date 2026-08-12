<?php

namespace App\Enums;

enum FestivalChargeDuePolicy: string
{
    case Fixed = 'fixed';
    case ApprovalRelative = 'approval_relative';
}
