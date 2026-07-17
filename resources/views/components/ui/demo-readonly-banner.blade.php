<div {{ $attributes->class(['border-b border-[#A78AB9]/35 bg-[#F2ECF6] px-4 py-3 text-sm text-[#2B1731] sm:px-6 lg:px-8']) }} role="status">
    <div class="flex items-start gap-3 font-semibold">
        <x-ui.icon name="triangle-alert" class="mt-0.5 h-4 w-4 shrink-0 text-[#72517B]" />
        <span>
            <span class="font-bold">{{ __('app.demo_readonly_title') }}</span>
            {{ __('app.demo_readonly_banner') }}
        </span>
    </div>
</div>
