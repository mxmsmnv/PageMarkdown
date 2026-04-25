Page Markdown for ProcessWire
=============================

Page Markdown is a module for ProcessWire that enables users to export any page into a clean, well-structured Markdown file directly from the page editor.

The module automatically converts most ProcessWire field types — including complex ProFields and Repeater Matrix — into their corresponding Markdown equivalents.

Features
--------

*   Editor Button: Adds an "Export to Markdown" button to the page edit form as a secondary priority action.

*   Smart HTML Conversion: Converts CKEditor and TinyMCE content (tables, lists, headings, links, bold/italic) into standard Markdown syntax.

*   ProFields Support: Native handling for ProFields Table, Combo, and Repeater Matrix.

*   Flexible Configuration:

    *   Toggle field labels as headings.

    *   Define global ignore lists for specific fields or field types.

    *   Customizable datetime formatting.

    *   Automatic cleanup of empty HTML tags and non-breaking spaces.

Installation
------------

1.  Download or clone this repository into your `/site/modules/PageMarkdown/` directory.

2.  In your ProcessWire admin, navigate to Modules > Refresh.

3.  Locate Page Markdown and click Install.

Configuration
-------------

After installation, you can adjust the following settings:

*   Show Field Labels: If enabled, field labels will be rendered as `##` headings before the content.

*   Clean Empty HTML Tags: Automatically removes empty paragraphs and excessive whitespace.

*   Datetime Format: Specify the PHP `date()` format for datetime fields.

*   Ignored Fields/Types: Select technical or system fields that should be excluded from all exports.

Supported Field Types
---------------------

The module supports a wide range of standard and premium fields:

*   Text: Text, Textarea, CKEditor, TinyMCE (with full HTML-to-Markdown conversion).

*   Numbers: Integer, Float, Checkbox (Yes/No).

*   Assets: Pagefiles and Pageimages (rendered as Markdown link or image syntax).

*   References: Page Reference (single and multiple) rendered as links.

*   Complex Fields:

    *   Repeater & Repeater Matrix: Supports nested structures with type labels.

    *   ProFields Table: Generates clean Markdown tables with column labels.

    *   ProFields Combo: Generates labeled key-value lists with per-subfield type handling.

*   Special Fields: MapMarker (address/coordinates), Email (mailto links), URL, Color (hex codes).

Usage
-----

Open any page in the ProcessWire admin and click the "Export to Markdown" button located at the bottom of the form. The file will be generated and the download will start automatically.

Requirements
------------

*   ProcessWire 3.0+
*   PHP 8.2+

License
-------

MIT License.

* Author: Maxim Semenov
* GitHub: https://github.com/mxmsmnv/PageMarkdown