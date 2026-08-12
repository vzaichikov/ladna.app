@extends('layouts.public')

@section('publicFooter')
    <x-ui.powered-footer :account="$account" :show-locale-switcher="true" class="mx-auto max-w-6xl bg-canvas px-5 pb-8 sm:px-8" />
@endsection
