<?php declare(strict_types=1);

namespace Kuria\SimpleHtmlParser;

use Kuria\DevMeta\Test;
use PHPUnit\Framework\AssertionFailedError;

class SimpleHtmlParserTest extends Test
{
    function testShouldGetLengthAndHtml()
    {
        $parser = new SimpleHtmlParser('abc');

        $this->assertSame(3, $parser->getLength());
        $this->assertSame('abc', $parser->getHtml());
    }

    /**
     * @dataProvider provideMatchCases
     */
    function testShouldMatch(string $html, array $expectedKeys)
    {
        $parser = new SimpleHtmlParser($html);

        $this->assertElement($parser->current(), $expectedKeys);
    }

    function provideMatchCases(): array
    {
        return [
            // html, expectedKeys
            'comment' => [
                '<!-- foo bar -->',
                [
                    'type' => SimpleHtmlParser::COMMENT,
                    'start' => 0,
                    'end' => 16,
                ],
            ],

            'opening tag' => [
                '<P>',
                [
                    'type' => SimpleHtmlParser::OPENING_TAG,
                    'start' => 0,
                    'end' => 3,
                    'name' => 'p',
                    'attrs' => [],
                ],
            ],

            'opening tag with special chars' => [
                '<Foo:barž>',
                [
                    'type' => SimpleHtmlParser::OPENING_TAG,
                    'start' => 0,
                    'end' => 11,
                    'name' => 'Foo:barž',
                    'attrs' => [],
                ],
            ],

            'opening tag with attrs' => [
                '<A HREF="http://example.com?FOO" id="foo"  class=link >',
                [
                    'type' => SimpleHtmlParser::OPENING_TAG,
                    'start' => 0,
                    'end' => 55,
                    'name' => 'a',
                    'attrs' => ['href' => 'http://example.com?FOO', 'id' => 'foo', 'class' => 'link'],
                ],
            ],

            'self-closing tag' => [
                '<hr />',
                [
                    'type' => SimpleHtmlParser::OPENING_TAG,
                    'start' => 0,
                    'end' => 6,
                    'name' => 'hr',
                    'attrs' => [],
                ],
            ],

            'self-closing tag with attrs' => [
                '<hr data-lorem="ipsum" />',
                [
                    'type' => SimpleHtmlParser::OPENING_TAG,
                    'start' => 0,
                    'end' => 25,
                    'name' => 'hr',
                    'attrs' => ['data-lorem' => 'ipsum'],
                ],
            ],

            'unterminated opening tag' => [
                '<a href="http://example.com/"',
                [
                    'type' => SimpleHtmlParser::OPENING_TAG,
                    'start' => 0,
                    'end' => 29,
                    'name' => 'a',
                    'attrs' => ['href' => 'http://example.com/'],
                ],
            ],

            'unterminated opening tag followed by another element' => [
                '<a href="http://example.com/"<br id="foo">',
                [
                    'type' => SimpleHtmlParser::OPENING_TAG,
                    'start' => 0,
                    'end' => 42,
                    'name' => 'a',
                    'attrs' => ['href' => 'http://example.com/', '<br' => true, 'id' => 'foo'],
                ],
            ],

            'closing tag' => [
                '</A>',
                [
                    'type' => SimpleHtmlParser::CLOSING_TAG,
                    'start' => 0,
                    'end' => 4,
                    'name' => 'a',
                ],
            ],

            'closing tag with special chars' => [
                '</Foo-barž>',
                [
                    'type' => SimpleHtmlParser::CLOSING_TAG,
                    'start' => 0,
                    'end' => 12,
                    'name' => 'Foo-barž',
                ],
            ],

            'closing tag with attrs' => [
                '</A id="nonsense">',
                [
                    'type' => SimpleHtmlParser::CLOSING_TAG,
                    'start' => 0,
                    'end' => 18,
                    'name' => 'a',
                ],
            ],

            'doctype' => [
                '<!doctype html>',
                [
                    'type' => SimpleHtmlParser::OTHER,
                    'start' => 0,
                    'end' => 15,
                    'symbol' => '!',
                ],
            ],

            'xml header' => [
                '<?xml version="1.0" ?>',
                [
                    'type' => SimpleHtmlParser::OTHER,
                    'start' => 0,
                    'end' => 22,
                    'symbol' => '?',
                ],
            ],

            'invalid closing tag' => [
                '</ div>',
                [
                    'type' => SimpleHtmlParser::OTHER,
                    'start' => 0,
                    'end' => 7,
                    'symbol' => '/',
                ],
            ],

            'invalid 1' => [
                '<?',
                [
                    'type' => SimpleHtmlParser::INVALID,
                    'start' => 0,
                    'end' => 2,
                ],
            ],

            'invalid 2' => [
                '<!',
                [
                    'type' => SimpleHtmlParser::INVALID,
                    'start' => 0,
                    'end' => 2,
                ],
            ],
        ];
    }

