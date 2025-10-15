<?php

namespace HospodaPlugin;

/**
 * Very small PDF helper tailored for weekly menu export.
 * Generates a basic PDF document with Helvetica fonts and text blocks.
 */
class Simple_Pdf {
    private const PAGE_WIDTH = 595.28;   // A4 width in points
    private const PAGE_HEIGHT = 841.89;  // A4 height in points
    private const MARGIN_LEFT = 48.0;
    private const MARGIN_RIGHT = 48.0;
    private const MARGIN_TOP = 54.0;
    private const MARGIN_BOTTOM = 54.0;

    /** @var array<int, array<int, array<string, float|string>>> */
    private $pages = [];
    /** @var array<int, array<string, float|string>> */
    private $currentOps = [];
    /** @var float */
    private $cursorY = 0.0;
    /** @var string|null */
    private $title = null;

    public function __construct(){
        $this->startNewPage(false);
    }

    /**
     * Set PDF title metadata (ASCII only).
     */
    public function set_title(string $title): void {
        $this->title = $this->cleanText($title);
    }

    /**
     * Add block of text with options.
     *
     * @param string $text
     * @param array{font?:string,size?:float,indent?:float,spacing_after?:float,max_chars?:int} $options
     */
    public function add_text(string $text, array $options = []): void {
        $font = $options['font'] ?? 'F1';
        $size = isset($options['size']) ? (float)$options['size'] : 12.0;
        $indent = isset($options['indent']) ? (float)$options['indent'] : 0.0;
        $spacingAfter = isset($options['spacing_after']) ? (float)$options['spacing_after'] : 6.0;

        $usableWidth = self::PAGE_WIDTH - self::MARGIN_LEFT - self::MARGIN_RIGHT - max(0.0, $indent);
        $approxCharWidth = max(1.0, $size * 0.55);
        $maxChars = $options['max_chars'] ?? max(12, (int)floor($usableWidth / $approxCharWidth));

        $paragraphs = preg_split("/\r?\n/", $text);
        if (!$paragraphs) {
            $paragraphs = [''];
        }

        foreach ($paragraphs as $paragraph) {
            $wrapped = $this->wrapText($paragraph, $maxChars);
            foreach ($wrapped as $line) {
                $this->ensureSpace($size * 1.6);
                $this->currentOps[] = [
                    'font' => $font,
                    'size' => $size,
                    'x'    => self::MARGIN_LEFT + $indent,
                    'y'    => $this->cursorY,
                    'text' => $this->cleanText($line),
                ];
                $this->cursorY -= $size * 1.6;
            }
            $this->cursorY -= max(0.0, $spacingAfter);
        }
    }

    /**
     * Add vertical spacer.
     */
    public function add_spacer(float $height): void {
        $this->ensureSpace($height);
        $this->cursorY -= $height;
    }

