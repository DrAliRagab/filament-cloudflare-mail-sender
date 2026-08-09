<?php

declare(strict_types=1);

namespace DrAliRagab\FilamentCloudflareMailSender\Rendering;

use DrAliRagab\FilamentCloudflareMailSender\Contracts\MessageBodyRenderer;
use DrAliRagab\FilamentCloudflareMailSender\Data\RenderedMessageBody;
use Illuminate\Support\Str;

final class CommonMarkMessageBodyRenderer implements MessageBodyRenderer
{
    private const MAX_NESTING_LEVEL = 20;

    private const MAX_DELIMITERS_PER_LINE = 1_000;

    public function render(string $markdown): RenderedMessageBody
    {
        $html = Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => self::MAX_NESTING_LEVEL,
            'max_delimiters_per_line' => self::MAX_DELIMITERS_PER_LINE,
        ]);

        return new RenderedMessageBody(
            html: $html,
            text: $this->toPlainText($html),
        );
    }

    private function toPlainText(string $html): string
    {
        $withLineBreaks = preg_replace(
            '/<\\/?(?:article|blockquote|div|h[1-6]|li|ol|p|pre|section|table|tr|ul)\\b[^>]*>|<br\\s*\\/?\\s*>/i',
            "\n",
            $html,
        );

        $text = html_entity_decode(
            strip_tags($withLineBreaks ?? $html),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8',
        );
        $text = preg_replace('/[ \\t]+\\n/', "\n", $text) ?? $text;
        $text = preg_replace('/\\n[ \\t]+/', "\n", $text) ?? $text;
        $text = preg_replace('/\\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