    /**
     * @dataProvider provideNonMatchingCases
     */
    function testShouldFailToMatch(string $html)
    {
        $parser = new SimpleHtmlParser($html);

        $this->assertNull($parser->current());
    }

    function provideNonMatchingCases(): array
    {
        return [
            ['<'],
            ['< foo'],
            ['<+bar'],
            ['<#'],
        ];
    }

    function testShouldFind()
    {
        $html = <<<HTML
<!doctype html>
<!-- <title>Not a title</title> -->
<meta name="foo" content="first">
<title>Lorem ipsum</title>
<meta name="bar" content="second">
HTML;

        $parser = new SimpleHtmlParser($html);

        $this->assertElement($parser->find(SimpleHtmlParser::OPENING_TAG, 'title'), [
            'type' => SimpleHtmlParser::OPENING_TAG,
            'start' => 86,
            'end' => 93,
            'name' => 'title',
        ]);

        // find should work with and alter the iterator's position
        // finding any opening tag after the title should yield the second meta tag
        $this->assertElement($parser->find(SimpleHtmlParser::OPENING_TAG), [
            'type' => SimpleHtmlParser::OPENING_TAG,
            'start' => 113,
            'end' => 147,
            'name' => 'meta',
            'attrs' => ['name' => 'bar', 'content' => 'second'],
        ]);
    }

    function testShouldFindNonTags()
    {
        $html = <<<HTML
<!doctype html>
<title>Lorem ipsum</title>
<!-- foo bar -->
HTML;

        $parser = new SimpleHtmlParser($html);

        $this->assertElement($parser->find(SimpleHtmlParser::OTHER), [
            'type' => SimpleHtmlParser::OTHER,
            'start' => 0,
            'end' => 15,
            'symbol' => '!',
        ]);

        $this->assertElement($parser->find(SimpleHtmlParser::COMMENT), [
            'type' => SimpleHtmlParser::COMMENT,
            'start' => 43,
            'end' => 59,
        ]);
    }

    function testShouldNotFindElementIfStopOffsetIsInsideAnotherElement()
    {
        $html = <<<HTML
<!-- foo bar -->
Lorem ipsum dolor sit amet<br>
HTML;

        $parser = new SimpleHtmlParser($html);

        $this->assertNull($parser->find(SimpleHtmlParser::OPENING_TAG, 'br', 10));
    }

    function testShouldNotFindElementIfStopOffsetIsExactlyAfterAnotherElement()
    {
        $html = <<<HTML
<!-- foo bar -->
Lorem ipsum dolor sit amet<br>
HTML;

        $parser = new SimpleHtmlParser($html);

        $this->assertNull($parser->find(SimpleHtmlParser::OPENING_TAG, 'br', 16));
    }

    function testShouldFindElementIfStopOffsetIsBetweenElements()
    {
        $html = <<<HTML
<!-- foo bar -->
Lorem ipsum dolor sit amet<br>
HTML;

        $parser = new SimpleHtmlParser($html);

        // find() can match elements after the stop offset in this case
        // this behavior is expected and documented
        $this->assertElement($parser->find(SimpleHtmlParser::OPENING_TAG, 'br', 28), [
            'name' => 'br',
            'start' => 43,
        ]);
    }

    function testShouldGetHtmlOfElement()
    {
        $html = <<<HTML
<!-- test link -->
<a href="http://example.com/">
<!-- the end -->
HTML;

        $parser = new SimpleHtmlParser($html);

        $element = $parser->find(SimpleHtmlParser::OPENING_TAG, 'a');

        $this->assertElement($element, ['name' => 'a']);
        $this->assertSame('<a href="http://example.com/">', $parser->getHtml($element));
    }

    function testShouldGetSlice()
    {
        $html = <<<HTML
<!-- foo -->
<span class="bar">hello</span>
HTML;

        $parser = new SimpleHtmlParser($html);

        $this->assertSame('<!-- foo -->', $parser->getSlice(0, 12));
        $this->assertSame('<span class="bar">hello</span>', $parser->getSlice(13, 43));
        $this->assertSame('', $parser->getSlice(-1, 1));
        $this->assertSame('', $parser->getSlice(1, -1));
        $this->assertSame('<!-- foo -->', $parser->getSlice(12, 0));
        $this->assertSame('', $parser->getSlice(100, 200));
    }

