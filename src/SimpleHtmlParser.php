<?php declare(strict_types=1);

namespace Kuria\SimpleHtmlParser;

/**
 * Simple HTML parser
 *
 * - tag and attribute names that contain only ASCII characters are lowercased
 * - loosely based on http://www.w3.org/TR/2011/WD-html5-20110113/parsing.html
 */
class SimpleHtmlParser implements \Iterator
{
    const COMMENT = 0;
    const OPENING_TAG = 1;
    const CLOSING_TAG = 2;
    const OTHER = 3;
    const INVALID = 4;

    /** @see http://www.w3.org/TR/html5/syntax.html#parsing-html-fragments */
    protected const RAWTEXT_TAG_MAP = [
        'style' => 0,
        'script' => 1,
        'noscript' => 2,
        'iframe' => 3,
        'noframes' => 4,
    ];

    /** Lowercase map of encodings supported by htmlspecialchars() */
    protected const SUPPORTED_ENCODING_MAP = [
        'iso-8859-1' => 0, 'iso8859-1' => 1, 'iso-8859-5' => 2, 'iso-8859-15' => 3,
        'iso8859-15' => 4, 'utf-8' => 5, 'cp866' => 6, 'ibm866' => 7,
        '866' => 8, 'cp1251' => 9, 'windows-1251' => 10, 'win-1251' => 11,
        '1251' => 12, 'cp1252' => 13, 'windows-1252' => 14, '1252' => 15,
        'koi8-r' => 16, 'koi8-ru' => 17, 'koi8r' => 18, 'big5' => 19, '950' => 20,
        'gb2312' => 21, '936' => 22, 'big5-hkscs' => 23, 'shift_jis' => 24,
        'sjis' => 25, 'sjis-win' => 26, 'cp932' => 27, '932' => 28,
        'euc-jp' => 29, 'eucjp' => 30, 'eucjp-win' => 31, 'macroman' => 32,
    ];

    /** @var string */
    private $html;
    /** @var int */
    private $length;
    /** @var bool iteration state */
    private $valid = true;
    /** @var int */
    private $offset = 0;
    /** @var int|null */
    private $index;
    /** @var array|null */
    private $current;
    /** @var array[] */
    private $stateStack = [];
    /** @var array|null */
    private $encodingInfo;
    /** @var string */
    private $fallbackEncoding = 'utf-8';
    /** @var array|false|null */
    private $doctypeElement;

    function __construct(string $html)
    {
        $this->html = $html;
        $this->length = strlen($html);
    }

    /**
     * Get HTML content
     *
     * - if an element is given, returns only the given element
     * - if no element is given, returns the entire document
     */
    function getHtml(?array $element = null): string
    {
        return $element !== null
            ? substr($this->html, $element['start'], $element['end'] - $element['start'])
            : $this->html;
    }

    /**
     * Extract a part of the HTML
     *
     * Returns an empty string for negative or out-of-bounds ranges.
     */
    function getSlice(int $start, int $end): string
    {
        if ($start === $end || $start < 0 || $end < 0) {
            return '';
        }

        if ($start < $end) {
            return (string) substr($this->html, $start, $end - $start);
        } else {
            return (string) substr($this->html, $end, $start - $end);
        }
    }

    /**
     * Extract a part of the HTML between 2 elements
     */
    function getSliceBetween(array $a, array $b): string
    {
        if ($a['start'] > $b['start']) {
            $start = $b['end'];
            $end = $a['start'];
        } else {
            $start = $a['end'];
            $end = $b['start'];
        }

        return $this->getSlice($start, $end);
    }

    /**
     * Get length of the HTML
     */
    function getLength(): int
    {
        return $this->length;
    }

    /**
     * Get encoding of the document
     */
    function getEncoding(): string
    {
        return $this->getEncodingInfo()['encoding'];
    }

    /**
     * Get the encoding-specifying meta tag, if any
     *
     * (META charset or META http-equiv="Content-Type")
     */
    function getEncodingTag(): ?array
    {
        return $this->getEncodingInfo()['tag'];
    }

    /**
     * See if the fallback encoding is being used
     */
    function usesFallbackEncoding(): bool
    {
        return $this->getEncodingInfo()['is_fallback'];
    }

    /**
     * Set fallback encoding
     *
     * - used if no encoding is specified or the specified encoding is unsupported
     * - setting after the encoding has been determined has no effect
     * - must be supported by htmlspecialchars()
     *
     * @throws \InvalidArgumentException if the encoding is not supported
     */
    function setFallbackEncoding(string $fallbackEncoding): void
    {
        $fallbackEncoding = strtolower($fallbackEncoding);

        if (!isset(static::SUPPORTED_ENCODING_MAP[$fallbackEncoding])) {
            throw new \InvalidArgumentException(sprintf('Unsupported fallback encoding "%s"', $fallbackEncoding));
        }

        $this->fallbackEncoding = $fallbackEncoding;
    }

