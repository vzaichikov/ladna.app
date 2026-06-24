<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

class StudioRulesHtmlSanitizer
{
    private const ALLOWED_TAGS = [
        'a',
        'blockquote',
        'br',
        'div',
        'em',
        'h1',
        'h2',
        'h3',
        'h4',
        'i',
        'li',
        'ol',
        'p',
        's',
        'span',
        'strong',
        'u',
        'ul',
    ];

    private const STYLED_TAGS = [
        'blockquote',
        'div',
        'h1',
        'h2',
        'h3',
        'h4',
        'li',
        'ol',
        'p',
        'span',
        'ul',
    ];

    private const BLOCKED_TAGS = [
        'audio',
        'button',
        'canvas',
        'embed',
        'form',
        'iframe',
        'img',
        'input',
        'math',
        'object',
        'script',
        'select',
        'style',
        'svg',
        'textarea',
        'video',
    ];

    /**
     * @return string|null Sanitized HTML, or null when no useful content remains.
     */
    public function sanitize(?string $html): ?string
    {
        $html = trim((string) $html);

        if ($html === '') {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->loadHTML(
            '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body><div id="studio-rules-root">'.$html.'</div></body></html>',
            LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = (new DOMXPath($document))->query('//*[@id="studio-rules-root"]')->item(0);

        if (! $root) {
            return null;
        }

        $this->sanitizeChildren($root);

        $cleanHtml = trim($this->innerHtml($root));

        return $cleanHtml === '' ? null : $cleanHtml;
    }

    private function sanitizeChildren(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                $tagName = strtolower($child->tagName);

                if (in_array($tagName, self::BLOCKED_TAGS, true)) {
                    $node->removeChild($child);

                    continue;
                }

                if (! in_array($tagName, self::ALLOWED_TAGS, true)) {
                    $this->sanitizeChildren($child);
                    $this->unwrap($child);

                    continue;
                }

                $this->sanitizeElement($child, $tagName);
                $this->sanitizeChildren($child);

                continue;
            }

            if ($child->nodeType === XML_COMMENT_NODE) {
                $node->removeChild($child);
            }
        }
    }

    private function sanitizeElement(DOMElement $element, string $tagName): void
    {
        foreach (iterator_to_array($element->attributes) as $attribute) {
            $name = strtolower($attribute->name);
            $value = trim($attribute->value);

            if ($tagName === 'a' && in_array($name, ['href', 'rel', 'target', 'title'], true)) {
                $this->sanitizeAnchorAttribute($element, $name, $value);

                continue;
            }

            if ($name === 'style' && in_array($tagName, self::STYLED_TAGS, true)) {
                $style = $this->sanitizeStyle($value);

                if ($style === null) {
                    $element->removeAttribute($attribute->name);
                } else {
                    $element->setAttribute('style', $style);
                }

                continue;
            }

            $element->removeAttribute($attribute->name);
        }

        if ($tagName === 'a' && $element->getAttribute('target') === '_blank') {
            $element->setAttribute('rel', 'noopener noreferrer');
        }
    }

    private function sanitizeAnchorAttribute(DOMElement $element, string $name, string $value): void
    {
        if ($name === 'href') {
            $href = $this->sanitizeHref($value);

            if ($href === null) {
                $element->removeAttribute('href');

                return;
            }

            $element->setAttribute('href', $href);

            return;
        }

        if ($name === 'target') {
            if (! in_array($value, ['_blank', '_self'], true)) {
                $element->removeAttribute('target');
            }

            return;
        }

        if ($name === 'rel') {
            $element->removeAttribute('rel');
        }
    }

    private function sanitizeHref(string $href): ?string
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($href === '' || preg_match('/[\x00-\x1F\x7F]/', $href) === 1) {
            return null;
        }

        if (str_starts_with($href, '#') || str_starts_with($href, '/')) {
            return $href;
        }

        $scheme = parse_url($href, PHP_URL_SCHEME);

        if (! is_string($scheme)) {
            return null;
        }

        return in_array(strtolower($scheme), ['http', 'https', 'mailto', 'tel'], true) ? $href : null;
    }

    private function sanitizeStyle(string $style): ?string
    {
        $allowed = [];

        foreach (explode(';', $style) as $declaration) {
            [$property, $value] = array_pad(explode(':', $declaration, 2), 2, null);
            $property = strtolower(trim((string) $property));
            $value = strtolower(trim((string) $value));

            if ($property === 'text-align' && in_array($value, ['left', 'right', 'center', 'justify'], true)) {
                $allowed[] = "text-align: {$value};";
            }
        }

        return $allowed === [] ? null : implode(' ', $allowed);
    }

    private function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;

        if (! $parent) {
            return;
        }

        while ($element->firstChild) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $parent->removeChild($element);
    }

    private function innerHtml(DOMNode $node): string
    {
        $html = '';

        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument?->saveHTML($child) ?? '';
        }

        return $html;
    }
}