    function testFindShouldThrowExceptionIfTagNameIsSpecifiedForNonTagType()
    {
        $parser = new SimpleHtmlParser('');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('OPENING_TAG or CLOSING_TAG');

        $parser->find(SimpleHtmlParser::COMMENT, 'foo');
    }

    function testShouldGetSliceBetween()
    {
        $html = <<<HTML
<!-- foo -->
<span class="bar">hello</span>
HTML;

        $parser = new SimpleHtmlParser($html);

        $spanOpen = $parser->find(SimpleHtmlParser::OPENING_TAG, 'span');
        $spanClose = $parser->find(SimpleHtmlParser::CLOSING_TAG, 'span');

        $this->assertSame('hello', $parser->getSliceBetween($spanOpen, $spanClose));
        $this->assertSame('hello', $parser->getSliceBetween($spanClose, $spanOpen));
    }

    function testShouldIterate()
    {
        $html = <<<HTML
<!doctype html>
<!-- foo bar -->
<title>Lorem ipsum</title>
<script type="text/javascript">
    document.write("<h1>Lorem ipsum</h1>"); // should not be picked up as a real tag
</script>
<p <!-- invalid on purpose -->
    <a href="http://example.com">Click here</a>
</p>

Dolor sit amet
<script type="text/javascript"> // unclosed on purpose
HTML;

        $expected = [
            ['type' => SimpleHtmlParser::OTHER, 'start' => 0, 'end' => 15, 'symbol' => '!'],
            ['type' => SimpleHtmlParser::COMMENT, 'start' => 16, 'end' => 32],
            ['type' => SimpleHtmlParser::OPENING_TAG, 'start' => 33, 'end' => 40, 'name' => 'title', 'attrs' => []],
            ['type' => SimpleHtmlParser::CLOSING_TAG, 'start' => 51, 'end' => 59, 'name' => 'title'],
            ['type' => SimpleHtmlParser::OPENING_TAG, 'start' => 60, 'end' => 91, 'name' => 'script', 'attrs' => ['type' => 'text/javascript']],
            ['type' => SimpleHtmlParser::CLOSING_TAG, 'start' => 177, 'end' => 186, 'name' => 'script'],
            ['type' => SimpleHtmlParser::OPENING_TAG, 'start' => 187, 'end' => 217, 'name' => 'p', 'attrs' => [
                '<!--' => true,
                'invalid' => true,
                'on' =>  true,
                'purpose' => true,
                '--' => true,
            ]],
            ['type' => SimpleHtmlParser::OPENING_TAG, 'start' => 222, 'end' => 251, 'name' => 'a', 'attrs' => ['href' => 'http://example.com']],
            ['type' => SimpleHtmlParser::CLOSING_TAG, 'start' => 261, 'end' => 265, 'name' => 'a'],
            ['type' => SimpleHtmlParser::CLOSING_TAG, 'start' => 266, 'end' => 270, 'name' => 'p'],
            ['type' => SimpleHtmlParser::OPENING_TAG, 'start' => 287, 'end' => 318, 'name' => 'script'],
        ];

        $parser = new SimpleHtmlParser($html);

        $this->assertSame(0, $parser->getOffset());
        $this->assertSame(0, $parser->key());

        foreach ($parser as $index => $element) {
            $this->assertArrayHasKey($index, $expected, 'element index is out of bounds');
            try {
                $this->assertElement($element, $expected[$index]);
            } catch (\Exception $e) {
                throw new AssertionFailedError(sprintf('Failed to assert validity of element at index "%s"', $index), 0, $e);
            }
            $this->assertSame($element['end'], $parser->getOffset());
        }

        $this->assertFalse($parser->valid());
        $parser->next();
        $this->assertNull($parser->current());
    }

    function testShouldIterateEmpty()
    {
        $parser = new SimpleHtmlParser('No tags here, sorry.. :)');

        for ($i = 0; $i < 2; ++$i) {
            $this->assertSame(0, $parser->getOffset());
            $this->assertNull($parser->key());
            $this->assertNull($parser->current());
            $this->assertFalse($parser->valid());

            $parser->rewind();
        }
    }

