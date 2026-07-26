<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Location;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class AccountQrLinksController extends Controller
{
    public function __invoke(Account $account): View
    {
        $this->authorize('update', $account);

        $studioLandingUrl = route('public.studio', $account->slug);
        $customerLoginUrl = route('customer.studio.login', $account->slug);
        $publicLinkLocations = $this->publicLinkLocations($account);

        return view('accounts.qr-links', [
            'account' => $account,
            'printableLinks' => collect([
                $this->printableLink(
                    'app.studio_public_landing_qr',
                    'app.studio_public_landing',
                    $studioLandingUrl,
                    __('app.studio_public_landing_qr_copy'),
                ),
                $this->printableLink(
                    'app.login_qr_codes',
                    'app.customer_login',
                    $customerLoginUrl,
                    __('app.login_qr_codes_copy'),
                ),
            ])->concat($publicLinkLocations->pluck('printable_links')->flatten(1)),
            'generalPublicLinks' => [
                [
                    'label' => __('app.studio_public_landing'),
                    'open_label' => __('app.open_public_studio_landing'),
                    'url' => $studioLandingUrl,
                ],
                [
                    'label' => __('app.studio_rules'),
                    'open_label' => __('app.open_public_studio_rules'),
                    'url' => route('public.studio-rules', $account->slug),
                ],
                [
                    'label' => __('app.public_offer'),
                    'open_label' => __('app.open_public_offer'),
                    'url' => route('public.studio-offer', $account->slug),
                ],
            ],
            'publicLinkLocations' => $publicLinkLocations,
        ]);
    }

    /**
     * @return Collection<int, array{location: Location, links: array<int, array{label: string, icon: string, url: string}>, printable_links: array<int, array{title: string, subject: string, description: string, url: string, qr_svg: string}>}>
     */
    private function publicLinkLocations(Account $account): Collection
    {
        return $account->locations()
            ->active()
            ->orderBy('name')
            ->get(['id', 'account_id', 'name', 'slug', 'address'])
            ->map(function (Location $location) use ($account): array {
                $scheduleUrl = route('public.schedule', [$account->slug, $location->slug]);
                $priceUrl = route('public.price', [$account->slug, $location->slug]);
                $scheduleEmbedUrl = route('public.schedule.embed', [$account->slug, $location->slug]);
                $priceEmbedUrl = route('public.price.embed', [$account->slug, $location->slug]);

                return [
                    'location' => $location,
                    'links' => [
                        ['label' => __('app.public_schedule'), 'icon' => 'schedule', 'url' => $scheduleUrl],
                        ['label' => __('app.public_price'), 'icon' => 'class-pass-plans', 'url' => $priceUrl],
                        ['label' => __('app.public_schedule_embed'), 'icon' => 'external', 'url' => $scheduleEmbedUrl],
                        ['label' => __('app.public_price_embed'), 'icon' => 'external', 'url' => $priceEmbedUrl],
                    ],
                    'printable_links' => [
                        $this->printableLink(
                            'app.public_schedule',
                            'app.public_schedule',
                            $scheduleUrl,
                            $location->name,
                        ),
                        $this->printableLink(
                            'app.public_price',
                            'app.public_price',
                            $priceUrl,
                            $location->name,
                        ),
                    ],
                ];
            });
    }

    /**
     * @return array{title: string, subject: string, description: string, url: string, qr_svg: string}
     */
    private function printableLink(string $titleKey, string $subjectKey, string $url, string $description): array
    {
        return [
            'title' => __($titleKey),
            'subject' => __($subjectKey),
            'description' => $description,
            'url' => $url,
            'qr_svg' => $this->qrCodeSvg($url),
        ];
    }

    private function qrCodeSvg(string $url): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle(320),
            new SvgImageBackEnd,
        );

        return (new Writer($renderer))->writeString($url);
    }
}
