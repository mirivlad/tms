<?php

declare(strict_types=1);

namespace Tms\Security;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

final class TaskDescriptionSanitizer
{
    /** @var array<string, true> */
    private const ALLOWED_TAGS = [
        'p' => true,
        'br' => true,
        'strong' => true,
        'b' => true,
        'em' => true,
        'i' => true,
        'u' => true,
        's' => true,
        'blockquote' => true,
        'pre' => true,
        'code' => true,
        'ul' => true,
        'ol' => true,
        'li' => true,
        'a' => true,
        'h1' => true,
        'h2' => true,
        'h3' => true,
    ];

    /** @var array<string, true> */
    private const DROP_WITH_CONTENT = [
        'script' => true,
        'style' => true,
        'iframe' => true,
        'object' => true,
        'embed' => true,
        'svg' => true,
        'math' => true,
        'form' => true,
        'input' => true,
        'button' => true,
        'textarea' => true,
        'select' => true,
        'option' => true,
        'link' => true,
        'meta' => true,
        'base' => true,
        'video' => true,
        'audio' => true,
        'source' => true,
        'canvas' => true,
    ];

    public function sanitize(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $previousErrors = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument('1.0', 'UTF-8');
            $loaded = $document->loadHTML(
                '<!doctype html><html><body><div id="tms-description-root">' . $html . '</div></body></html>',
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
            );
            if ($loaded === false) {
                return '';
            }

            $root = (new DOMXPath($document))->query('//*[@id="tms-description-root"]')?->item(0);
            if (!$root instanceof DOMElement) {
                return '';
            }

            $this->sanitizeChildren($root);

            $result = '';
            foreach ($root->childNodes as $child) {
                $serialized = $document->saveHTML($child);
                if ($serialized !== false) {
                    $result .= $serialized;
                }
            }

            return trim($result);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }
    }

    private function sanitizeChildren(DOMNode $parent): void
    {
        $child = $parent->firstChild;
        while ($child !== null) {
            $next = $child->nextSibling;
            $this->sanitizeNode($child);
            $child = $next;
        }
    }

    private function sanitizeNode(DOMNode $node): void
    {
        if (!$node instanceof DOMElement) {
            return;
        }

        $tag = strtolower($node->tagName);
        if (isset(self::DROP_WITH_CONTENT[$tag])) {
            $node->parentNode?->removeChild($node);
            return;
        }

        if (!isset(self::ALLOWED_TAGS[$tag])) {
            $this->sanitizeChildren($node);
            $this->unwrap($node);
            return;
        }

        $this->sanitizeAttributes($node, $tag);
        $this->sanitizeChildren($node);
    }

    private function sanitizeAttributes(DOMElement $element, string $tag): void
    {
        $href = $tag === 'a' ? trim($element->getAttribute('href')) : '';
        $title = $tag === 'a' ? trim($element->getAttribute('title')) : '';

        while ($element->attributes->length > 0) {
            $attribute = $element->attributes->item(0);
            if ($attribute === null) {
                break;
            }
            $element->removeAttributeNode($attribute);
        }

        if ($tag !== 'a') {
            return;
        }

        if ($this->isSafeLink($href)) {
            $element->setAttribute('href', $href);
            $element->setAttribute('rel', 'noopener noreferrer');
        }
        if ($title !== '') {
            $element->setAttribute('title', $this->cleanAttributeText($title));
        }
    }

    private function isSafeLink(string $href): bool
    {
        if ($href === '' || preg_match('/[\x00-\x1F\x7F]/', $href) === 1) {
            return false;
        }

        $decoded = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $scheme = parse_url($decoded, PHP_URL_SCHEME);
        if (!is_string($scheme)) {
            return false;
        }

        return in_array(strtolower($scheme), ['http', 'https', 'mailto'], true);
    }

    private function cleanAttributeText(string $value): string
    {
        return preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
    }

    private function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;
        if ($parent === null) {
            return;
        }

        while ($element->firstChild !== null) {
            $parent->insertBefore($element->firstChild, $element);
        }
        $parent->removeChild($element);
    }
}
