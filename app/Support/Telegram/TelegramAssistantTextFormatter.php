<?php

namespace App\Support\Telegram;

class TelegramAssistantTextFormatter
{
    public function format(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = str_replace(
            ['$\\rightarrow$', '$\\to$', '\\rightarrow', '\\to'],
            '→',
            $text,
        );

        return collect(explode("\n", $text))
            ->map(function (string $line): string {
                if (preg_match('/^[ \t]{0,3}#{1,6}[ \t]+(.+)$/u', $line, $heading) === 1) {
                    return '<b>'.$this->escape($this->plainMarkdown($heading[1])).'</b>';
                }

                if (preg_match('/^[ \t]*[*\-•][ \t]+(.+)$/u', $line, $bullet) === 1) {
                    return '&#8226; '.$this->formatInline($bullet[1]);
                }

                return $this->formatInline($line);
            })
            ->implode("\n");
    }

    private function formatInline(string $text): string
    {
        $parts = preg_split(
            '/(\*\*.+?\*\*|`[^`\n]+`)/us',
            $text,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY,
        );

        if (! is_array($parts)) {
            return $this->escape($text);
        }

        return collect($parts)
            ->map(function (string $part): string {
                if (str_starts_with($part, '**') && str_ends_with($part, '**') && mb_strlen($part) > 4) {
                    return '<b>'.$this->escape(mb_substr($part, 2, -2)).'</b>';
                }

                if (str_starts_with($part, '`') && str_ends_with($part, '`') && mb_strlen($part) > 2) {
                    return '<code>'.$this->escape(mb_substr($part, 1, -1)).'</code>';
                }

                return $this->escape($part);
            })
            ->implode('');
    }

    private function plainMarkdown(string $text): string
    {
        $text = trim($text);

        if (str_starts_with($text, '**') && str_ends_with($text, '**') && mb_strlen($text) > 4) {
            return mb_substr($text, 2, -2);
        }

        return $text;
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
