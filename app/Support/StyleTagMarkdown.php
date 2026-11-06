<?php

namespace App\Support;

use Illuminate\Mail\Markdown;

/**
 * Markdown emails without the PHP dom extension: the theme's CSS goes into a <style> tag instead of being inlined
 * into each element (inlining needs DOMDocument). Most mail apps show these emails the same way.
 */
class StyleTagMarkdown extends Markdown
{
    public function render($view, array $data = [], $inliner = null)
    {
        return parent::render($view, $data, $inliner ?? new class
        {
            public function convert(string $html, string $css = ''): string
            {
                $style = '<style type="text/css">'.$css.'</style>';

                return str_contains($html, '</head>') ? str_replace('</head>', $style.'</head>', $html) : $style.$html;
            }
        });
    }
}
