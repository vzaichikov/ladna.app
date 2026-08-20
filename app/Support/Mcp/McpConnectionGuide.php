<?php

namespace App\Support\Mcp;

use App\Models\Account;
use Illuminate\Support\Str;

class McpConnectionGuide
{
    /**
     * @return array{
     *     studio_name: string,
     *     connection_name: string,
     *     connection_url: string,
     *     public_guide_url: string,
     *     instructions_url: string,
     *     setup_prompt: string,
     *     facts: array<int, array{title: string, body: string, icon: string}>,
     *     clients: array<int, array{name: string, copy: string, steps: array<int, string>, help_url: string|null}>,
     *     examples: array<int, array{prompt: string, permission_note: string|null}>,
     *     troubleshooting: array<int, array{title: string, body: string}>
     * }
     */
    public function forAccount(Account $account): array
    {
        $studioName = $this->safeStudioName($account);
        $connectionName = __('app.mcp_guide_connection_name', ['studio' => $studioName]);
        $connectionUrl = route('mcp.ladna-studio.oauth', ['accountSlug' => $account->slug]);
        $publicGuideUrl = route('mcp.connection-guide.show', $account);
        $instructionsUrl = route('mcp.connection-guide.markdown', $account);

        return [
            'studio_name' => $studioName,
            'connection_name' => $connectionName,
            'connection_url' => $connectionUrl,
            'public_guide_url' => $publicGuideUrl,
            'instructions_url' => $instructionsUrl,
            'setup_prompt' => __('app.mcp_guide_setup_prompt', [
                'instructions_url' => $instructionsUrl,
                'connection_name' => $connectionName,
            ]),
            'facts' => [
                [
                    'title' => __('app.mcp_guide_fact_studio_title'),
                    'body' => __('app.mcp_guide_fact_studio_body'),
                    'icon' => 'sparkles',
                ],
                [
                    'title' => __('app.mcp_guide_fact_sign_in_title'),
                    'body' => __('app.mcp_guide_fact_sign_in_body'),
                    'icon' => 'user',
                ],
                [
                    'title' => __('app.mcp_guide_fact_read_only_title'),
                    'body' => __('app.mcp_guide_fact_read_only_body'),
                    'icon' => 'eye',
                ],
            ],
            'clients' => [
                $this->client('chatgpt', 'https://help.openai.com/en/articles/12584461-developer-mode-and-full-mcp-connectors-in-chatgpt'),
                $this->client('claude', 'https://support.anthropic.com/en/articles/11175166-about-custom-integrations-using-remote-mcp'),
                $this->client('other'),
            ],
            'examples' => [
                [
                    'prompt' => __('app.mcp_guide_example_studio'),
                    'permission_note' => null,
                ],
                [
                    'prompt' => __('app.mcp_guide_example_classes'),
                    'permission_note' => null,
                ],
                [
                    'prompt' => __('app.mcp_guide_example_help'),
                    'permission_note' => null,
                ],
                [
                    'prompt' => __('app.mcp_guide_example_finance'),
                    'permission_note' => __('app.mcp_guide_permission_example_note'),
                ],
            ],
            'troubleshooting' => [
                [
                    'title' => __('app.mcp_guide_trouble_missing_title'),
                    'body' => __('app.mcp_guide_trouble_missing_body'),
                ],
                [
                    'title' => __('app.mcp_guide_trouble_studio_title'),
                    'body' => __('app.mcp_guide_trouble_studio_body'),
                ],
                [
                    'title' => __('app.mcp_guide_trouble_access_title'),
                    'body' => __('app.mcp_guide_trouble_access_body'),
                ],
            ],
        ];
    }

    public function markdown(Account $account): string
    {
        $guide = $this->forAccount($account);
        $lines = [
            '# '.__('app.mcp_guide_markdown_title', ['studio' => $this->markdownEscape($guide['studio_name'])]),
            '',
            __('app.mcp_guide_markdown_intro'),
            '',
            '## '.__('app.mcp_guide_markdown_details'),
            '',
            '- '.__('app.mcp_guide_markdown_name').': `'.$this->inlineCode($guide['connection_name']).'`',
            '- '.__('app.mcp_guide_markdown_url').': `'.$this->inlineCode($guide['connection_url']).'`',
            '- '.__('app.mcp_guide_markdown_sign_in').': '.__('app.mcp_guide_markdown_sign_in_value'),
            '- '.__('app.mcp_guide_markdown_access').': '.__('app.mcp_guide_markdown_access_value'),
            '',
            '## '.__('app.mcp_guide_markdown_action_title'),
            '',
            '1. '.__('app.mcp_guide_markdown_action_1'),
            '2. '.__('app.mcp_guide_markdown_action_2', ['name' => '`'.$this->inlineCode($guide['connection_name']).'`']),
            '3. '.__('app.mcp_guide_markdown_action_3', ['url' => '`'.$this->inlineCode($guide['connection_url']).'`']),
            '4. '.__('app.mcp_guide_markdown_action_4'),
            '5. '.__('app.mcp_guide_markdown_action_5'),
            '',
            '## '.__('app.mcp_guide_markdown_fallback_title'),
            '',
            __('app.mcp_guide_markdown_fallback_body', ['url' => '`'.$this->inlineCode($guide['connection_url']).'`']),
            '',
            '## '.__('app.mcp_guide_examples_title'),
            '',
        ];

        foreach ($guide['examples'] as $example) {
            $lines[] = '- “'.$this->markdownEscape($example['prompt']).'”'.($example['permission_note'] ? ' — '.$this->markdownEscape($example['permission_note']) : '');
        }

        $lines = [
            ...$lines,
            '',
            '## '.__('app.mcp_guide_safety_title'),
            '',
            '- '.__('app.mcp_guide_markdown_safety_password'),
            '- '.__('app.mcp_guide_markdown_safety_read_only'),
            '- '.__('app.mcp_guide_markdown_safety_permissions'),
            '- '.__('app.mcp_guide_markdown_safety_disconnect'),
            '',
        ];

        return implode("\n", $lines);
    }

    /**
     * @return array{name: string, copy: string, steps: array<int, string>, help_url: string|null}
     */
    private function client(string $client, ?string $helpUrl = null): array
    {
        return [
            'name' => __('app.mcp_guide_client_'.$client.'_name'),
            'copy' => __('app.mcp_guide_client_'.$client.'_copy'),
            'steps' => collect(range(1, 4))
                ->map(fn (int $step): string => __('app.mcp_guide_client_'.$client.'_step_'.$step))
                ->all(),
            'help_url' => $helpUrl,
        ];
    }

    private function safeStudioName(Account $account): string
    {
        return Str::of(strip_tags($account->name))
            ->replace('`', '')
            ->squish()
            ->limit(120, '')
            ->toString();
    }

    private function markdownEscape(string $value): string
    {
        return str_replace(
            ['\\', '`', '*', '_', '{', '}', '[', ']', '<', '>', '#'],
            ['\\\\', '\\`', '\\*', '\\_', '\\{', '\\}', '\\[', '\\]', '\\<', '\\>', '\\#'],
            $value,
        );
    }

    private function inlineCode(string $value): string
    {
        return str_replace('`', '', $value);
    }
}
