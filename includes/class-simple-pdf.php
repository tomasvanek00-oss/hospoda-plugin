<?php

namespace HospodaPlugin;

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/vendor/tfpdf.php';
require_once __DIR__ . '/vendor/unifont/ttfonts.php';
require_once __DIR__ . '/class-font-loader.php';

class Simple_Pdf {
    private const PAGE_WIDTH_MM = 210.0; // A4
    private const PAGE_HEIGHT_MM = 297.0;
    private const MARGIN_LEFT_PT = 48.0;
    private const MARGIN_RIGHT_PT = 48.0;
    private const MARGIN_TOP_PT = 54.0;
    private const MARGIN_BOTTOM_PT = 54.0;

    /** @var \tFPDF */
    private $pdf;
    /** @var float */
    private $marginLeftMm;
    /** @var float */
    private $marginRightMm;
    /** @var float */
    private $marginTopMm;
    /** @var float */
    private $marginBottomMm;

    public function __construct() {
        $this->marginLeftMm = $this->ptToMm(self::MARGIN_LEFT_PT);
        $this->marginRightMm = $this->ptToMm(self::MARGIN_RIGHT_PT);
        $this->marginTopMm = $this->ptToMm(self::MARGIN_TOP_PT);
        $this->marginBottomMm = $this->ptToMm(self::MARGIN_BOTTOM_PT);

        $this->pdf = new \tFPDF('P', 'mm', 'A4');
        $this->pdf->SetMargins($this->marginLeftMm, $this->marginTopMm, $this->marginRightMm);
        $this->pdf->SetAutoPageBreak(true, $this->marginBottomMm);
        $this->pdf->AddPage();

        $this->registerFonts();
    }

    public function set_title(string $title): void {
        $this->pdf->SetTitle($title, true);
    }

    /**
     * Add multi-line text block.
     *
     * @param array{font?:string,size?:float,indent?:float,spacing_after?:float,align?:string} $options
     */
    public function add_text(string $text, array $options = []): void {
        $text = $this->normalizeText($text);
        if ($text === '') {
            return;
        }

        $fontKey = $options['font'] ?? 'F1';
        $fontSize = isset($options['size']) ? (float)$options['size'] : 12.0;
        $indentPt = isset($options['indent']) ? (float)$options['indent'] : 0.0;
        $spacingAfterPt = isset($options['spacing_after']) ? (float)$options['spacing_after'] : 6.0;
        $align = strtoupper($options['align'] ?? 'L');
        if (!in_array($align, ['L', 'C', 'R', 'J'], true)) {
            $align = 'L';
        }

        $style = $fontKey === 'F2' ? 'B' : '';
        $this->pdf->SetFont($this->fontFamilyForKey($fontKey), $style, $fontSize);

        $indentMm = $this->ptToMm($indentPt);
        $spacingAfterMm = $this->ptToMm($spacingAfterPt);
        $lineHeightMm = $this->ptToMm($fontSize * 1.35);

        $availableWidthMm = self::PAGE_WIDTH_MM - $this->marginLeftMm - $this->marginRightMm - $indentMm;
        if ($availableWidthMm <= 0) {
            $availableWidthMm = self::PAGE_WIDTH_MM - $this->marginLeftMm - $this->marginRightMm;
        }

        $targetLeft = $this->marginLeftMm + $indentMm;
        if ($align === 'C' || $align === 'R') {
            $targetLeft = $this->marginLeftMm;
        }

        $this->pdf->SetLeftMargin($targetLeft);
        $this->pdf->SetRightMargin($this->marginRightMm);
        $this->pdf->SetX($targetLeft);
        $this->pdf->MultiCell($availableWidthMm, $lineHeightMm, $text, 0, $align);
        $this->pdf->SetLeftMargin($this->marginLeftMm);
        $this->pdf->SetRightMargin($this->marginRightMm);
        $this->pdf->SetX($this->marginLeftMm);

        if ($spacingAfterMm > 0) {
            $this->pdf->Ln($spacingAfterMm);
        }
    }

    public function add_spacer(float $heightPt): void {
        $heightMm = $this->ptToMm($heightPt);
        if ($heightMm > 0) {
            $this->pdf->Ln($heightMm);
        }
    }

    /**
     * @param array{width?:float,spacing_after?:float,align?:string,indent?:float} $options
     */
    public function add_image(string $path, array $options = []): void {
        if (!is_file($path)) {
            return;
        }

        $imageSize = @getimagesize($path);
        if (!$imageSize || empty($imageSize[0]) || empty($imageSize[1])) {
            return;
        }

        $targetWidthPt = isset($options['width']) ? (float)$options['width'] : 200.0;
        $spacingAfterPt = isset($options['spacing_after']) ? (float)$options['spacing_after'] : 12.0;
        $indentPt = isset($options['indent']) ? (float)$options['indent'] : 0.0;
        $align = strtolower($options['align'] ?? 'left');

        $targetWidthMm = $this->ptToMm($targetWidthPt);
        if ($targetWidthMm <= 0) {
            $targetWidthMm = 60.0;
        }
        $targetHeightMm = $targetWidthMm * ($imageSize[1] / $imageSize[0]);

        $spacingAfterMm = $this->ptToMm($spacingAfterPt);
        $indentMm = $this->ptToMm($indentPt);

        $maxY = self::PAGE_HEIGHT_MM - $this->marginBottomMm;
        if ($this->pdf->GetY() + $targetHeightMm > $maxY) {
            $this->pdf->AddPage();
        }

        $x = $this->marginLeftMm + $indentMm;
        if ($align === 'center') {
            $x = (self::PAGE_WIDTH_MM - $targetWidthMm) / 2;
        } elseif ($align === 'right') {
            $x = self::PAGE_WIDTH_MM - $this->marginRightMm - $targetWidthMm;
        }

        $this->pdf->Image($path, $x, $this->pdf->GetY(), $targetWidthMm);
        $this->pdf->Ln($targetHeightMm + $spacingAfterMm);
    }