    function testShouldManageStates()
    {
        $html = <<<HTML
<!doctype html>
<title>Lorem ipsum</title>
<h1>Dolor sit amet</h1>
HTML;

        $parser = new SimpleHtmlParser($html);

        // initial state
        $this->assertSame(0, $parser->getOffset());
        $this->assertSame(0, $parser->countStates());

        $parser->pushState();
        $this->assertSame(1, $parser->countStates());

        $parser->next();

        // doctype
        $this->assertSame(15, $parser->getOffset());
        $this->assertElement($parser->current(), [
            'type' => SimpleHtmlParser::OTHER,
            'symbol' => '!',
        ]);

        $parser->pushState();
        $this->assertSame(2, $parser->countStates());

        $parser->next();

        // <title>
        $this->assertSame(23, $parser->getOffset());
        $this->assertElement($parser->current(), [
            'type' => SimpleHtmlParser::OPENING_TAG,
            'name' => 'title',
        ]);

        $parser->pushState();
        $this->assertSame(3, $parser->countStates());

        $parser->next();

        // </title>
        $this->assertSame(42, $parser->getOffset());
        $this->assertElement($parser->current(), [
            'type' => SimpleHtmlParser::CLOSING_TAG,
            'name' => 'title',
        ]);

        $parser->pushState();
        $this->assertSame(4, $parser->countStates());

        $parser->find(SimpleHtmlParser::CLOSING_TAG, 'h1');

        // </h1>
        $this->assertSame(66, $parser->getOffset());

        $parser->revertState();
        $this->assertSame(3, $parser->countStates());

        // reverted back to </title>
        $this->assertSame(42, $parser->getOffset());
        $this->assertElement($parser->current(), [
            'type' => SimpleHtmlParser::CLOSING_TAG,
            'name' => 'title',
        ]);

        $parser->popState(); // pop state @ <title>
        $this->assertSame(2, $parser->countStates());
        $this->assertSame(42, $parser->getOffset()); // popping sould not affect offset

        $parser->revertState();
        $this->assertSame(1, $parser->countStates());

        // reverted to doctype
        $this->assertSame(15, $parser->getOffset());
        $this->assertElement($parser->current(), [
            'type' => SimpleHtmlParser::OTHER,
            'symbol' => '!',
        ]);

        $parser->revertState();
        $this->assertSame(0, $parser->countStates());

        // reverted to the beginning
        $this->assertSame(0, $parser->getOffset());
    }

    function testShouldClearStates()
    {
        $parser = new SimpleHtmlParser('');

        $this->assertSame(0, $parser->countStates());

        for ($i = 1; $i <= 3; ++$i) {
            $parser->pushState();
            $this->assertSame($i, $parser->countStates());
        }

        $parser->clearStates();

        $this->assertSame(0, $parser->countStates());
    }

    function testPopStateShouldThrowExceptionIfThereAreNoStates()
    {
        $parser = new SimpleHtmlParser('');

        $this->assertSame(0, $parser->countStates());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The state stack is empty');

        $parser->popState();
    }

    function testRevertStateShouldThrowExceptionIfThereAreNoStates()
    {
        $parser = new SimpleHtmlParser('');

        $this->assertSame(0, $parser->countStates());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The state stack is empty');

        $parser->revertState();
    }

    function testShouldEscape()
    {
        $parser = new SimpleHtmlParser('');

        $this->assertSame(
            '&lt;a href=&quot;http://example.com/?foo=bar&amp;amp;lorem=ipsum&quot;&gt;Test&lt;/a&gt;',
            $parser->escape('<a href="http://example.com/?foo=bar&amp;lorem=ipsum">Test</a>')
        );
    }

    function testShouldGetDoctype()
    {
        $html = <<<HTML
<!-- foo bar -->
<!DOCTYPE html>
HTML;

        $parser = new SimpleHtmlParser($html);

        $this->assertElement($parser->getDoctypeElement(), [
            'type' => SimpleHtmlParser::OTHER,
            'start' => 17,
            'end' => 32,
            'content' => 'DOCTYPE html',
        ]);
    }

    function testShouldReturnNullIfThereIsNoDoctype()
    {
        $html = <<<HTML
<!-- foo bar -->
<title>Hello</title>
HTML;

        $parser = new SimpleHtmlParser($html);

        $this->assertNull($parser->getDoctypeElement());
    }

    function testShouldUseDefaultFallbackEncoding()
    {
        $html = <<<HTML
<!doctype html>
<title>Foo bar</title>
HTML;

        $parser = new SimpleHtmlParser($html);

        $this->assertSame('utf-8', $parser->getEncoding());
        $this->assertNull($parser->getEncodingTag());
        $this->assertTrue($parser->usesFallbackEncoding());
    }

    function testShouldUseCustomFallbackEncoding()
    {
        $html = <<<HTML
<!doctype html>
<title>Foo bar</title>
HTML;

        $parser = new SimpleHtmlParser($html);

        $parser->setFallbackEncoding('ISO-8859-15');

        $this->assertSame('iso-8859-15', $parser->getEncoding());
        $this->assertNull($parser->getEncodingTag());
        $this->assertTrue($parser->usesFallbackEncoding());
    }

