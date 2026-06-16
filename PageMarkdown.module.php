<?php namespace ProcessWire;

/**
 * PageMarkdown
 *
 * Export ProcessWire pages to Markdown format.
 * Supports: Text, Textarea, CKEditor, TinyMCE, Integer, Float, Checkbox, Datetime,
 * Email, URL, Color, MapMarker, SelectableOptions, PageArray, Page,
 * Pagefiles/Pageimages, Repeater, Repeater Matrix, ProFields Table, ProFields Combo.
 *
 * @author Maxim Semenov <maxim@smnv.org> (smnv.org)
 * @link https://github.com/mxmsmnv/PageMarkdown
 * @license MIT
 * @since 2026-03-21
 * @php 8.2+
 */

class PageMarkdown extends WireData implements Module, ConfigurableModule {

    public static function getModuleInfo(): array {
        return [
            'title'    => 'Page Markdown',
            'version'  => 114,
            'summary'  => 'Export any page to a clean Markdown file. Adds an export button to the page editor.',
            'author'   => 'Maxim Semenov',
            'href'     => 'https://smnv.org',
            'singular' => true,
            'autoload' => true,
            'icon'     => 'file-text-o',
        ];
    }

    protected static array $defaultConfig = [
        'showFieldLabels' => 1,
        'ignoredFields'   => [
            'pass', 'roles', 'permissions', 'settings', 'admin_theme',
        ],
        'ignoredTypes'    => [
            'FieldtypeFieldsetOpen', 'FieldtypeFieldsetTabOpen', 'FieldtypeFieldsetClose',
            'FieldtypeModule', 'FieldtypeSelector',
        ],
        'cleanEmptyTags'  => 1,
        'datetimeFormat'  => 'Y-m-d H:i',
    ];

    public function __construct() {
        parent::__construct();
        foreach(self::$defaultConfig as $key => $value) {
            $this->set($key, $value);
        }
    }

    protected function getIgnoredTypes(): array {
        $v = $this->ignoredTypes;
        return is_array($v) ? $v : (array) self::$defaultConfig['ignoredTypes'];
    }

    public function init(): void {
        $this->addHookAfter('ProcessPageEdit::buildForm', $this, 'addExportButton');

        if($this->wire('input')->get('export_markdown') && $this->wire('input')->get('id')) {
            $this->addHookBefore('ProcessPageEdit::execute', $this, 'handleExport');
        }
    }

    // -------------------------------------------------------------------------
    // Module config
    // -------------------------------------------------------------------------

    public static function getModuleConfigInputfields(array $data): InputfieldWrapper {
        $inputfields = new InputfieldWrapper();
        $data = array_merge(self::$defaultConfig, $data);

        $f = wire('modules')->get('InputfieldCheckbox');
        $f->name        = 'showFieldLabels';
        $f->label       = 'Show Field Labels';
        $f->description = 'Display field labels as headings in the exported Markdown.';
        $f->checked     = $data['showFieldLabels'] ? 'checked' : '';
        $f->columnWidth = 33;
        $inputfields->add($f);

        $f = wire('modules')->get('InputfieldCheckbox');
        $f->name        = 'cleanEmptyTags';
        $f->label       = 'Clean Empty HTML Tags';
        $f->description = 'Remove empty tags and non-breaking spaces from HTML output.';
        $f->checked     = $data['cleanEmptyTags'] ? 'checked' : '';
        $f->columnWidth = 33;
        $inputfields->add($f);

        $f = wire('modules')->get('InputfieldText');
        $f->name        = 'datetimeFormat';
        $f->label       = 'Datetime Format';
        $f->description = 'PHP date() format string for datetime fields.';
        $f->value       = $data['datetimeFormat'];
        $f->columnWidth = 34;
        $inputfields->add($f);

        $f = wire('modules')->get('InputfieldAsmSelect');
        $f->name        = 'ignoredFields';
        $f->label       = 'Globally Ignored Fields';
        $f->description = 'Fields that will never appear in the export.';
        foreach(wire('fields') as $field) {
            $f->addOption($field->name, $field->name . " ({$field->label})");
        }
        $f->value = $data['ignoredFields'] ?? self::$defaultConfig['ignoredFields'];
        $inputfields->add($f);

        $f = wire('modules')->get('InputfieldAsmSelect');
        $f->name        = 'ignoredTypes';
        $f->label       = 'Globally Ignored Field Types';
        $f->description = 'Field types that will never appear in the export (e.g. fieldsets, UI-only types).';
        $knownTypes = [];
        foreach(wire('fields') as $field) {
            $cls = $field->type->className();
            if(!isset($knownTypes[$cls])) $knownTypes[$cls] = $cls;
        }
        ksort($knownTypes);
        foreach($knownTypes as $cls) { $f->addOption($cls, $cls); }
        $f->value = $data['ignoredTypes'] ?? self::$defaultConfig['ignoredTypes'];
        $inputfields->add($f);

        return $inputfields;
    }