    /**
     * Render a left-aligned text block with an image floated to the right on the same row.
     *
     * @param array{text?:array<string,mixed>,image_width?:float,gap?:float,spacing_after?:float} $options
     */
    public function add_image_with_side_text(string $text, string $path, array $options = []): void {
        $text = $this->normalizeText($text);
        $hasImage = is_file($path);

        if ($text === '' && !$hasImage) {
            return;
        }

        if (!$hasImage) {
            $textOptions = is_array($options['text'] ?? null) ? $options['text'] : [];
            $this->add_text($text, $textOptions);
            return;
        }

        $imageSize = @getimagesize($path);
        if (!$imageSize || empty($imageSize[0]) || empty($imageSize[1])) {
            $textOptions = is_array($options['text'] ?? null) ? $options['text'] : [];
            $this->add_text($text, $textOptions);
            return;
        }

        $textOptions = is_array($options['text'] ?? null) ? $options['text'] : [];

        $targetWidthPt = isset($options['image_width']) ? (float)$options['image_width'] : 200.0;
        $gapPt = isset($options['gap']) ? (float)$options['gap'] : 18.0;
        $spacingAfterPt = isset($options['spacing_after']) ? (float)$options['spacing_after'] : 12.0;

        $targetWidthMm = $this->ptToMm($targetWidthPt);
        if ($targetWidthMm <= 0) {
            $targetWidthMm = 60.0;
        }

        $targetHeightMm = $targetWidthMm * ($imageSize[1] / $imageSize[0]);
        $gapMm = $this->ptToMm($gapPt);
        $spacingAfterMm = $this->ptToMm($spacingAfterPt);

        $availableWidthMm = self::PAGE_WIDTH_MM - $this->marginLeftMm - $this->marginRightMm;
        $textWidthMm = $availableWidthMm - $targetWidthMm - $gapMm;

        if ($text === '' || $textWidthMm < 30.0) {
            if ($text !== '') {
                $this->add_text($text, $textOptions);
            }
            $this->add_image($path, ['width' => $targetWidthPt, 'spacing_after' => $spacingAfterPt, 'align' => 'center']);
            return;
        }

        $fontKey = $textOptions['font'] ?? 'F1';
        $fontSize = isset($textOptions['size']) ? (float)$textOptions['size'] : 12.0;
        $align = strtoupper($textOptions['align'] ?? 'L');
        if (!in_array($align, ['L', 'C', 'R', 'J'], true)) {
            $align = 'L';
        }

        $style = $fontKey === 'F2' ? 'B' : '';
        $this->pdf->SetFont($this->fontFamilyForKey($fontKey), $style, $fontSize);

        $lineHeightMm = $this->ptToMm($fontSize * 1.35);

        $startX = $this->marginLeftMm;
        $startY = $this->pdf->GetY();

        $this->pdf->SetLeftMargin($startX);
        $this->pdf->SetRightMargin($this->marginRightMm + $targetWidthMm + $gapMm);
        $this->pdf->SetXY($startX, $startY);
        $this->pdf->MultiCell($textWidthMm, $lineHeightMm, $text, 0, $align);
        $textBottomY = $this->pdf->GetY();

        $imageX = $this->marginLeftMm + $textWidthMm + $gapMm;
        $imageY = $startY;
        $this->pdf->Image($path, $imageX, $imageY, $targetWidthMm);
        $imageBottomY = $imageY + $targetHeightMm;

        $this->pdf->SetLeftMargin($this->marginLeftMm);
        $this->pdf->SetRightMargin($this->marginRightMm);
        $this->pdf->SetX($this->marginLeftMm);

        $bottomY = max($textBottomY, $imageBottomY);
        $this->pdf->SetY($bottomY);
        if ($spacingAfterMm > 0) {
            $this->pdf->Ln($spacingAfterMm);
        }
    }

    public function output(): string {
        return $this->pdf->Output('S');
    }

    private function registerFonts(): void {
        $fonts = Font_Loader::ensure_fonts();

        $this->pdf->AddFont('NotoSans', '', $fonts['regular'], true);
        $this->pdf->AddFont('NotoSans', 'B', $fonts['bold'], true);
    }

    private function fontFamilyForKey(string $fontKey): string {
        return 'NotoSans';
    }

    private function normalizeText(string $text): string {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text) ?? '';
        return trim($text);
    }

    private function ptToMm(float $pt): float {
        return $pt * 25.4 / 72.0;
    }
}
