<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class MonopayIframeMessagingTest extends TestCase
{
    #[DataProvider('supportedLinks')]
    public function test_trusted_frame_can_open_supported_bank_links(string $link): void
    {
        $this->assertSame([$link], $this->dispatchMessage(['message' => 'monopay-link', 'value' => $link]));
    }

    public static function supportedLinks(): array
    {
        return [
            'legacy app scheme' => ['monobank://pay/test'],
            'current app scheme' => ['mono://mbnk.app/pay/test'],
            'universal link' => ['https://mbnk.app/pay/test'],
        ];
    }

    #[DataProvider('unsafeLinks')]
    public function test_frame_cannot_navigate_to_an_untrusted_destination(string $link): void
    {
        $this->assertSame([], $this->dispatchMessage(['message' => 'monopay-link', 'value' => $link]));
    }

    public static function unsafeLinks(): array
    {
        return array_map(fn (string $link): array => [$link], [
            'javascript:alert(1)', 'data:text/html,test', 'https://evil.example/pay',
            'https://mbnk.app.evil.example/pay', 'https://mbnk.app@evil.example/pay',
            'https://user@mbnk.app/pay', 'http://mbnk.app/pay', 'https://mbnk.app:8443/pay',
            'mono://evil.example/pay', 'intent://pay/test', '/relative', '',
        ]);
    }

    public function test_wrong_origin_or_frame_is_ignored(): void
    {
        $payload = ['message' => 'monopay-link', 'value' => 'monobank://pay/test'];
        $this->assertSame([], $this->dispatchMessage($payload, origin: 'https://evil.example'));
        $this->assertSame([], $this->dispatchMessage($payload, expectedFrame: false));
        $this->assertSame([], $this->dispatchMessage(['message' => 'close-button'], origin: 'https://evil.example'));
    }

    public function test_json_messages_and_close_button_work_without_confirming_payment(): void
    {
        $this->assertSame(['https://mbnk.app/pay/test'], $this->dispatchMessage(json_encode(['message' => 'monopay-link', 'value' => 'https://mbnk.app/pay/test'])));
        $this->assertSame(['https://ladna.local/order'], $this->dispatchMessage(['message' => 'close-button']));
        $this->assertSame([], $this->dispatchMessage(['message' => 'success']));
    }

    #[DataProvider('malformedMessages')]
    public function test_malformed_messages_are_ignored(mixed $payload): void
    {
        $this->assertSame([], $this->dispatchMessage($payload));
    }

    public static function malformedMessages(): array
    {
        return [[null], ['{'], [1], [[]], [['message' => 'monopay-link', 'value' => 12]]];
    }

    /** @return list<string> */
    private function dispatchMessage(mixed $payload, string $origin = 'https://pay.monobank.ua', bool $expectedFrame = true): array
    {
        $script = <<<'JS'
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
const input = JSON.parse(process.argv[1]);
const source = readFileSync('resources/js/app.js', 'utf8');
const start = source.indexOf('function initEventMonopayIframes(');
const end = source.indexOf('function initEventTicketPdfSharing(', start);
if (start < 0 || end < 0) throw new Error('Payment handler not found');
const navigations = [];
const listeners = [];
const iframe = { contentWindow: {} };
const container = {
    dataset: { iframeOrigin: 'https://pay.monobank.ua', returnUrl: 'https://ladna.local/order' },
    querySelector: () => iframe,
};
const document = { querySelectorAll: () => [container] };
const window = {
    addEventListener: (type, listener) => { if (type === 'message') listeners.push(listener); },
    location: { assign: url => navigations.push(url) },
};
const context = vm.createContext({ window, document, URL });
vm.runInContext(source.slice(start, end) + '\ninitEventMonopayIframes(); initEventMonopayIframes();', context);
for (const listener of listeners) listener({
    origin: input.origin,
    source: input.expectedFrame ? iframe.contentWindow : {},
    data: input.payload,
});
process.stdout.write(JSON.stringify(navigations));
JS;
        $process = new Process(['node', '--input-type=module', '-e', $script, json_encode(compact('payload', 'origin', 'expectedFrame'), JSON_THROW_ON_ERROR)], dirname(__DIR__, 2));
        $process->mustRun();

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }
}