    function testShouldThrowExceptionOnUnsupportedFallbackEncoding()
    {
        $parser = new SimpleHtmlParser('');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported fallback encoding');

        $parser->setFallbackEncoding('unknown');
    }

    function testShouldDetectEncodingFromMetaCharset()
    {
        $html = <<<HTML
<!doctype html>
<META CharSet="WINDOWS-1251">
<title>Foo bar</title>
HTML;

        $parser = new SimpleHtmlParser($html);

        $this->assertSame('windows-1251', $parser->getEncoding());
        $this->assertElement($parser->getEncodingTag(), [
            'type' => SimpleHtmlParser::OPENING_TAG,
            'start' => 16,
            'end' => 45,
            'name' => 'meta',
            'attrs' => ['charset' => 'WINDOWS-1251'],
        ]);
        $this->assertFalse($parser->usesFallbackEncoding());
    }

    function testShouldDetectEncodingFromMetaHttpEquiv()
    {
        $html = <<<HTML
<!doctype html>
<META Http-Equiv="content-type" Content="text/html; charset=WINDOWS-1251">
<title>Foo bar</title>
HTML;

        $parser = new SimpleHtmlParser($html);

        $this->assertSame('windows-1251', $parser->getEncoding());
        $this->assertElement($parser->getEncodingTag(), [
            'type' => SimpleHtmlParser::OPENING_TAG,
            'start' => 16,
            'end' => 90,
            'name' => 'meta',
            'attrs' => ['http-equiv' => 'content-type', 'content' => 'text/html; charset=WINDOWS-1251'],
        ]);
        $this->assertFalse($parser->usesFallbackEncoding());
    }

    function testShouldNotAlterStateWhenDetectingEncoding()
    {
        $html = <<<HTML
<!doctype html>
<title>Foo bar</title>
HTML;

        $parser = new SimpleHtmlParser($html);

        $this->assertElement($parser->find(SimpleHtmlParser::OPENING_TAG, 'title'));
        $this->assertSame(23, $parser->getOffset());
        $this->assertSame(0, $parser->countStates());

        $this->assertSame('utf-8', $parser->getEncoding());
        $this->assertNull($parser->getEncodingTag());
        $this->assertTrue($parser->usesFallbackEncoding());

        $this->assertElement($parser->current());
        $this->assertSame(23, $parser->getOffset());
        $this->assertSame(0, $parser->countStates());
    }

    function testShouldDetectEncodingWhenGettingEncodingTag()
    {
        $html = <<<HTML
<!doctype html>
<meta charset="win-1251">
<title>Foo bar</title>
HTML;

        $parser = new SimpleHtmlParser($html);

        $this->assertElement($parser->getEncodingTag(), [
            'type' => SimpleHtmlParser::OPENING_TAG,
            'start' => 16,
            'end' => 41,
            'name' => 'meta',
            'attrs' => ['charset' => 'win-1251'],
        ]);
    }

    function testShouldNotUseFallbackEncodingIfEncodingIsSpecified()
    {
        $html = <<<HTML
<!doctype html>
<meta charset="win-1251">
<title>Foo bar</title>
HTML;

        $parser = new SimpleHtmlParser($html);

        $this->assertFalse($parser->usesFallbackEncoding());
    }

    private function assertElement($element, array $expectedKeys = []): void
    {
        $this->assertInternalType('array', $element);
        $this->assertArrayHasKey('type', $element);

        // check type and keys
        $keys = ['type', 'start', 'end'];

        switch ($element['type']) {
            case SimpleHtmlParser::COMMENT:
            case SimpleHtmlParser::INVALID:
                // no extra attributes
                break;
            case SimpleHtmlParser::OPENING_TAG:
                $keys[] = 'name';
                $keys[] = 'attrs';
                break;
            case SimpleHtmlParser::CLOSING_TAG:
                $keys[] = 'name';
                break;
            case SimpleHtmlParser::OTHER:
                $keys[] = 'symbol';
                break;
            default:
                $this->fail(sprintf('Failed asserting that "%s" is a valid element type', $element['type']));
        }

        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, $element);
        }

        $unknownKeys = array_diff(array_keys($element), $keys, array_keys($expectedKeys));

        if ($unknownKeys) {
            $this->fail(sprintf(
                'Failed asserting that element contains only known keys, found unknown key(s): %s',
                implode(', ', $unknownKeys)
            ));
        }

        // check expected keys
        foreach ($expectedKeys as $expectedKey => $expectedValue) {
            $this->assertArrayHasKey($expectedKey, $element);
            $this->assertSame($expectedValue, $element[$expectedKey]);
        }
    }
}