    /**
     * Get the doctype element, if any
     *
     * Returns an element of type OTHER, with an extra "content" key.
     */
    function getDoctypeElement(): ?array
    {
        if ($this->doctypeElement === null) {
            $this->doctypeElement = $this->findDoctype();
        }

        return $this->doctypeElement ?: null;
    }

    /**
     * Escape a string
     *
     * @see htmlspecialchars()
     */
    function escape(string $string, int $mode = ENT_QUOTES, bool $doubleEncode = true): string
    {
        return htmlspecialchars($string, $mode, $this->getEncodingInfo()['encoding'], $doubleEncode);
    }

    /**
     * Find a specific element starting from the current offset
     *
     * - $tagName should be lowercase.
     * - stops searching after $stopOffset is reached, if specified (soft limit).
     */
    function find(int $elemType, ?string $tagName = null, ?int $stopOffset = null): ?array
    {
        if ($tagName !== null && static::OPENING_TAG !== $elemType && static::CLOSING_TAG !== $elemType) {
            throw new \LogicException('Can only specify tag name when searching for OPENING_TAG or CLOSING_TAG');
        }

        while ($this->valid && ($stopOffset === null || $this->offset < $stopOffset)) {
            $this->next();

            if (
                $this->valid
                && $elemType === $this->current['type']
                && ($tagName === null || $this->current['name'] === $tagName)
            ) {
                return $this->current;
            }
        }

        return null;
    }

    /**
     * Get current offset
     */
    function getOffset(): int
    {
        return $this->offset;
    }

    /**
     * Store current iteration state
     *
     * Use revertState() or popState() when you are done.
     */
    function pushState(): void
    {
        $this->stateStack[] = [$this->valid, $this->offset, $this->index, $this->current];
    }

    /**
     * Pop the last stored iteration state without reverting to it
     *
     * @throws \LogicException if there are no states on the stack
     */
    function popState(): void
    {
        if (array_pop($this->stateStack) === null) {
            throw new \LogicException('The state stack is empty');
        }
    }

    /**
     * Revert to an earlier iteration state
     *
     * @throws \LogicException if there are no states on the stack
     */
    function revertState(): void
    {
        if (!$this->stateStack) {
            throw new \LogicException('The state stack is empty');
        }

        [$this->valid, $this->offset, $this->index, $this->current] = array_pop($this->stateStack);
    }

    /**
     * Get number of states on the stack
     */
    function countStates(): int
    {
        return count($this->stateStack);
    }

    /**
     * Throw away all stored states
     */
    function clearStates(): void
    {
        $this->stateStack = [];
    }

    function current(): ?array
    {
        if ($this->current === null && $this->valid) {
            $this->next();
        }

        return $this->current;
    }

    function key(): ?int
    {
        if ($this->current === null && $this->valid) {
            $this->next();
        }

        return $this->index;
    }

    function next(): void
    {
        if (!$this->valid) {
            return;
        }

        // skip contents of known RAWTEXT tags
        if (
            $this->current !== null
            && $this->current['type'] === static::OPENING_TAG
            && isset(static::RAWTEXT_TAG_MAP[$this->current['name']])
        ) {
            $this->offset = ($end = stripos($this->html, "</{$this->current['name']}>", $this->offset)) !== false
                ? $end
                : $this->length;
        }

        // match a thing
        $this->current = $this->match($this->offset);

        if ($this->current !== null) {
            // advance offset and index
            $this->offset = $this->current['end'];

            if ($this->index !== null) {
                ++$this->index;
            } else {
                $this->index = 0;
            }
        } else {
            // could not match anything
            $this->offset = $this->length;
            $this->valid = false;
        }
    }

    function rewind(): void
    {
        $this->valid = true;
        $this->offset = 0;
        $this->index = null;
        $this->current = null;
    }

    function valid(): bool
    {
        if ($this->current === null && $this->valid) {
            $this->next();
        }

        return $this->valid;
    }

    /**
     * Match HTML element at the current offset
     */
    private function match(int $offset): ?array
    {
        $result = null;

        if (
            $offset < $this->length
            && preg_match('{<!--|<(/?)([\w\-:\x80-\xFF]+)|<[!?/]}', $this->html, $match, PREG_OFFSET_CAPTURE, $offset)
        ) {
            if ($match[0][0] === '<!--') {
                // comment
                $offset = $match[0][1] + 3;

                if (($end = strpos($this->html, '-->', $offset)) !== false) {
                    $result = [
                        'type' => static::COMMENT,
                        'start' => $match[0][1],
                        'end' => $end + 3,
                    ];
                }
            } elseif (isset($match[1])) {
                // opening or closing tag
                $offset = $match[0][1] + strlen($match[0][0]);
                [$attrs, $offset] = $this->matchAttributes($offset);
                preg_match('{\s*/?>}A', $this->html, $endMatch, 0, $offset);

                $isClosingTag = $match[1][0] === '/';

                $result = [
                    'type' => $isClosingTag ? static::CLOSING_TAG : static::OPENING_TAG,
                    'start' => $match[0][1],
                    'end' => $offset + ($endMatch ? strlen($endMatch[0]) : 0),
                    'name' => $this->normalizeIdentifier($match[2][0]),
                ];

                if (!$isClosingTag) {
                    $result['attrs'] = $attrs;
                }
            } else {
                // other
                $offset = $match[0][1] + 2;
                $end = strpos($this->html, '>', $offset);

                $result = $end !== false
                    ? [
                        'type' => static::OTHER,
                        'symbol' => $match[0][0][1],
                        'start' => $match[0][1],
                        'end' => $end + 1,
                    ]
                    : [
                        'type' => static::INVALID,
                        'start' => $match[0][1],
                        'end' => $match[0][1] + 2,
                    ];
            }
        }

        return $result;
    }

