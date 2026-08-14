<?php

namespace App\Support\Festivals;

use App\Models\Account;
use App\Models\FestivalOnlineStream;
use App\Models\FestivalStreamEntitlement;
use App\Models\User;

final readonly class FestivalStreamViewer
{
    public function __construct(
        public Account $account,
        public FestivalOnlineStream $stream,
        public ?FestivalStreamEntitlement $entitlement = null,
        public bool $isStaffPreview = false,
        public ?User $staffUser = null,
    ) {}
}
