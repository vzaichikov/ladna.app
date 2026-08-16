<?php

use App\Http\Controllers\FestivalAdmissionController;
use App\Http\Controllers\FestivalAnnouncementController;
use App\Http\Controllers\FestivalBattleVoteController;
use App\Http\Controllers\FestivalEntryController;
use App\Http\Controllers\FestivalEntryStepController;
use App\Http\Controllers\FestivalFileController;
use App\Http\Controllers\FestivalJudgingController;
use App\Http\Controllers\FestivalParticipantController;
use App\Http\Controllers\FestivalPortalAuthController;
use App\Http\Controllers\FestivalPortalController;
use App\Http\Controllers\FestivalPublicController;
use App\Http\Controllers\FestivalStreamAccessController;
use App\Http\Controllers\FestivalSubmissionController;
use App\Http\Controllers\FestivalTimelineController;
use App\Http\Middleware\AuthenticateFestivalPortal;
use App\Http\Middleware\EnsureFestivalEditionWritable;
use App\Http\Middleware\EnsureFestivalPortalRole;
use App\Http\Middleware\EnsureFestivalProfileComplete;
use App\Http\Middleware\EnsureFestivalsEnabled;
use App\Http\Middleware\EnsurePublicSubscriptionIsActive;
use App\Http\Middleware\PreventReadOnlyDemoMutations;
use Illuminate\Support\Facades\Route;

