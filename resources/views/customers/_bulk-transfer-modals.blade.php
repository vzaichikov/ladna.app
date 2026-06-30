<div
    class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/55 p-3 backdrop-blur-sm sm:p-4"
    role="dialog"
    aria-modal="true"
    aria-labelledby="customer-export-title"
    data-customer-transfer-modal="export"
>
    <div class="flex max-h-[90vh] w-full max-w-lg flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-2xl">
        <div class="flex shrink-0 items-start justify-between gap-4 border-b border-stone-200 p-5">
            <div class="flex items-start gap-4">
                <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-emerald-50 text-emerald-700">
                    <x-ui.icon name="download" class="h-5 w-5" />
                </div>
                <div>
                    <h2 id="customer-export-title" class="text-lg font-semibold text-slate-950">{{ __('app.customer_export_title') }}</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('app.customer_export_copy') }}</p>
                </div>
            </div>
            <x-ui.action-button type="button" icon="close" :label="__('app.cancel')" data-customer-transfer-close />
        </div>

        <div class="p-5">
            <div class="rounded-lg border border-stone-200 bg-slate-50 p-4">
                <div class="grid grid-cols-3 gap-2 text-sm font-semibold text-slate-700">
                    <div>name</div>
                    <div>phone</div>
                    <div>email</div>
                </div>
            </div>

            <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:justify-end">
                <x-ui.button type="button" variant="secondary" data-customer-transfer-close>{{ __('app.cancel') }}</x-ui.button>
                <x-ui.button :href="route('dashboard.accounts.customers.export', $account)">
                    <x-ui.icon name="download" class="h-4 w-4" />
                    {{ __('app.customer_export_download') }}
                </x-ui.button>
            </div>
        </div>
    </div>
</div>

<div
    class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/55 p-3 backdrop-blur-sm sm:p-4"
    role="dialog"
    aria-modal="true"
    aria-labelledby="customer-import-title"
    data-customer-transfer-modal="import"
