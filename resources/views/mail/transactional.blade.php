<x-mail::message>
@include('mail.partials.header', [
    'accountName' => $accountName,
    'accountLogoUrl' => $accountLogoUrl,
    'accountBrandColor' => $accountBrandColor,
])

@include($contentView, ['data' => $data])

@include('mail.partials.footer', [
    'accountName' => $accountName,
    'supportUrl' => $supportUrl,
])
</x-mail::message>
