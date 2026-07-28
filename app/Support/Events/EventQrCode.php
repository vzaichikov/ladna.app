<?php

namespace App\Support\Events;

use App\Models\EventTicket;
use BaconQrCode\Renderer\Image\ImagickImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

class EventQrCode
{
    public function png(EventTicket $ticket): string
    {
        $renderer = new ImageRenderer(new RendererStyle(480, 2), new ImagickImageBackEnd);

        return (new Writer($renderer))->writeString($this->payload($ticket));
    }

    public function dataUri(EventTicket $ticket): string
    {
        return 'data:image/png;base64,'.base64_encode($this->png($ticket));
    }

    public function payload(EventTicket $ticket): string
    {
        return $ticket->token_encrypted;
    }
}