Route::middleware([EnsurePublicSubscriptionIsActive::class, EnsureFestivalsEnabled::class, EnsureFestivalEditionWritable::class])->group(function (): void {
    Route::get('/{accountSlug}/festivals', [FestivalPublicController::class, 'index'])->name('public.festivals.index');
    Route::get('/{accountSlug}/festivals/{editionSlug}', [FestivalPublicController::class, 'show'])->name('public.festivals.show');
    Route::get('/{accountSlug}/festivals/{editionSlug}/timeline', [FestivalTimelineController::class, 'publicFragment'])->name('public.festivals.timeline');
    Route::post('/{accountSlug}/festivals/{editionSlug}/admission', [FestivalAdmissionController::class, 'store'])->middleware([PreventReadOnlyDemoMutations::class, 'throttle:festival-checkout'])->name('public.festivals.admission.store');
    Route::get('/{accountSlug}/festival-orders/{accessToken}', [FestivalPublicController::class, 'order'])->name('public.festival-orders.show');
    Route::get('/{accountSlug}/festival-orders/{accessToken}/payment', [FestivalPublicController::class, 'orderPayment'])->middleware('throttle:120,1')->name('public.festival-orders.payment');
    Route::get('/{accountSlug}/festival-orders/{accessToken}/status', [FestivalPublicController::class, 'orderStatus'])->middleware('throttle:120,1')->name('public.festival-orders.status');
    Route::get('/{accountSlug}/festival-orders/{accessToken}/tickets.pdf', [FestivalPublicController::class, 'orderPdf'])->middleware('throttle:30,1')->name('public.festival-orders.pdf');
    Route::get('/{accountSlug}/festival-orders/{accessToken}/tickets/{ticketCode}/qr', [FestivalPublicController::class, 'ticketQr'])->middleware('throttle:120,1')->name('public.festival-tickets.qr');
    Route::get('/{accountSlug}/festival-documents/{festivalDocument}', [FestivalFileController::class, 'document'])->name('public.festival-documents.download');

    Route::get('/{accountSlug}/festival/login', [FestivalPortalAuthController::class, 'show'])->defaults('festivalRole', 'registrant')->name('festival.login');
    Route::post('/{accountSlug}/festival/login/email', [FestivalPortalAuthController::class, 'emailLogin'])->defaults('festivalRole', 'registrant')->middleware([PreventReadOnlyDemoMutations::class, 'throttle:festival-login'])->name('festival.login.email');
    Route::post('/{accountSlug}/festival/login/otp', [FestivalPortalAuthController::class, 'sendOtp'])->defaults('festivalRole', 'registrant')->middleware([PreventReadOnlyDemoMutations::class, 'throttle:festival-otp'])->name('festival.login.otp.send');
    Route::get('/{accountSlug}/festival/login/otp', [FestivalPortalAuthController::class, 'otpChallenge'])->defaults('festivalRole', 'registrant')->name('festival.login.otp.challenge');
    Route::post('/{accountSlug}/festival/login/otp/resend', [FestivalPortalAuthController::class, 'resendOtp'])->defaults('festivalRole', 'registrant')->middleware([PreventReadOnlyDemoMutations::class, 'throttle:festival-otp'])->name('festival.login.otp.resend');
    Route::post('/{accountSlug}/festival/login/otp/change-phone', [FestivalPortalAuthController::class, 'changeOtpPhone'])->defaults('festivalRole', 'registrant')->name('festival.login.otp.change-phone');
    Route::post('/{accountSlug}/festival/login/otp/verify', [FestivalPortalAuthController::class, 'verifyOtp'])->defaults('festivalRole', 'registrant')->middleware('throttle:festival-login')->name('festival.login.otp.verify');
    Route::get('/{accountSlug}/festival/login/google', [FestivalPortalAuthController::class, 'googleRedirect'])->defaults('festivalRole', 'registrant')->name('festival.login.google');

    Route::get('/{accountSlug}/festival/judge/login', [FestivalPortalAuthController::class, 'show'])->defaults('festivalRole', 'judge')->name('festival.judge.login');
    Route::post('/{accountSlug}/festival/judge/login/email', [FestivalPortalAuthController::class, 'emailLogin'])->defaults('festivalRole', 'judge')->middleware([PreventReadOnlyDemoMutations::class, 'throttle:festival-login'])->name('festival.judge.login.email');
    Route::post('/{accountSlug}/festival/judge/login/otp', [FestivalPortalAuthController::class, 'sendOtp'])->defaults('festivalRole', 'judge')->middleware([PreventReadOnlyDemoMutations::class, 'throttle:festival-otp'])->name('festival.judge.login.otp.send');
    Route::get('/{accountSlug}/festival/judge/login/otp', [FestivalPortalAuthController::class, 'otpChallenge'])->defaults('festivalRole', 'judge')->name('festival.judge.login.otp.challenge');
    Route::post('/{accountSlug}/festival/judge/login/otp/resend', [FestivalPortalAuthController::class, 'resendOtp'])->defaults('festivalRole', 'judge')->middleware([PreventReadOnlyDemoMutations::class, 'throttle:festival-otp'])->name('festival.judge.login.otp.resend');
    Route::post('/{accountSlug}/festival/judge/login/otp/change-phone', [FestivalPortalAuthController::class, 'changeOtpPhone'])->defaults('festivalRole', 'judge')->name('festival.judge.login.otp.change-phone');
    Route::post('/{accountSlug}/festival/judge/login/otp/verify', [FestivalPortalAuthController::class, 'verifyOtp'])->defaults('festivalRole', 'judge')->middleware('throttle:festival-login')->name('festival.judge.login.otp.verify');
    Route::get('/{accountSlug}/festival/judge/login/google', [FestivalPortalAuthController::class, 'googleRedirect'])->defaults('festivalRole', 'judge')->name('festival.judge.login.google');

    Route::get('/{accountSlug}/festival/guest/login', [FestivalPortalAuthController::class, 'show'])->defaults('festivalRole', 'guest')->name('festival.guest.login');
    Route::post('/{accountSlug}/festival/guest/login/email', [FestivalPortalAuthController::class, 'emailLogin'])->defaults('festivalRole', 'guest')->middleware([PreventReadOnlyDemoMutations::class, 'throttle:festival-login'])->name('festival.guest.login.email');
    Route::post('/{accountSlug}/festival/guest/login/otp', [FestivalPortalAuthController::class, 'sendOtp'])->defaults('festivalRole', 'guest')->middleware([PreventReadOnlyDemoMutations::class, 'throttle:festival-otp'])->name('festival.guest.login.otp.send');
    Route::get('/{accountSlug}/festival/guest/login/otp', [FestivalPortalAuthController::class, 'otpChallenge'])->defaults('festivalRole', 'guest')->name('festival.guest.login.otp.challenge');
    Route::post('/{accountSlug}/festival/guest/login/otp/resend', [FestivalPortalAuthController::class, 'resendOtp'])->defaults('festivalRole', 'guest')->middleware([PreventReadOnlyDemoMutations::class, 'throttle:festival-otp'])->name('festival.guest.login.otp.resend');
    Route::post('/{accountSlug}/festival/guest/login/otp/change-phone', [FestivalPortalAuthController::class, 'changeOtpPhone'])->defaults('festivalRole', 'guest')->name('festival.guest.login.otp.change-phone');
    Route::post('/{accountSlug}/festival/guest/login/otp/verify', [FestivalPortalAuthController::class, 'verifyOtp'])->defaults('festivalRole', 'guest')->middleware('throttle:festival-login')->name('festival.guest.login.otp.verify');
    Route::get('/{accountSlug}/festival/guest/login/google', [FestivalPortalAuthController::class, 'googleRedirect'])->defaults('festivalRole', 'guest')->name('festival.guest.login.google');

    Route::prefix('{accountSlug}/festival-portal')->name('festival.portal.')->middleware([AuthenticateFestivalPortal::class])->group(function (): void {
        Route::post('logout', [FestivalPortalAuthController::class, 'logout'])->name('logout');
        Route::middleware([EnsureFestivalPortalRole::class.':registrant', EnsureFestivalProfileComplete::class])->group(function (): void {
            Route::get('profile', [FestivalPortalController::class, 'editProfile'])->name('profile.edit');
            Route::put('profile', [FestivalPortalController::class, 'updateProfile'])->middleware(PreventReadOnlyDemoMutations::class)->name('profile.update');
            Route::post('profile/phone/send', [FestivalPortalController::class, 'sendProfilePhoneOtp'])->middleware([PreventReadOnlyDemoMutations::class, 'throttle:festival-profile-otp'])->name('profile.phone.send');
            Route::post('profile/phone/resend', [FestivalPortalController::class, 'resendProfilePhoneOtp'])->middleware([PreventReadOnlyDemoMutations::class, 'throttle:festival-profile-otp'])->name('profile.phone.resend');
            Route::post('profile/phone/change', [FestivalPortalController::class, 'changeProfilePhone'])->middleware(PreventReadOnlyDemoMutations::class)->name('profile.phone.change');
            Route::post('profile/phone/verify', [FestivalPortalController::class, 'verifyProfilePhoneOtp'])->middleware([PreventReadOnlyDemoMutations::class, 'throttle:festival-login'])->name('profile.phone.verify');
            Route::get('/', [FestivalPortalController::class, 'dashboard'])->name('dashboard');
            Route::get('participants', [FestivalParticipantController::class, 'index'])->name('participants.index');
            Route::post('participants', [FestivalParticipantController::class, 'store'])->middleware(PreventReadOnlyDemoMutations::class)->name('participants.store');
            Route::put('participants/{festivalParticipant}', [FestivalParticipantController::class, 'update'])->middleware(PreventReadOnlyDemoMutations::class)->name('participants.update');
            Route::delete('participants/{festivalParticipant}', [FestivalParticipantController::class, 'destroy'])->middleware(PreventReadOnlyDemoMutations::class)->name('participants.destroy');
            Route::get('entries', [FestivalPortalController::class, 'entries'])->name('entries.index');
            Route::get('editions/{editionSlug}/entries/create', [FestivalEntryController::class, 'create'])->name('entries.create');
            Route::post('editions/{editionSlug}/entries', [FestivalEntryController::class, 'store'])->middleware(PreventReadOnlyDemoMutations::class)->name('entries.store');
            Route::get('entries/{festivalEntry}', [FestivalEntryController::class, 'show'])->name('entries.show');
            Route::get('entries/{festivalEntry}/steps/{festivalEntryStep}', [FestivalEntryStepController::class, 'show'])->name('entry-steps.show');
            Route::get('entries/{festivalEntry}/edit', [FestivalEntryController::class, 'edit'])->name('entries.edit');
            Route::put('entries/{festivalEntry}', [FestivalEntryController::class, 'update'])->middleware(PreventReadOnlyDemoMutations::class)->name('entries.update');
            Route::post('entries/{festivalEntry}/submit', [FestivalEntryController::class, 'submit'])->middleware(PreventReadOnlyDemoMutations::class)->name('entries.submit');
            Route::post('entries/{festivalEntry}/steps/{festivalEntryStep}/submit', [FestivalEntryStepController::class, 'submit'])->middleware(PreventReadOnlyDemoMutations::class)->name('entry-steps.submit');
            Route::post('entries/{festivalEntry}/steps/{festivalEntryStep}/requirements/{festivalEntryRequirement}/response', [FestivalEntryStepController::class, 'storeResponse'])->middleware(PreventReadOnlyDemoMutations::class)->name('entry-step-responses.store');
            Route::post('entries/{festivalEntry}/withdraw', [FestivalEntryController::class, 'withdraw'])->middleware(PreventReadOnlyDemoMutations::class)->name('entries.withdraw');
            Route::post('entries/{festivalEntry}/requirements/{festivalEntryRequirement}/submissions', [FestivalSubmissionController::class, 'store'])->middleware(PreventReadOnlyDemoMutations::class)->name('submissions.store');
            Route::get('submissions/{festivalSubmission}', [FestivalFileController::class, 'portalSubmission'])->name('submissions.download');
            Route::put('notification-preferences', [FestivalAnnouncementController::class, 'updatePreferences'])->middleware(PreventReadOnlyDemoMutations::class)->name('notification-preferences.update');
            Route::post('entries/{festivalEntry}/charges/{festivalCharge}/pay', [FestivalEntryController::class, 'payCharge'])->middleware(PreventReadOnlyDemoMutations::class)->name('charges.pay');
        });

        Route::prefix('judge')->name('judge.')->middleware([EnsureFestivalPortalRole::class.':judge', EnsureFestivalProfileComplete::class])->group(function (): void {
            Route::get('profile', [FestivalPortalController::class, 'editProfile'])->name('profile.edit');
            Route::put('profile', [FestivalPortalController::class, 'updateProfile'])->middleware(PreventReadOnlyDemoMutations::class)->name('profile.update');
            Route::post('profile/phone/send', [FestivalPortalController::class, 'sendProfilePhoneOtp'])->middleware([PreventReadOnlyDemoMutations::class, 'throttle:festival-profile-otp'])->name('profile.phone.send');
            Route::post('profile/phone/resend', [FestivalPortalController::class, 'resendProfilePhoneOtp'])->middleware([PreventReadOnlyDemoMutations::class, 'throttle:festival-profile-otp'])->name('profile.phone.resend');
            Route::post('profile/phone/change', [FestivalPortalController::class, 'changeProfilePhone'])->middleware(PreventReadOnlyDemoMutations::class)->name('profile.phone.change');
            Route::post('profile/phone/verify', [FestivalPortalController::class, 'verifyProfilePhoneOtp'])->middleware([PreventReadOnlyDemoMutations::class, 'throttle:festival-login'])->name('profile.phone.verify');
            Route::get('/', [FestivalPortalController::class, 'judgeDashboard'])->name('dashboard');
        });

        Route::prefix('guest')->name('guest.')->middleware([EnsureFestivalPortalRole::class.':guest', EnsureFestivalProfileComplete::class])->group(function (): void {
            Route::get('/', [FestivalPortalController::class, 'guestDashboard'])->name('dashboard');
            Route::get('profile', [FestivalPortalController::class, 'editProfile'])->name('profile.edit');
            Route::put('profile', [FestivalPortalController::class, 'updateProfile'])->middleware(PreventReadOnlyDemoMutations::class)->name('profile.update');
            Route::post('profile/phone/send', [FestivalPortalController::class, 'sendProfilePhoneOtp'])->middleware([PreventReadOnlyDemoMutations::class, 'throttle:festival-profile-otp'])->name('profile.phone.send');
            Route::post('profile/phone/resend', [FestivalPortalController::class, 'resendProfilePhoneOtp'])->middleware([PreventReadOnlyDemoMutations::class, 'throttle:festival-profile-otp'])->name('profile.phone.resend');
            Route::post('profile/phone/change', [FestivalPortalController::class, 'changeProfilePhone'])->middleware(PreventReadOnlyDemoMutations::class)->name('profile.phone.change');
            Route::post('profile/phone/verify', [FestivalPortalController::class, 'verifyProfilePhoneOtp'])->middleware([PreventReadOnlyDemoMutations::class, 'throttle:festival-login'])->name('profile.phone.verify');
            Route::get('stream/{festivalStreamEntitlement}', [FestivalStreamAccessController::class, 'watch'])->whereNumber('festivalStreamEntitlement')->middleware('throttle:festival-stream-bootstrap')->name('stream.watch');
            Route::delete('stream/{festivalStreamEntitlement}/leases', [FestivalStreamAccessController::class, 'release'])->whereNumber('festivalStreamEntitlement')->name('stream.release');
        });

        Route::middleware([EnsureFestivalPortalRole::class.':judge', EnsureFestivalProfileComplete::class])->group(function (): void {
            Route::get('editions/{editionSlug}/judging', [FestivalJudgingController::class, 'guestIndex'])->name('judging.index');
            Route::get('editions/{editionSlug}/judging/{festivalScoreSheet}', [FestivalJudgingController::class, 'editGuest'])->name('judging.edit');
            Route::put('editions/{editionSlug}/judging/{festivalScoreSheet}', [FestivalJudgingController::class, 'updateGuest'])->middleware(PreventReadOnlyDemoMutations::class)->name('judging.update');
            Route::get('editions/{editionSlug}/battle-votes', [FestivalBattleVoteController::class, 'indexGuest'])->name('battle-votes.index');
            Route::put('editions/{editionSlug}/battle-votes/{festivalBattleMatch}', [FestivalBattleVoteController::class, 'storeGuest'])->middleware(PreventReadOnlyDemoMutations::class)->name('battle-votes.update');
        });
    });
});

