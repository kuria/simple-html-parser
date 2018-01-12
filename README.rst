Simple HTML parser
##################

Minimalistic HTML parser.

.. NOTE::

   If you need advanced DOM manipulation, consider using ``kuria/dom`` instead.

.. contents::


Features
********

- parsing opening tags
- parsing closing tags
- parsing comments
- parsing DTDs
- extracting parts of HTML content
- determining encoding of HTML documents
- handling "raw text" tags (``<style>``, ``<script>``, ``<noscript>``, etc.)


Requirements
************

- PHP 7.1+


Usage
*****

Creating the parser
===================

.. code:: php

   <?php

   use Kuria\SimpleHtmlParser\SimpleHtmlParser;

   $parser = new SimpleHtmlParser($html);


Iterating elements
==================

The parser implements ``Iterator`` so it can be traversed using the standard
iterator methods.

.. code:: php

   <?php

   foreach ($parser as $element) {
       print_r($element);
   }

.. code:: php

   <?php

   $parser->rewind();

   if ($parser->valid()) {
       print_r($parser->current());
   }


Element types
=============

- ``SimpleHtmlParser::COMMENT`` - a comment, e.g. ``<!-- foo -->``
- ``SimpleHtmlParser::OPENING_TAG`` - an opening tag, e.g. ``<span class="bar">``
- ``SimpleHtmlParser::CLOSING_TAG`` - a closing tag, e.g. ``</span>``
- ``SimpleHtmlParser::OTHER`` - special element, e.g. doctype, XML header
- ``SimpleHtmlParser::INVALID`` - invalid or incomplete tags


Tag name and attribute normalization
====================================

Tag and attribute names that contain only ASCII characters are lowercased.


Managing parser state
=====================

The state methods can be used to temporarily store and/or revert state of the
parser.

- ``pushState()`` - push current state of the parser onto the stack
- ``popState()`` - pop (discard) state stored on top of the stack
- ``revertState()`` - pop and restore state stored on top of the stack
- ``countStates()`` - count the number of states currently on the stack
- ``clearStates()`` - discard all states


``getHtml()`` - get HTML content
================================

The ``getHtml()`` method may be used to get the entire HTML content or HTML
of a single element.

.. code:: php

   <?php

   $parser->getHtml(); // get entire document
   $parser->getHtml($element); // get single element


``getSlice()`` - get part of the HTML
=====================================

The ``getSlice()`` method returns a part of the HTML content.

Returns an empty string for negative or out-of-bounds ranges.

.. code:: php

   <?php

   $slice = $parser->getSlice(100, 200);


``getSliceBetween()`` - get content between 2 elements
======================================================

The ``getSliceBetween()`` method returns a part of the HTML content that is between
2 elements (usually opening and closing tag).

.. code:: php

   <?php

   $slice = $parser->getSliceBetween($openingTag, $closingTag);


``getLength()`` - get length of the HTML
========================================

The ``getLength()`` returns total length of the HTML content.


``getEncoding()`` - determine encoding of the HTML document
===========================================================

The ``getEncoding()`` method attempts to determine encoding of the HTML document.

If the encoding cannot be determined or is not supported, the fallback encoding
will be used instead.

This method does not alter the parser's state.


``getEncodingTag()`` - find the encoding-specifying meta tag
============================================================

The ``getEncodingTag()`` method attempts to find the ``<meta charset="...">``
or ``<meta http-equiv="Content-Type" content="...">`` tag in the first 1024
bytes of the HTML document.

Returns ``NULL`` if the tag was not found.

This method does not alter the parser's state.


``usesFallbackEncoding()`` - see if the fallback encoding is being used
=======================================================================

The ``usesFallbackEncoding()`` indicates whether the fallback encoding
is being used. This is the case when the encoding is not specified or
is not supported.

This method does not alter the parser's state.


``setFallbackEncoding()`` - set fallback encoding
=================================================

The ``setFallbackEncoding()`` method specifies an encoding to be used in case
the document has no encoding specified or specifies an unsupported encoding.

The fallback encoding must be supported by ``htmlspecialchars()``.


``getDoctypeElement()`` - find the doctype element
==================================================

The ``getDoctypeElement()`` method attempts to find the doctype in the first 1024
bytes of the HTML document.

Returns ``NULL`` if no doctype was found.


``escape()`` - escape a string
==============================

The ``escape()`` method escapes a string using ``htmlspecialchars()`` using
the HTML document's encoding.


``find()`` - match a specific element
=====================================

The ``find()`` method attempts to find a specific element starting from the
current position, optionally stopping after a given number of bytes.

Returns ``NULL`` if no element was matched.

.. code:: php

   <?php

   $element = $parser->find(SimpleHtmlParser::OPENING_TAG, 'title');


``getOffset()`` - get current offset
====================================

The ``getOffset()`` method returns the current parser offset in bytes.


Example: Reading document's title
*********************************

.. code:: php

   <?php

   $html = <<<HTML
   <!doctype html>
   <meta charset="utf-8">
   <title>Foo bar</title>
   <h1>Baz qux</h1>
   HTML;

   $parser = new SimpleHtmlParser($html);

   $titleOpen = $parser->find(SimpleHtmlParser::OPENING_TAG, 'title');

   if ($titleOpen) {
       $titleClose = $parser->find(SimpleHtmlParser::CLOSING_TAG, 'title');

       if ($titleClose) {
           $title = $parser->getSliceBetween($titleOpen, $titleClose);

           var_dump($title);
       }
   }

Output:

::

  string(7) "Foo bar"
