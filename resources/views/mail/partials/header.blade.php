<div style="margin-bottom: 24px;">
    @if ($accountLogoUrl)
        <img src="{{ $accountLogoUrl }}" alt="{{ $accountName }}" style="max-height: 48px; max-width: 160px; object-fit: contain;">
    @endif
    <div style="margin-top: 12px; color: {{ $accountBrandColor }}; font-size: 16px; font-weight: 700;">
        {{ $accountName }}
    </div>
</div>