>
    <div class="flex h-[90vh] max-h-[90vh] w-full max-w-4xl flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-2xl">
        <div class="flex shrink-0 items-start justify-between gap-4 border-b border-stone-200 p-5">
            <div class="flex items-start gap-4">
                <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-violet-crm-100 text-brand-700">
                    <x-ui.icon name="upload" class="h-5 w-5" />
                </div>
                <div>
                    <h2 id="customer-import-title" class="text-lg font-semibold text-slate-950">{{ __('app.customer_import_title') }}</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('app.customer_import_copy') }}</p>
                </div>
            </div>
            <x-ui.action-button type="button" icon="close" :label="__('app.cancel')" data-customer-transfer-close />
        </div>

        <form
            method="POST"
            action="{{ route('dashboard.accounts.customers.import', $account) }}"
            enctype="multipart/form-data"
            class="flex min-h-0 flex-1 flex-col"
            data-customer-import-form
            data-validate-action="{{ route('dashboard.accounts.customers.import.validate', $account) }}"
            data-file-ready-template="{{ __('app.customer_import_file_ready', ['name' => '__name__']) }}"
            data-uploading="{{ __('app.customer_import_uploading') }}"
            data-validating="{{ __('app.customer_import_validating') }}"
            data-processing="{{ __('app.customer_import_processing') }}"
            data-failed="{{ __('app.customer_import_upload_failed') }}"
            data-empty="{{ __('app.customer_import_empty_results') }}"
            data-inserted-label="{{ __('app.customer_import_inserted') }}"
            data-updated-label="{{ __('app.customer_import_updated') }}"
            data-skipped-label="{{ __('app.customer_import_skipped') }}"
        >
            @csrf

            <div class="min-h-0 flex-1 space-y-5 overflow-y-auto p-5">
                <input
                    id="customer-import-file"
                    name="file"
                    type="file"
                    accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv"
                    class="sr-only"
                    data-customer-import-input
                >

                <div
                    class="flex min-h-44 cursor-pointer flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-stone-300 bg-slate-50 px-5 py-8 text-center transition hover:border-brand-100 hover:bg-brand-50"
                    data-customer-import-dropzone
                >
                    <div class="flex h-12 w-12 items-center justify-center rounded-lg bg-white text-brand-700 shadow-xs">
                        <x-ui.icon name="upload-cloud" class="h-6 w-6" />
                    </div>
                    <div>
                        <div class="font-semibold text-slate-950">{{ __('app.customer_import_drop_title') }}</div>
                        <div class="mt-1 text-sm text-slate-500">{{ __('app.customer_import_drop_copy') }}</div>
                    </div>
                    <div class="flex flex-wrap justify-center gap-2">
                        <x-ui.button type="button" variant="secondary" data-customer-import-browse>
                            <x-ui.icon name="folder-open" class="h-4 w-4" />
                            {{ __('app.customer_import_choose_file') }}
                        </x-ui.button>
                        <x-ui.button :href="route('dashboard.accounts.customers.example', $account)" variant="ghost">
                            <x-ui.icon name="file-spreadsheet" class="h-4 w-4" />
                            {{ __('app.customer_import_view_example') }}
                        </x-ui.button>
                    </div>
                    <div class="hidden text-sm font-semibold text-slate-700" data-customer-import-file-name></div>
                </div>

                <div class="hidden rounded-lg border border-stone-200 bg-white p-4" data-customer-import-progress>
                    <div class="flex items-center justify-between gap-3 text-sm font-semibold text-slate-700">
                        <span data-customer-import-progress-label>{{ __('app.customer_import_uploading') }}</span>
                        <span data-customer-import-progress-value>0%</span>
                    </div>
                    <div class="mt-3 h-2 overflow-hidden rounded-full bg-stone-100">
                        <div class="h-full w-0 rounded-full bg-brand-600 transition-all" data-customer-import-progress-bar></div>
                    </div>
                </div>

                <div class="hidden rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700" data-customer-import-error></div>

                <section class="rounded-lg border border-stone-200 bg-white p-4">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500">{{ __('app.customer_import_results') }}</h3>
                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                            <div class="rounded-lg bg-slate-50 px-3 py-2">
                                <div class="text-xs font-semibold text-slate-500">{{ __('app.customer_import_total_rows') }}</div>
                                <div class="text-lg font-semibold text-slate-950" data-customer-import-summary="total_rows">0</div>
                            </div>
                            <div class="rounded-lg bg-emerald-50 px-3 py-2">
                                <div class="text-xs font-semibold text-emerald-700">{{ __('app.customer_import_inserted') }}</div>
                                <div class="text-lg font-semibold text-emerald-900" data-customer-import-summary="inserted">0</div>
                            </div>
                            <div class="rounded-lg bg-amber-50 px-3 py-2">
                                <div class="text-xs font-semibold text-amber-700">{{ __('app.customer_import_updated') }}</div>
                                <div class="text-lg font-semibold text-amber-900" data-customer-import-summary="updated">0</div>
                            </div>
                            <div class="rounded-lg bg-rose-50 px-3 py-2">
                                <div class="text-xs font-semibold text-rose-700">{{ __('app.customer_import_skipped') }}</div>
                                <div class="text-lg font-semibold text-rose-900" data-customer-import-summary="skipped">0</div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 overflow-hidden rounded-lg border border-stone-200">
                        <div class="overflow-x-auto">
                            <div class="grid min-w-[720px] grid-cols-[72px_130px_1fr_1.4fr] gap-3 bg-slate-50 px-4 py-3 text-xs font-semibold uppercase tracking-wide text-slate-500">
                                <div>{{ __('app.customer_import_row') }}</div>
                                <div>{{ __('app.customer_import_status') }}</div>
                                <div>{{ __('app.customer_import_contact') }}</div>
                                <div>{{ __('app.customer_import_reason') }}</div>
                            </div>
                            <div class="max-h-72 overflow-y-auto" data-customer-import-results>
                                <div class="px-4 py-5 text-sm text-slate-500">{{ __('app.customer_import_empty_results') }}</div>
                            </div>
                        </div>
                    </div>
                </section>
            </div>

            <div class="flex shrink-0 flex-col gap-3 border-t border-stone-200 bg-white p-5 sm:flex-row sm:justify-end">
                <x-ui.button type="button" variant="secondary" data-customer-transfer-close>{{ __('app.cancel') }}</x-ui.button>
                <x-ui.button type="submit" data-customer-import-submit>
                    <x-ui.icon name="upload" class="h-4 w-4" />
                    {{ __('app.customer_import_start') }}
                </x-ui.button>
            </div>
        </form>
    </div>
</div>
