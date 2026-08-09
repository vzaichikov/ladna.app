<?php

namespace App\View\Components\Festivals\Staff;

use App\Models\Account;
use App\Models\FestivalEdition;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class Workspace extends Component
{
    /**
     * @param  array{manage: bool, registrations: bool, schedule: bool, finance: bool, judging: bool, ticket_check_in: bool}  $permissions
     */
    public function __construct(
        public Account $account,
        public FestivalEdition $edition,
        public array $permissions,
    ) {}

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View|Closure|string
    {
        return view('components.festivals.staff.workspace');
    }
}