    // -------------------------------------------------------------------------
    // Button + download handler
    // -------------------------------------------------------------------------

    public function addExportButton(HookEvent $event): void {
        $form    = $event->return;
        $process = $event->object;
        $page    = $process->getPage();

        if(!$page || !$page->id || $page->template == 'admin') return;

        /** @var InputfieldButton $button */
        $button = $this->wire('modules')->get('InputfieldButton');
        $button->attr('id', 'btn_export_markdown');
        $button->value = 'Export to Markdown';
        $button->icon  = 'file-text-o';

        $exportUrl = "./?id={$page->id}&export_markdown=1";
        $button->attr('onclick', "window.location.href='{$exportUrl}'; return false;");
        $button->addClass('ui-priority-secondary');
        $button->style = 'margin-top: 20px;';

        $form->add($button);
    }

    public function handleExport(HookEvent $event): void {
        $id   = (int) $this->wire('input')->get('id');
        $page = $this->wire('pages')->get($id);

        if(!$page->id || !$page->editable()) {
            throw new WireException('Access denied.');
        }

        $content  = $this->generateMarkdown($page);
        $filename = $page->name . '-' . date('Y-m-d') . '.md';

        while(ob_get_level()) ob_end_clean();

        header('Content-Description: File Transfer');
        header('Content-Type: text/markdown; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . strlen($content));

        echo $content;
        exit;
    }

    // -------------------------------------------------------------------------
    // Markdown generation
    // -------------------------------------------------------------------------

    protected function generateMarkdown(Page $page): string {
        $pageId = $page->id;
        $this->wire('pages')->uncacheAll();
        $page = $this->wire('pages')->get("id=$pageId, include=all");
        $page->of(false);

        $md  = '# ' . $page->get('title|name') . "\n\n";
        $md .= "---\n";
        $md .= "ID: {$page->id}\n";
        $md .= "Template: {$page->template->name}\n";
        $md .= 'Export Date: ' . date('Y-m-d H:i') . "\n";
        $md .= "URL: {$page->httpUrl}\n";
        $md .= "---\n\n";

        $ignoredTypes = $this->getIgnoredTypes();

        foreach($page->template->fieldgroup as $field) {
            if(in_array($field->name, (array) $this->ignoredFields)) continue;
            if($field->name === 'title') continue;
            if($field->type && in_array($field->type->className(), $ignoredTypes)) continue;

            $value = $page->get($field->name);

            if($this->isNumericField($field)) {
                if(is_null($value) || $value === '') continue;
            } else {
                if($this->isEmpty($value)) continue;
            }

            $rendered = $this->renderFieldValue($value, $field, 0);
            if(!is_string($rendered) || trim($rendered) === '') continue;

            if($this->showFieldLabels) {
                $md .= '## ' . ($field->label ?: $field->name) . "\n\n";
            }
            $md .= $rendered . "\n\n---\n\n";
        }

        return $md;
    }

    // -------------------------------------------------------------------------
    // Field rendering — central dispatcher
    // -------------------------------------------------------------------------

    protected function renderFieldValue(mixed $value, ?Field $field, int $level): string {
        $type = $field?->type;

        // 1. REPEATER / REPEATER MATRIX
        if($value instanceof RepeaterPageArray) {
            return $this->renderRepeater($value, $level);
        }

        // 2. FILES / IMAGES
        if($value instanceof Pagefiles) {
            return $this->renderFiles($value);
        }

        // 3. PAGE REFERENCE
        if($type && str_contains($type->className(), 'FieldtypePage')) {
            if($value instanceof Page)      return $this->renderPage($value);
            if($value instanceof PageArray) return $this->renderPageArray($value);
            return '';
        }

        // 4. PAGE ARRAY
        if($value instanceof PageArray) {
            return $this->renderPageArray($value);
        }

        // 5. SINGLE PAGE
        if($value instanceof Page) {
            return $this->renderPage($value);
        }

        // 6. SELECTABLE OPTIONS
        if($value instanceof WireArray && $this->isOptionsField($field)) {
            return $this->renderSelectableOptions($value);
        }

        // 7. PROFIELDS COMBO — ComboValue has getSubfields(), detect by that
        if(is_object($value) && method_exists($value, 'getSubfields')) {
            return $this->renderCombo($value);
        }

        // 8. PROFIELDS TABLE
        if($this->isTableField($value, $field)) {
            return $this->renderTable($value);
        }

        // 9. MAP MARKER
        if(is_object($value) && str_contains(get_class($value), 'MapMarker')) {
            return $this->renderMapMarker($value);
        }

        // 10. DATETIME
        if($type && str_contains($type->className(), 'Datetime')) {
            return $this->renderDatetime($value);
        }

        // 11. CHECKBOX
        if($type && str_contains($type->className(), 'Checkbox')) {
            return $value ? 'Yes' : 'No';
        }

        // 12. INTEGER / FLOAT
        if($type && (str_contains($type->className(), 'Integer') || str_contains($type->className(), 'Float'))) {
            return (string) $value;
        }

        // 13. EMAIL
        if($type && str_contains($type->className(), 'Email') && is_string($value) && $value !== '') {
            return '[' . $value . '](mailto:' . $value . ')';
        }

        // 14. URL
        if($type && str_contains($type->className(), 'URL') && is_string($value) && $value !== '') {
            return '[' . $value . '](' . $value . ')';
        }

        // 15. COLOR
        if($type && str_contains($type->className(), 'Color')) {
            return '`#' . ltrim((string) $value, '#') . '`';
        }

        // 16. GENERIC STRING / HTML
        if(is_string($value)) {
            if($type && str_contains($type->className(), 'TinyMCE')) {
                return $this->processTinyMceContent($value);
            }
            return $this->processHtmlContent($value);
        }

        // 17. SCALAR FALLBACK
        if(is_scalar($value)) {
            return (string) $value;
        }

        // 18. WIRE ARRAY FALLBACK
        if($value instanceof WireArray) {
            $items = [];
            foreach($value as $item) {
                $s = is_scalar($item) ? (string) $item : $this->stringifyComplexValue($item);
                if($s !== '') $items[] = $s;
            }
            return implode(', ', $items);
        }

        return '';
    }

    // -------------------------------------------------------------------------
    // Type-specific renderers
    // -------------------------------------------------------------------------

    protected function renderRepeater(RepeaterPageArray $items, int $level): string {
        $prefix = str_repeat('#', min(6, $level + 3)) . ' ';
        $out    = '';

        foreach($items as $item) {
            $typeInfo = $this->getMatrixTypeLabel($item);
            $out .= $prefix . 'Item (ID:' . $item->id . ')' . $typeInfo . "\n\n";

            foreach($item->template->fieldgroup as $f) {
                if($f->name === 'repeater_matrix_type') continue;
                if(in_array($f->name, (array) $this->ignoredFields)) continue;

                $v = $item->get($f->name);

                if($this->isNumericField($f)) {
                    if(is_null($v) || $v === '') continue;
                } else {
                    if($this->isEmpty($v)) continue;
                }

                if($this->showFieldLabels) {
                    $out .= '**' . ($f->label ?: $f->name) . '**: ';
                }
                $out .= $this->renderFieldValue($v, $f, $level + 1) . "\n\n";
            }
        }

        return $out;
    }

    protected function getMatrixTypeLabel(Page $item): string {
        if(!isset($item->repeater_matrix_type)) return '';

        $typeId = (int) $item->repeater_matrix_type;
        if(!$typeId) return '';

        $matrixField = null;

        if(method_exists($item, 'getForField')) {
            $matrixField = $item->getForField();
        }

        if(!$matrixField) {
            $tplName = $item->template->name;
            if(str_starts_with($tplName, 'repeater_')) {
                $fieldName   = substr($tplName, strlen('repeater_'));
                $matrixField = $this->wire('fields')->get($fieldName);
            }
        }

        // getMatrixTypeLabel() is a method of the Field object (RepeaterMatrixField),
        // not of the Fieldtype. Signature: getMatrixTypeLabel(int $type, $language = null)
        if($matrixField && method_exists($matrixField, 'getMatrixTypeLabel')) {
            $label = $matrixField->getMatrixTypeLabel($typeId);
            if($label) return ' [' . $label . ']';
        }

        return ' [type:' . $typeId . ']';
    }

    protected function renderPage(Page $page): string {
        if(!$page->id) return '';
        $title = $page->get('title|name');
        $url   = $page->httpUrl ?: $page->url ?: '';
        return $url ? '[' . $title . '](' . $url . ')' : $title;
    }

    protected function renderFiles(Pagefiles $files): string {
        $assets = [];
        foreach($files as $file) {
            $url      = $file->httpUrl;
            $desc     = $file->description ?: $file->name;
            $assets[] = ($file instanceof Pageimage)
                ? "![{$desc}]({$url})"
                : "[File: {$desc}]({$url})";
        }
        if(empty($assets)) return '';
        return count($assets) > 1 ? "\n- " . implode("\n- ", $assets) : $assets[0];
    }

    protected function renderPageArray(PageArray $pages): string {
        $links = [];
        foreach($pages as $p) {
            $rendered = $this->renderPage($p);
            if($rendered !== '') $links[] = $rendered;
        }
        if(empty($links)) return '';
        return count($links) > 1 ? "- " . implode("\n- ", $links) : $links[0];
    }

    protected function renderSelectableOptions(WireArray $options): string {
        $labels = [];
        foreach($options as $opt) {
            $labels[] = method_exists($opt, 'title') ? $opt->title : (string) $opt;
        }
        return implode(', ', $labels);
    }

    protected function renderCombo(mixed $value): string {
        // ComboValue (extends WireData) — iterate via getSubfields(), not foreach
        $out = '';
        foreach($value->getSubfields() as $subfield) {
            $name = $subfield->name;
            $val  = $value->get($name);
            if($this->isEmpty($val)) continue;
            $label  = $subfield->label ?: $name;
            $valStr = $this->renderComboSubfieldValue($val, $subfield->type);
            if($valStr !== '') $out .= "- **{$label}**: {$valStr}\n";
        }
        return $out ? "\n" . $out : '';
    }

    protected function renderComboSubfieldValue(mixed $val, string $subfieldType): string {
        if($val instanceof Pagefiles)  return $this->renderFiles($val);
        if($val instanceof PageArray)  return $this->renderPageArray($val);
        if($val instanceof Page)       return $this->renderPage($val);

        if(is_array($val)) {
            return implode(', ', array_filter(array_map('strval', $val)));
        }

        if(is_string($val)) {
            if($subfieldType === 'CKEditor' || $subfieldType === 'TinyMCE') {
                return $this->processHtmlContent($val);
            }
            return trim($val);
        }

        if(is_bool($val))   return $val ? 'Yes' : 'No';
        if(is_scalar($val)) return (string) $val;
        return '';
    }

    protected function renderTable(mixed $value): string {
        // $value is TableRows — column definitions come from getColumns()
        if(count($value) === 0) return '';

        // Build column map: name => label
        $colDefs = [];
        if(method_exists($value, 'getColumns')) {
            foreach($value->getColumns() as $col) {
                $name = $col['name'] ?? '';
                if($name === '') continue;
                $colDefs[$name] = $col['label'] ?: ucfirst($name);
            }
        }

        // Fallback: derive from first row keys
        if(empty($colDefs)) {
            $firstRow = $value->first();
            if($firstRow) {
                $skip = ['id', 'pages_id', 'sort', 'rowId'];
                $data = method_exists($firstRow, 'getArray') ? $firstRow->getArray() : (array) $firstRow;
                foreach(array_keys($data) as $k) {
                    if(!in_array($k, $skip)) $colDefs[$k] = ucfirst($k);
                }
            }
        }

        if(empty($colDefs)) return '';

        // Build matrix
        $matrix = [];
        foreach($value as $row) {
            $rowClean = [];
            foreach(array_keys($colDefs) as $colName) {
                $v = method_exists($row, 'get') ? $row->get($colName) : '';
                $v = $this->stringifyComplexValue($v ?? '');
                $v = str_replace(["\r", "\n", "|"], [" ", " ", "\\|"], $v);
                $rowClean[$colName] = trim($v);
            }
            $matrix[] = $rowClean;
        }

        // Drop empty columns
        $usedCols = array_filter(array_keys($colDefs), function($col) use ($matrix) {
            foreach($matrix as $row) {
                if(($row[$col] ?? '') !== '') return true;
            }
            return false;
        });

        if(empty($usedCols)) return '';

        $headers = array_map(fn($col) => $colDefs[$col], $usedCols);
        $table   = "| " . implode(" | ", $headers) . " |\n";
        $table  .= "| " . str_repeat(" --- |", count($usedCols)) . "\n";
        foreach($matrix as $row) {
            $cells = array_map(fn($col) => $row[$col] ?? '', $usedCols);
            $table .= "| " . implode(" | ", $cells) . " |\n";
        }

        return "\n" . $table;
    }

    protected function renderMapMarker(mixed $marker): string {
        $parts = [];
        if(!empty($marker->address)) $parts[] = 'Address: ' . $marker->address;
        if(!empty($marker->lat))     $parts[] = 'Lat: ' . $marker->lat;
        if(!empty($marker->lng))     $parts[] = 'Lng: ' . $marker->lng;
        if(!empty($marker->zoom))    $parts[] = 'Zoom: ' . $marker->zoom;
        if(!empty($marker->status))  $parts[] = 'Status: ' . $marker->status;
        return implode(' | ', $parts);
    }

    protected function renderDatetime(mixed $value): string {
        if(!$value) return '';
        $fmt = $this->datetimeFormat ?: 'Y-m-d H:i';
        if(is_numeric($value)) return date($fmt, (int) $value);
        return (string) $value;
    }

    // -------------------------------------------------------------------------
    // HTML → Markdown converter
    // -------------------------------------------------------------------------

    protected function processTinyMceContent(string $html): string {
        // Resolve data-mce-src / data-mce-href to real attributes
        $html = preg_replace('/(<img[^>]+)data-mce-src="([^"]*)"([^>]*>)/i', '$1src="$2"$3', $html);
        $html = preg_replace('/(<a[^>]+)data-mce-href="([^"]*)"([^>]*>)/i',  '$1href="$2"$3', $html);
        // Strip remaining data-mce-* attributes and contenteditable
        $html = preg_replace('/\s+data-mce-[a-z\-]+=(?:"[^"]*"|\'[^\']*\')/i', '', $html);
        $html = preg_replace('/\s+contenteditable="[^"]*"/i', '', $html);
        // Strip mce-* CSS classes
        $html = preg_replace_callback('/class="([^"]*)"/i', function($m) {
            $classes = trim(preg_replace('/\bmce-\S+\s*/i', '', $m[1]));
            return $classes !== '' ? 'class="' . $classes . '"' : '';
        }, $html);
        return $this->processHtmlContent($html);
    }

    protected function processHtmlContent(string $html): string {
        if($this->cleanEmptyTags) {
            $html = str_replace(
                ['&nbsp;', '&thinsp;', '&mdash;', "\xc2\xa0"],
                [' ',       ' ',        '—',        ' '],
                $html
            );
            $html = preg_replace('/<p[^>]*>\s*(?:&nbsp;)?\s*<\/p>/i', '', $html);
        }

        // Tables
        $html = preg_replace_callback('/<table[^>]*>(.*?)<\/table>/is', function($m) {
            $rows = [];
            preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $m[1], $trMatches);
            foreach($trMatches[1] as $trContent) {
                preg_match_all('/<(td|th)[^>]*>(.*?)<\/\1>/is', $trContent, $tdMatches);
                $cells = array_map(fn($c) => trim(str_replace(["\r", "\n", "|"], [" ", " ", "\\|"], strip_tags($c))), $tdMatches[2]);
                if(!empty($cells)) $rows[] = $cells;
            }
            if(empty($rows)) return '';
            $md  = "\n| " . implode(" | ", $rows[0]) . " |\n";
            $md .= "| " . str_repeat(" --- |", count($rows[0])) . "\n";
            for($i = 1; $i < count($rows); $i++) {
                $md .= "| " . implode(" | ", $rows[$i]) . " |\n";
            }
            return $md . "\n";
        }, $html);

        // Lists
        $html = preg_replace_callback('/<(u|o)l[^>]*>(.*?)<\/\1l>/is', function($m) {
            $isOrdered = ($m[1] === 'o');
            $items     = preg_split('/<li[^>]*>/i', $m[2], -1, PREG_SPLIT_NO_EMPTY);
            $out = '';
            $i   = 1;
            foreach($items as $item) {
                $content = trim(strip_tags(str_replace('</li>', '', $item)));
                if($content) $out .= ($isOrdered ? ($i++) . '. ' : '- ') . $content . "\n";
            }
            return "\n" . $out . "\n";
        }, $html);

        // Headings
        $html = preg_replace_callback('/<h([1-6])[^>]*>(.*?)<\/h\1>/is', function($m) {
            return "\n" . str_repeat('#', (int) $m[1]) . ' ' . strip_tags($m[2]) . "\n";
        }, $html);

        // Inline formatting
        $rules = [
            '/<strong[^>]*>(.*?)<\/strong>/is'           => '**$1**',
            '/<b[^>]*>(.*?)<\/b>/is'                     => '**$1**',
            '/<em[^>]*>(.*?)<\/em>/is'                   => '*$1*',
            '/<i[^>]*>(.*?)<\/i>/is'                     => '*$1*',
            '/<p[^>]*>(.*?)<\/p>/is'                     => "$1\n\n",
            '/<br\s*\/?>/i'                              => "\n",
            '/<hr[^>]*>/i'                               => "\n---\n",
            '/<a[^>]*href="([^"]*)"[^>]*>(.*?)<\/a>/is' => '[$2]($1)',
            '/<blockquote[^>]*>(.*?)<\/blockquote>/is'   => "\n> $1\n",
            '/<code[^>]*>(.*?)<\/code>/is'               => '`$1`',
            '/<pre[^>]*>(.*?)<\/pre>/is'                 => "\n```\n$1\n```\n",
        ];

        foreach($rules as $pattern => $replacement) {
            $html = preg_replace($pattern, $replacement, $html);
        }

        return trim(strip_tags(html_entity_decode($html)));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    protected function stringifyComplexValue(mixed $val): string {
        if($val instanceof PageArray) {
            $items = [];
            foreach($val as $p) {
                $r = $this->renderPage($p);
                if($r !== '') $items[] = $r;
            }
            return implode(', ', $items);
        }

        if($val instanceof Page) {
            return $this->renderPage($val);
        }

        if($val instanceof Pagefiles) {
            $names = [];
            foreach($val as $f) { $names[] = $f->name; }
            return implode(', ', $names);
        }

        if(is_array($val) || $val instanceof WireArray) {
            $items = [];
            foreach($val as $k => $v) {
                if($this->isEmpty($v)) continue;
                $s = is_scalar($v) ? (string) $v : $this->stringifyComplexValue($v);
                if($s !== '') $items[] = is_numeric($k) ? $s : "{$k}: {$s}";
            }
            return implode(', ', $items);
        }

        if(is_string($val))  return trim(strip_tags(html_entity_decode($val)));
        if(is_bool($val))    return $val ? 'Yes' : 'No';
        if(is_scalar($val))  return (string) $val;

        return '';
    }

    protected function isEmpty(mixed $value): bool {
        if(is_null($value)) return true;
        if(is_string($value) && strlen(trim($value)) === 0) return true;
        if($value instanceof Page) return !$value->id;
        if($value instanceof WireArray && count($value) === 0) return true;
        if(is_array($value) && count($value) === 0) return true;
        if(is_object($value) && !($value instanceof WireArray) && method_exists($value, 'count') && $value->count() === 0) return true;
        return false;
    }

    protected function isNumericField(?Field $field): bool {
        if(!$field?->type) return false;
        $cls = $field->type->className();
        return str_contains($cls, 'Integer') || str_contains($cls, 'Float') || str_contains($cls, 'Checkbox');
    }

    protected function isOptionsField(?Field $field): bool {
        if(!$field?->type) return false;
        return str_contains($field->type->className(), 'Options');
    }

    protected function isTableField(mixed $value, ?Field $field = null): bool {
        if($field?->type && str_contains($field->type->className(), 'Table')) return true;
        if(!is_object($value)) return false;
        return str_contains(get_class($value), 'TableRows') || str_contains(get_class($value), 'FieldtypeTable');
    }
}