<?php

namespace App\Support\Festivals;

use App\Models\FestivalTicket;
use BaconQrCode\Renderer\Image\ImagickImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

class FestivalQrToken
{
    public function png(FestivalTicket $ticket): string
    {
        return (new Writer(new ImageRenderer(new RendererStyle(480, 2), new ImagickImageBackEnd)))->writeString($ticket->token_encrypted);
    }

    public function dataUri(FestivalTicket $ticket): string
    {
        return 'data:image/png;base64,'.base64_encode($this->png($ticket));
    }
}
