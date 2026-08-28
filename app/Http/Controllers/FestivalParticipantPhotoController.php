<?php

namespace App\Http\Controllers;

use App\Enums\FestivalPortalRole;
use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\FestivalParticipant;
use App\Models\FestivalPortalUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FestivalParticipantPhotoController extends Controller
{
    public function portalProfile(Request $request, string $accountSlug): StreamedResponse
    {
        $account = $request->attributes->get('festivalAccount');
        $portalUser = $request->user('festival');
        abort_unless(
            $account instanceof Account
            && $account->slug === $accountSlug
            && $portalUser instanceof FestivalPortalUser
            && $portalUser->account_id === $account->id
            && $portalUser->role === FestivalPortalRole::Registrant,
            404,
        );

        return $this->image($portalUser->avatar_path);
    }

    public function portalParticipant(Request $request, string $accountSlug, FestivalParticipant $festivalParticipant): StreamedResponse
    {
        $account = $request->attributes->get('festivalAccount');
        $portalUser = $request->user('festival');
        abort_unless(
            $account instanceof Account
            && $account->slug === $accountSlug
            && $portalUser instanceof FestivalPortalUser
            && $portalUser->account_id === $account->id
            && $festivalParticipant->account_id === $account->id
            && $festivalParticipant->festival_portal_user_id === $portalUser->id,
            404,
        );

        $festivalParticipant->setRelation('portalUser', $portalUser);

        return $this->image($festivalParticipant->resolvedPhotoPath());
    }

    public function staffProfile(
        Request $request,
        Account $account,
        FestivalEdition $festivalEdition,
        FestivalPortalUser $festivalPortalUser,
    ): StreamedResponse {
        $this->assertStaffAccess($request, $account, $festivalEdition, $festivalPortalUser);

        return $this->image($festivalPortalUser->avatar_path);
    }

    public function staffParticipant(
        Request $request,
        Account $account,
        FestivalEdition $festivalEdition,
        FestivalPortalUser $festivalPortalUser,
        FestivalParticipant $festivalParticipant,
    ): StreamedResponse {
        $this->assertStaffAccess($request, $account, $festivalEdition, $festivalPortalUser);
        abort_unless(
            $festivalParticipant->account_id === $account->id
            && $festivalParticipant->festival_portal_user_id === $festivalPortalUser->id,
            404,
        );
        $festivalParticipant->setRelation('portalUser', $festivalPortalUser);

        return $this->image($festivalParticipant->resolvedPhotoPath());
    }

    private function assertStaffAccess(
        Request $request,
        Account $account,
        FestivalEdition $edition,
        FestivalPortalUser $portalUser,
    ): void {
        abort_unless(
            $edition->account_id === $account->id
            && $portalUser->account_id === $account->id
            && $portalUser->role === FestivalPortalRole::Registrant,
            404,
        );
        abort_unless((bool) $request->user()?->can('manageFestivalRegistrations', $account), 403);
    }

    private function image(?string $path): StreamedResponse
    {
        abort_unless(filled($path), 404);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($path), 404);
        $mimeType = strtolower((string) $disk->mimeType($path));
        abort_unless(in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true), 404);

        return $disk->response($path, null, [
            'Cache-Control' => 'private, no-store',
            'Content-Type' => $mimeType,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
