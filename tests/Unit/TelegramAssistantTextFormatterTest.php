<?php

namespace Tests\Unit;

use App\Support\Telegram\TelegramAssistantTextFormatter;
use PHPUnit\Framework\TestCase;

class TelegramAssistantTextFormatterTest extends TestCase
{
    public function test_it_formats_supported_markdown_and_preserves_paragraphs_safely(): void
    {
        $text = <<<'TEXT'
### **Несплачені абонементи**

* `B8V2-LJ7L` $\rightarrow$ **1 100 грн**
- <script>alert("x")</script>
TEXT;

        $formatted = (new TelegramAssistantTextFormatter)->format($text);

        $this->assertSame(
            "<b>Несплачені абонементи</b>\n\n&#8226; <code>B8V2-LJ7L</code> → <b>1 100 грн</b>\n&#8226; &lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;",
            $formatted,
        );
    }
}
