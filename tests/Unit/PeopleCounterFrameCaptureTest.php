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

    public function test_command_waits_after_opening_rtsp_stream_before_writing_frame(): void
    {
        config(['services.mediamtx.rtsp_transport' => 'tcp']);

        $this->assertSame([
            'ffmpeg',
            '-hide_banner',
            '-loglevel',
            'error',
            '-rtsp_transport',
            'tcp',
            '-i',
            'rtsp://127.0.0.1:8554/ladna-a1-r1',
            '-ss',
            '4',
            '-frames:v',
            '1',
            '-q:v',
            '2',
            '-y',
            '/tmp/frame.jpg',
        ], $this->command('rtsp://127.0.0.1:8554/ladna-a1-r1', '/tmp/frame.jpg', 4));
    }

    public function test_command_uses_global_capture_delay_default(): void
    {
        config(['services.people_counter.capture_delay_seconds' => 3]);

        $command = $this->command('http://127.0.0.1:8888/ladna-a1-r1/index.m3u8', '/tmp/frame.jpg');
        $delayOption = array_search('-ss', $command, true);

        $this->assertIsInt($delayOption);
        $this->assertSame('3', $command[$delayOption + 1]);
        $this->assertNotContains('-rtsp_transport', $command);
    }

    public function test_command_allows_zero_capture_delay(): void
    {
        config(['services.people_counter.capture_delay_seconds' => 0]);

        $this->assertNotContains(
            '-ss',
            $this->command('http://127.0.0.1:8888/ladna-a1-r1/index.m3u8', '/tmp/frame.jpg'),
        );
    }

    private function streamUrl(string $pathName): string
    {
        $method = new ReflectionMethod(PeopleCounterFrameCapture::class, 'streamUrl');
        $method->setAccessible(true);

        return $method->invoke(app(PeopleCounterFrameCapture::class), $pathName);
    }

    /**
     * @return list<string>
     */
    private function command(string $streamUrl, string $absolutePath, ?int $captureDelaySeconds = null): array
    {
        $method = new ReflectionMethod(PeopleCounterFrameCapture::class, 'command');
        $method->setAccessible(true);

        return $method->invoke(app(PeopleCounterFrameCapture::class), $streamUrl, $absolutePath, $captureDelaySeconds);
    }
}
