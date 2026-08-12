@extends('layouts.public', ['hideAppFooter' => true])

@php($isJudge = $portalUser->role === \App\Enums\FestivalPortalRole::Judge)

@section('title', __('app.profile').' - '.$account->name)

@section('content')
<main class="min-h-screen bg-canvas px-5 py-8">
    <div class="mx-auto max-w-4xl">
        @include('festivals.portal._nav')
        <header class="mt-8"><h1 class="text-4xl font-semibold">{{ __('app.festival_profile') }}</h1><p class="mt-2 text-slate-600">{{ $isJudge ? __('app.festival_role_judge') : __('app.festival_role_registrant') }}</p></header>
        @if(session('status'))<div class="mt-5 rounded-xl bg-emerald-50 p-4 text-sm text-emerald-900">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="mt-5 rounded-xl bg-rose-50 p-4 text-sm text-rose-900"><ul class="list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <form method="POST" action="{{ route($isJudge ? 'festival.portal.judge.profile.update' : 'festival.portal.profile.update', $account->slug) }}" class="mt-6 rounded-2xl border border-stone-200 bg-white p-6 shadow-crm">
            @csrf
            @method('PUT')
            <div class="grid gap-5 sm:grid-cols-2">
                @unless($isJudge)<label><span class="crm-label">{{ __('app.festival_profile_type') }}</span><select name="registrant_type" required class="crm-field">@foreach(\App\Enums\FestivalRegistrantType::cases() as $type)<option value="{{ $type->value }}" @selected(old('registrant_type', $portalUser->registrant_type?->value) === $type->value)>{{ __('app.festival_registrant_'.$type->value) }}</option>@endforeach</select></label>@endunless
                <label><span class="crm-label">{{ __('app.language') }}</span><select name="locale" class="crm-field"><option value="uk" @selected(old('locale', $portalUser->locale)==='uk')>Українська</option><option value="en" @selected(old('locale', $portalUser->locale)==='en')>English</option></select></label>
                <label><span class="crm-label">{{ __('app.first_name') }}</span><input name="first_name" value="{{ old('first_name', $portalUser->first_name) }}" required class="crm-field"></label>
                <label><span class="crm-label">{{ __('app.last_name') }}</span><input name="last_name" value="{{ old('last_name', $portalUser->last_name) }}" required class="crm-field"></label>
                <label><span class="crm-label">{{ __('app.patronymic') }}</span><input name="patronymic" value="{{ old('patronymic', $portalUser->patronymic) }}" class="crm-field"></label>
                <label><span class="crm-label">{{ __('app.email') }}</span><input type="email" name="email" value="{{ old('email', $portalUser->email) }}" required class="crm-field"></label>
                <label><span class="crm-label">{{ __('app.phone') }}</span><input name="phone" value="{{ old('phone', $portalUser->phone) }}" @required(! $isJudge) class="crm-field" data-phone-mask data-country-code="{{ $account->country_code ?? 'UA' }}"></label>
                @unless($isJudge)
                    <label><span class="crm-label">{{ __('app.city') }}</span><input name="city" value="{{ old('city', $portalUser->city) }}" required class="crm-field"></label>
                    <label><span class="crm-label">{{ __('app.festival_studio_school') }}</span><input name="studio_name" value="{{ old('studio_name', $portalUser->studio_name) }}" required class="crm-field"></label>
                    <label class="sm:col-span-2"><span class="crm-label">Instagram</span><input type="url" name="instagram_url" value="{{ old('instagram_url', $portalUser->instagram_url) }}" class="crm-field"></label>
                @endunless
                <label><span class="crm-label">{{ __('app.new_password') }}</span><input type="password" name="password" autocomplete="new-password" class="crm-field"><span class="crm-help">{{ __('app.festival_password_edit_help') }}</span></label>
                <label><span class="crm-label">{{ __('app.password_confirmation') }}</span><input type="password" name="password_confirmation" autocomplete="new-password" class="crm-field"></label>
            </div>
            <div class="mt-6 flex justify-end"><x-ui.button type="submit">{{ __('app.save') }}</x-ui.button></div>
        </form>
    </div>
</main>
@endsection
