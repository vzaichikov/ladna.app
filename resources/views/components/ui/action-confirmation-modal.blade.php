<div
    id="delete-confirmation-modal"
    class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/55 p-4 backdrop-blur-sm"
    role="dialog"
    aria-modal="true"
    aria-labelledby="delete-confirmation-title"
>
    <div class="max-h-[calc(100vh-2rem)] w-full max-w-md overflow-y-auto rounded-xl border border-slate-200 bg-white p-6 shadow-2xl">
        <div class="flex items-center gap-4">
            <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-rose-50 text-rose-700" data-confirm-icon data-default-icon="trash-2">
                <x-ui.icon name="trash" class="h-5 w-5" />
            </div>
            <div class="min-w-0">
                <h2 id="delete-confirmation-title" class="text-lg font-semibold text-slate-950" data-confirm-title data-default-text="{{ __('app.confirm_delete_title') }}">
                    {{ __('app.confirm_delete_title') }}
                </h2>
            </div>
        </div>

        <div class="mt-5 hidden rounded-xl border border-stone-200 bg-slate-50 p-4" data-confirm-details></div>

        <p class="mt-3 whitespace-pre-line text-sm leading-6 text-slate-500" data-confirm-body data-default-text="{{ __('app.confirm_delete_body') }}">
            {{ __('app.confirm_delete_body') }}
        </p>

        <div class="mt-5 hidden" data-confirm-phrase-container>
            <label for="confirmation-phrase" class="crm-label" data-confirm-phrase-label data-default-text="{{ __('app.confirmation_phrase_label') }}">
                {{ __('app.confirmation_phrase_label') }}
            </label>
            <input
                id="confirmation-phrase"
                type="text"
                class="crm-field min-h-11"
                autocomplete="off"
                spellcheck="false"
                aria-describedby="confirmation-phrase-help"
                data-confirm-phrase-input
            >
            <p id="confirmation-phrase-help" class="mt-2 text-sm leading-6 text-slate-500" data-confirm-phrase-help data-default-text="{{ __('app.confirmation_phrase_help') }}">
                {{ __('app.confirmation_phrase_help') }}
            </p>
        </div>

        <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:justify-end">
            <x-ui.button type="button" variant="secondary" data-confirm-cancel>
                {{ __('app.cancel') }}
            </x-ui.button>
            <x-ui.button type="button" variant="danger" data-confirm-accept data-default-text="{{ __('app.delete') }}">
                {{ __('app.delete') }}
            </x-ui.button>
        </div>
    </div>
</div>
