<?php

namespace App\Services;

use League\CommonMark\GithubFlavoredMarkdownConverter;

final class SafeMarkdown
{
    private const MAX_BYTES = 65536;

    private readonly GithubFlavoredMarkdownConverter $converter;

    public function __construct()
    {
        $this->converter = new GithubFlavoredMarkdownConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 24,
            'max_delimiters_per_line' => 512,
        ]);
    }

    public function render(string $markdown): string
    {
        // Limit previously persisted content as well as new form input.
        $markdown = mb_strcut($markdown, 0, self::MAX_BYTES, 'UTF-8');

        return $this->converter->convert($markdown)->getContent();
    }
}
