<div style="margin-top: 28px; padding-top: 16px; border-top: 1px solid #e5e7eb; color: #64748b; font-size: 13px; line-height: 1.5;">
    <div>{{ __('app.mail_footer_sent_by', ['name' => $accountName]) }}</div>
    @if ($supportUrl)
        <div style="margin-top: 6px;">
            <a href="{{ $supportUrl }}" style="color: #6d28d9;">{{ __('app.mail_footer_support') }}</a>
        </div>
    @endif
</div>