Route::get('/festival-stream/bootstrap', [FestivalStreamAccessController::class, 'bootstrap'])->middleware('throttle:festival-stream-bootstrap')->name('festival.stream.bootstrap');
Route::get('/festival-stream/watch/{path}', [FestivalStreamAccessController::class, 'player'])->where('path', '[A-Za-z0-9-]+')->name('festival.stream.player');
Route::get('/festival-stream/heartbeat/{path}', [FestivalStreamAccessController::class, 'heartbeat'])->where('path', '[A-Za-z0-9-]+')->middleware('throttle:festival-stream-heartbeat')->name('festival.stream.heartbeat');
Route::get('/internal/festival-stream/authorize', [FestivalStreamAccessController::class, 'gatewayAuthorize'])->middleware('throttle:festival-stream-gateway')->name('internal.festival-stream.authorize');
Route::post('/internal/festival-stream/publisher-authorize', [FestivalStreamAccessController::class, 'publisherAuthorize'])->middleware('throttle:festival-stream-publisher')->name('internal.festival-stream.publisher-authorize');

Route::post('/{accountSlug}/festival/logout', [FestivalPortalAuthController::class, 'logout'])
    ->middleware([EnsureFestivalsEnabled::class, AuthenticateFestivalPortal::class])
    ->name('festival.logout');
