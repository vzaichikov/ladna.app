<?php

namespace App\Support\Entrance;

use BaconQrCode\Renderer\Image\ImagickImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

class EntranceQrCode
{
    public function png(string $url): string
    {
        return (new Writer(new ImageRenderer(new RendererStyle(480, 2), new ImagickImageBackEnd)))->writeString($url);
    }

    public function dataUri(string $url): string
    {
        return 'data:image/png;base64,'.base64_encode($this->png($url));
    }
}
