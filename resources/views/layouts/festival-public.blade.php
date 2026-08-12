@extends('layouts.public', ['hideAppFooter' => true])

@section('title', $edition->title.' - '.$account->name)

@section('content')
    <div
        class="festival-theme"
        data-festival-template="{{ $landingTemplateKey }}"
        data-festival-palette="{{ $landingPaletteKey }}"
    >
        @yield('festivalContent')
        <x-ui.powered-footer
            :account="$account"
            :show-locale-switcher="true"
            class="festival-footer mx-auto max-w-6xl px-5 pb-8 sm:px-8"
        />
    </div>
@endsection
