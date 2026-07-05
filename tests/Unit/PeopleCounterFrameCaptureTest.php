<?php

namespace Tests\Unit;

use App\Support\PeopleCounter\PeopleCounterFrameCapture;
use ReflectionMethod;
use Tests\TestCase;

class PeopleCounterFrameCaptureTest extends TestCase
{
    public function test_stream_url_uses_capture_url_template_when_configured(): void
    {
        config(['services.mediamtx.capture_url_template' => 'http://127.0.0.1:8888/{path}/index.m3u8']);

        $this->assertSame(
            'http://127.0.0.1:8888/ladna-a1-r1/index.m3u8',
            $this->streamUrl('ladna-a1-r1'),
        );
    }

    public function test_stream_url_falls_back_to_rtsp_url(): void
    {
        config([
            'services.mediamtx.capture_url_template' => null,
            'services.mediamtx.rtsp_url' => 'rtsp://127.0.0.1:8554',
        ]);

        $this->assertSame(
            'rtsp://127.0.0.1:8554/ladna-a1-r1',
            $this->streamUrl('ladna-a1-r1'),
        );
    }

    private function streamUrl(string $pathName): string
    {
        $method = new ReflectionMethod(PeopleCounterFrameCapture::class, 'streamUrl');
        $method->setAccessible(true);

        return $method->invoke(app(PeopleCounterFrameCapture::class), $pathName);
    }
}