    /**
     * Match tag attributes
     *
     * Returns [attributes, offset] tuple.
     */
    private function matchAttributes(int $offset): array
    {
        $attrs = [];

        while (
            $offset >= 0
            && $offset < $this->length
            && preg_match('{\s*([^\x00-\x20"\'>/=]+)}A', $this->html, $match, 0, $offset)
        ) {
            $name = $match[1];
            $value = true;
            $offset += strlen($match[0]);

            // parse value
            if (preg_match('{\s*=\s*}A', $this->html, $match, 0, $offset)) {
                $offset += strlen($match[0]);

                if ($offset < $this->length) {
                    if ($this->html[$offset] === '"' || $this->html[$offset] === '\'') {
                        // quoted
                        if (preg_match('{"([^"]*+)"|\'([^\']*+)\'}A', $this->html, $match, 0, $offset)) {
                            $value = $match[2] ?? $match[1];
                            $offset += strlen($match[0]);
                        }
                    } elseif (preg_match('{[^\s"\'=<>`]++}A', $this->html, $match, 0, $offset)) {
                        // unquoted
                        $value = $match[0];
                        $offset += strlen($match[0]);
                    }
                }
            }

            $attrs[$this->normalizeIdentifier($name)] = $value;
        }

        return [$attrs, $offset];
    }

    private function normalizeIdentifier(string $name): string
    {
        // lowercase only if the name consists of ASCII characters
        if (preg_match('{[^\x80-\xFF]+$}AD', $name)) {
            return strtolower($name);
        }

        return $name;
    }

    /**
     * Try to find the doctype in the first 1024 bytes of the document
     */
    private function findDoctype(): ?array
    {
        $found = false;
        $this->pushState();

        try {
            $this->rewind();

            while ($element = $this->find(static::OTHER, null, 1024)) {
                if ($element['symbol'] === '!') {
                    $content = substr($this->html, $element['start'] + 2, $element['end'] - $element['start'] - 3);

                    if (strncasecmp('doctype', $content, 7) === 0) {
                        $element['content'] = $content;
                        $found = true;
                        break;
                    }
                }
            }
        } finally {
            $this->revertState();
        }

        return $found ? $element : null;
    }

    /**
     * Try to determine the encoding from the first 1024 bytes of the document
     */
    private function getEncodingInfo(): array
    {
        if ($this->encodingInfo !== null) {
            return $this->encodingInfo;
        }

        // http://www.w3.org/TR/html5/syntax.html#determining-the-character-encoding
        // http://www.w3.org/TR/html5/document-metadata.html#charset

        $this->pushState();

        try {
            $this->rewind();

            $found = false;
            $pragma = false;

            while ($metaTag = $this->find(static::OPENING_TAG, 'meta', 1024)) {
                if (isset($metaTag['attrs']['charset'])) {
                    $found = true;
                    break;
                } elseif (
                    isset($metaTag['attrs']['http-equiv'], $metaTag['attrs']['content'])
                    && strcasecmp($metaTag['attrs']['http-equiv'], 'content-type') === 0
                ) {
                    $found = true;
                    $pragma = true;
                    break;
                }
            }
        } finally {
            $this->revertState();
        }

        // handle the result
        $encoding = null;
        $isFallback = false;

        if ($found) {
            if ($pragma) {
                $encoding = static::parseCharsetFromContentType($metaTag['attrs']['content']);
            } else {
                $encoding = $metaTag['attrs']['charset'];
            }
        }

        if ($encoding !== null) {
            $encoding = strtolower($encoding);
        }

        if ($encoding === null || !isset(static::SUPPORTED_ENCODING_MAP[$encoding])) {
            // no encoding has been specified or it is not supported
            $encoding = $this->fallbackEncoding;
            $isFallback = true;
        }

        return $this->encodingInfo = [
            'encoding' => $encoding,
            'tag' => $found ? $metaTag : null,
            'is_fallback' => $isFallback,
        ];
    }

    /**
     * Attempt to extract the charset from a Content-Type header
     */
    static function parseCharsetFromContentType(string $contentType): ?string
    {
        // http://www.w3.org/TR/2011/WD-html5-20110113/fetching-resources.html#algorithm-for-extracting-an-encoding-from-a-content-type
        return preg_match('{charset\s*+=\s*+(["\'])?+(?(1)(.+)(?=\1)|([^\s;]+))}i', $contentType, $match)
            ? ($match[3] ?? $match[2])
            : null;
    }
}
