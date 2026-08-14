@extends('layouts.public')

@section('publicFooter')
    @php
        $isStreamPlayer = $disablePublicPwa ?? false;
        $studioUrl = $isStreamPlayer
            ? rtrim((string) config('app.url'), '/').'/'.rawurlencode($account->slug)
            : null;
    @endphp
    <x-ui.powered-footer
        :account="$account"
        :show-locale-switcher="! $isStreamPlayer"
        :show-studio-legal-links="! $isStreamPlayer"
        :studio-url="$studioUrl"
        class="mx-auto max-w-6xl bg-canvas px-5 pb-8 sm:px-8"
    />
@endsection
