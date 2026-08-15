<div
    class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/60 p-4 backdrop-blur-sm"
    role="dialog"
    aria-modal="true"
    aria-labelledby="ticket-scanner-modal-title"
    aria-describedby="ticket-scanner-modal-message"
    data-scanner-modal
    data-ready-title="{{ __('app.ticket_scanner_ready_title') }}"
    data-confirmed-title="{{ __('app.ticket_scanner_confirmed_title') }}"
    data-duplicate-title="{{ __('app.ticket_scanner_duplicate_title') }}"
    data-warning-title="{{ __('app.ticket_scanner_warning_title') }}"
    data-confirm-label="{{ __('app.ticket_scanner_confirm_pass') }}"
    data-confirming-label="{{ __('app.ticket_scanner_confirming') }}"
>
    <div class="max-h-[calc(100dvh-2rem)] w-full max-w-lg overflow-y-auto rounded-2xl border-t-4 border-emerald-400 bg-white p-5 shadow-2xl sm:p-6" data-scanner-modal-panel>
        <div class="flex items-start justify-between gap-4">
            <div class="flex min-w-0 items-start gap-3">
                <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-emerald-50 text-emerald-700" data-scanner-modal-icon-shell>
                    <span data-scanner-modal-icon="success"><x-ui.icon name="circle-check" class="h-6 w-6" /></span>
                    <span class="hidden" data-scanner-modal-icon="danger"><x-ui.icon name="circle-x" class="h-6 w-6" /></span>
                    <span class="hidden" data-scanner-modal-icon="warning"><x-ui.icon name="triangle-alert" class="h-6 w-6" /></span>
                </div>
                <div class="min-w-0">
                    <h2 id="ticket-scanner-modal-title" class="text-xl font-semibold text-slate-950" data-scanner-modal-title></h2>
                    <p id="ticket-scanner-modal-message" class="mt-1 text-sm leading-6 text-slate-600" data-scanner-modal-message></p>
                </div>
            </div>
            <button type="button" class="shrink-0 rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-900 crm-focus" aria-label="{{ __('app.close') }}" data-scanner-modal-dismiss>
                <x-ui.icon name="x" class="h-5 w-5" />
            </button>
        </div>

        <dl class="mt-5 hidden divide-y divide-stone-200 rounded-xl border border-stone-200 bg-slate-50 px-4" data-scanner-modal-details>
            <div class="grid gap-1 py-3 sm:grid-cols-[9rem_1fr] sm:gap-4" data-scanner-modal-customer-row>
                <dt class="text-sm font-medium text-slate-500">{{ __('app.ticket_scanner_customer') }}</dt>
                <dd class="break-words text-sm font-semibold text-slate-950" data-scanner-modal-customer></dd>
            </div>
            <div class="grid gap-1 py-3 sm:grid-cols-[9rem_1fr] sm:gap-4" data-scanner-modal-type-row>
                <dt class="text-sm font-medium text-slate-500">{{ __('app.ticket_scanner_ticket_type') }}</dt>
                <dd class="break-words text-sm font-semibold text-slate-950" data-scanner-modal-type></dd>
            </div>
            <div class="grid gap-1 py-3 sm:grid-cols-[9rem_1fr] sm:gap-4" data-scanner-modal-code-row>
                <dt class="text-sm font-medium text-slate-500">{{ __('app.ticket_scanner_ticket_code') }}</dt>
                <dd class="break-all font-mono text-sm font-semibold text-slate-950" data-scanner-modal-code></dd>
            </div>
            <div hidden class="grid gap-1 py-3 sm:grid-cols-[9rem_1fr] sm:gap-4" data-scanner-modal-checked-in-row>
                <dt class="text-sm font-medium text-slate-500">{{ __('app.ticket_scanner_checked_in_at') }}</dt>
                <dd class="text-sm font-semibold text-slate-950" data-scanner-modal-checked-in></dd>
            </div>
        </dl>

        <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
            <x-ui.button type="button" variant="secondary" data-scanner-modal-dismiss>{{ __('app.close') }}</x-ui.button>
            <x-ui.button type="button" variant="success" data-scanner-modal-confirm>
                <x-ui.icon name="circle-check" class="h-4 w-4" />
                <span data-scanner-modal-confirm-label>{{ __('app.ticket_scanner_confirm_pass') }}</span>
            </x-ui.button>
        </div>
    </div>
</div>