    /**
     * Render PDF binary contents.
     */
    public function output(): string {
        $this->finalizePage();
        if (empty($this->pages)) {
            $this->pages[] = [];
        }

        $objects = [];
        $objectId = 1;

        // Catalog placeholder (object 1) -> to be filled later with pages object id.
        $catalogId = $objectId++;
        $pagesId = $objectId++;

        $fontRegularId = $objectId++;
        $fontBoldId = $objectId++;

        $pageEntries = [];
        $contentEntries = [];

        foreach ($this->pages as $pageOps) {
            $pageId = $objectId++;
            $contentId = $objectId++;
            $pageEntries[] = ['pageId' => $pageId, 'contentId' => $contentId];
            $contentEntries[] = ['id' => $contentId, 'stream' => $this->buildContentStream($pageOps)];
        }

        $infoId = null;
        if ($this->title !== null && $this->title !== '') {
            $infoId = $objectId++;
        }

        // Build object contents
        $objects[$catalogId] = $this->buildCatalogObject($pagesId);
        $objects[$pagesId] = $this->buildPagesObject($pageEntries);
        $objects[$fontRegularId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[$fontBoldId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

        foreach ($pageEntries as $index => $entry) {
            $objects[$entry['pageId']] = $this->buildPageObject($pagesId, $entry['contentId'], $fontRegularId, $fontBoldId);
            $objects[$entry['contentId']] = $this->buildContentObject($contentEntries[$index]['stream']);
        }

        if ($infoId !== null) {
            $objects[$infoId] = '<< /Title (' . $this->escape($this->title) . ') >>';
        }

        ksort($objects);
        $pdf = "%PDF-1.4\n";
        $offsets = [0 => 0];

        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $body . "\nendobj\n";
        }

        $xrefPosition = strlen($pdf);
        $maxId = max(array_keys($objects));
        $pdf .= 'xref' . "\n";
        $pdf .= '0 ' . ($maxId + 1) . "\n";
        $pdf .= sprintf("%010d 65535 f \n", 0);
        for ($i = 1; $i <= $maxId; $i++) {
            $offset = $offsets[$i] ?? 0;
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        $pdf .= 'trailer << /Size ' . ($maxId + 1) . ' /Root ' . $catalogId . ' 0 R';
        if ($infoId !== null) {
            $pdf .= ' /Info ' . $infoId . ' 0 R';
        }
        $pdf .= " >>\n";
        $pdf .= 'startxref' . "\n" . $xrefPosition . "\n";
        $pdf .= '%%EOF';

        return $pdf;
    }

    private function buildCatalogObject(int $pagesId): string {
        return '<< /Type /Catalog /Pages ' . $pagesId . ' 0 R >>';
    }

    /**
     * @param array<int, array{pageId:int,contentId:int}> $pageEntries
     */
    private function buildPagesObject(array $pageEntries): string {
        $kids = [];
        foreach ($pageEntries as $entry) {
            $kids[] = $entry['pageId'] . ' 0 R';
        }
        $kidsStr = implode(' ', $kids);
        return '<< /Type /Pages /Count ' . count($pageEntries) . ' /Kids [' . $kidsStr . '] >>';
    }

    private function buildPageObject(int $pagesId, int $contentId, int $fontRegularId, int $fontBoldId): string {
        return '<< /Type /Page /Parent ' . $pagesId . ' 0 R /MediaBox [0 0 ' . self::PAGE_WIDTH . ' ' . self::PAGE_HEIGHT . '] /Resources << /Font << /F1 ' . $fontRegularId . ' 0 R /F2 ' . $fontBoldId . ' 0 R >> >> /Contents ' . $contentId . ' 0 R >>';
    }

    private function buildContentObject(string $stream): string {
        return '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
    }

    /**
     * @param array<int, array<string, float|string>> $ops
     */
    private function buildContentStream(array $ops): string {
        $out = '';
        foreach ($ops as $entry) {
            $font = (string)$entry['font'];
            $size = $this->formatNumber((float)$entry['size']);
            $x = $this->formatNumber((float)$entry['x']);
            $y = $this->formatNumber((float)$entry['y']);
            $text = $this->escape((string)$entry['text']);
            $out .= "BT\n";
            $out .= '/' . $font . ' ' . $size . " Tf\n";
            $out .= '1 0 0 1 ' . $x . ' ' . $y . " Tm\n";
            $out .= '(' . $text . ") Tj\n";
            $out .= "ET\n";
        }
        return $out;
    }

    /**
     * @return array<int, string>
     */
    private function wrapText(string $text, int $maxChars): array {
        $text = trim($text);
        if ($text === '') {
            return [''];
        }
        $wrapped = wordwrap($text, $maxChars, "\n", true);
        return explode("\n", $wrapped);
    }

    private function ensureSpace(float $needed): void {
        if ($this->cursorY - $needed < self::MARGIN_BOTTOM) {
            $this->startNewPage(true);
        }
    }

    private function startNewPage(bool $storePrevious): void {
        if ($storePrevious && !empty($this->currentOps)) {
            $this->pages[] = $this->currentOps;
        }
        $this->currentOps = [];
        $this->cursorY = self::PAGE_HEIGHT - self::MARGIN_TOP;
    }

    private function finalizePage(): void {
        if (!empty($this->currentOps)) {
            $this->pages[] = $this->currentOps;
            $this->currentOps = [];
        }
    }

    private function cleanText(string $text): string {
        $original = $text;
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if ($converted === false) {
            $converted = $this->fallbackTransliteration($original);
        }
        $converted = preg_replace('/[^\x20-\x7E\r\n\t]/', '', (string)$converted);
        return trim($converted);
    }

    private function escape(string $text): string {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }

    private function formatNumber(float $number): string {
        return rtrim(rtrim(sprintf('%.2f', $number), '0'), '.');
    }

    private function fallbackTransliteration(string $text): string {
        $normalized = preg_replace('/[^\p{L}\p{N}\s]/u', '', $text);
        $normalized = preg_replace('/\s+/u', ' ', (string)$normalized);
        $ascii = iconv('UTF-8', 'ASCII//IGNORE', (string)$normalized);
        if ($ascii === false) {
            return preg_replace('/[^A-Za-z0-9\s]/', '', (string)$normalized) ?? '';
        }
        return (string) $ascii;
    }
}
