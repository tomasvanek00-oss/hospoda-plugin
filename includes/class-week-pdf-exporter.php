<?php

namespace HospodaPlugin;

class Week_Pdf_Exporter {
    /**
     * Build PDF binary string for given week data structure.
     *
     * @param array{monday:string,labels:array<int,string>,dates:array<int,string>,days:array<int,array>,sides_map:array<int,string>} $week
     * @param array<string,mixed> $branding
     * @param array{show_soup_price?:bool,static_menu?:array<int,array<string,mixed>>} $options
     */
    public function build(array $week, array $branding = [], array $options = []): string {
        $scales = [1.0, 0.95, 0.9, 0.86, 0.82, 0.78, 0.74, 0.7];
        $showSoupPrice = !empty($options['show_soup_price']);
        $staticMenu = $this->normalizeStaticMenuItems($options['static_menu'] ?? []);

        $lastPdf = null;
        foreach ($scales as $scale) {
            $pdf = $this->renderDocument($week, $branding, $showSoupPrice, $staticMenu, $scale);
            if ($pdf->get_page_count() <= 1) {
                return $pdf->output();
            }
            $lastPdf = $pdf;
        }

        return $lastPdf ? $lastPdf->output() : '';
    }

    /**
     * @param array{monday:string,labels:array<int,string>,dates:array<int,string>,days:array<int,array>,sides_map:array<int,string>} $week
     * @param array<int,array<string,mixed>> $staticMenu
     */
    private function renderDocument(array $week, array $branding, bool $showSoupPrice, array $staticMenu, float $scale): Simple_Pdf {
        $pdf = new Simple_Pdf();

        $startTs = strtotime($week['monday']);
        $endDate = end($week['dates']);
        $endTs = $endDate ? strtotime($endDate) : false;
        $rangeLabel = $this->formatRange($startTs ?: time(), $endTs ?: $startTs ?: time());
        $rangeHuman = $this->formatHumanRange($startTs ?: time(), $endTs ?: $startTs ?: time());

        $pdf->set_title('Týdenní menu ' . $rangeLabel);

        $logoPath = is_string($branding['logo_path'] ?? '') ? trim($branding['logo_path']) : '';
        $topCustom = !empty($branding['_top_custom']);
        $bottomCustom = !empty($branding['_bottom_custom']);

        if ($logoPath !== '') {
            $this->addScaledImageWithSideText($pdf, $rangeHuman, $logoPath, [
                'image_width' => 95.0,
                'spacing_after' => 6.5,
                'gap' => 12.0,
                'text' => ['font' => 'F2', 'size' => 12.5, 'align' => 'L', 'spacing_after' => 0.0],
            ], $scale);
        } else {
            $this->addScaledText($pdf, $rangeHuman, ['font' => 'F2', 'size' => 12.5, 'align' => 'C', 'spacing_after' => 6.5], $scale);
        }

        $topLines = $this->extractLines($branding['top_text'] ?? '');
        $topStyles = $this->getTopLineStyles();
        if (!empty($topLines)) {
            foreach ($topLines as $index => $line) {
                $style = $this->resolveStyle($topStyles, $index);
                $this->addScaledText($pdf, $line, $style, $scale);
            }
        } elseif (!$topCustom) {
            foreach ($this->getTopLineFallback() as $style) {
                $this->addScaledText($pdf, $style['text'], $style['options'], $scale);
            }
        }

        foreach ($week['dates'] as $index => $date) {
            $label = $week['labels'][$index] ?? '';
            $heading = trim($label . ' ' . $this->formatDate($date));
            $this->addScaledText($pdf, $heading, ['font' => 'F2', 'size' => 11.5, 'spacing_after' => 1.4], $scale);

            $dayData = $week['days'][$index] ?? ['soup' => [], 'mains' => []];
            $soupLine = $this->formatSoupLine($dayData['soup'] ?? [], $showSoupPrice);
            $this->addScaledText($pdf, $soupLine, ['indent' => 12.0, 'size' => 9.2, 'spacing_after' => 1.8], $scale);

            $mains = $dayData['mains'] ?? [];
            if (!empty($mains)) {
                foreach ($mains as $position => $row) {
                    $line = $this->formatMainLine($position + 1, $row, $week['sides_map']);
                    $this->addScaledText($pdf, $line, ['indent' => 18.0, 'size' => 9.2, 'spacing_after' => 1.3], $scale);
                }
            } else {
                $this->addScaledText($pdf, 'Žádná hlavní jídla nejsou nastavena.', ['indent' => 18.0, 'size' => 9.2, 'spacing_after' => 1.3], $scale);
            }

            $this->addScaledSpacer($pdf, 3.0, $scale);
        }

        if (!empty($staticMenu)) {
            $this->addScaledText($pdf, 'Stálá nabídka', ['font' => 'F2', 'size' => 10.8, 'spacing_after' => 1.8], $scale);
            foreach ($staticMenu as $item) {
                $line = $this->formatStaticLine($item, $week['sides_map']);
                $this->addScaledText($pdf, $line, ['indent' => 12.0, 'size' => 8.8, 'spacing_after' => 1.2], $scale);
            }
            $this->addScaledSpacer($pdf, 5.0, $scale);
        }

        $bottomLines = $this->extractLines($branding['bottom_text'] ?? '');
        $bottomStyles = $this->getBottomLineStyles();
        if (!empty($bottomLines)) {
            foreach ($bottomLines as $index => $line) {
                $style = $this->resolveStyle($bottomStyles, $index);
                $this->addScaledText($pdf, $line, $style, $scale);
            }
        } elseif (!$bottomCustom) {
            foreach ($this->getBottomLineFallback() as $style) {
                $this->addScaledText($pdf, $style['text'], $style['options'], $scale);
            }
        }

        return $pdf;
    }

    private function addScaledText(Simple_Pdf $pdf, string $text, array $options, float $scale): void {
        $options = $this->scaleOptions($options, $scale);
        $pdf->add_text($text, $options);
    }

    private function addScaledImageWithSideText(Simple_Pdf $pdf, string $text, string $path, array $options, float $scale): void {
        if (isset($options['image_width'])) {
            $options['image_width'] = (float)$options['image_width'] * $scale;
        }
        if (isset($options['gap'])) {
            $options['gap'] = (float)$options['gap'] * $scale;
        }
        if (isset($options['spacing_after'])) {
            $options['spacing_after'] = (float)$options['spacing_after'] * $scale;
        }
        if (isset($options['text']) && is_array($options['text'])) {
            $options['text'] = $this->scaleOptions($options['text'], $scale);
        }
        $pdf->add_image_with_side_text($text, $path, $options);
    }

    private function addScaledSpacer(Simple_Pdf $pdf, float $heightPt, float $scale): void {
        $pdf->add_spacer($heightPt * $scale);
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function scaleOptions(array $options, float $scale): array {
        if (isset($options['size'])) {
            $options['size'] = (float)$options['size'] * $scale;
        }
        if (isset($options['indent'])) {
            $options['indent'] = (float)$options['indent'] * $scale;
        }
        if (isset($options['spacing_after'])) {
            $options['spacing_after'] = (float)$options['spacing_after'] * $scale;
        }
        return $options;
    }

    /**
     * @param array<string,mixed> $soup
     */
    private function formatSoupLine(array $soup, bool $showPrice = true): string {
        $title = trim((string)($soup['title'] ?? ''));
        if ($title === '') {
            return 'Polévka: nenastaveno';
        }
        $line = 'Polévka: ' . $title;
        $price = trim((string)($soup['price'] ?? ''));
        if ($showPrice && $price !== '') {
            $line .= ' — ' . $price . ' Kč';
        }
        $allergens = $this->formatAllergens($soup['allergens'] ?? []);
        if ($allergens !== '') {
            $line .= ' (A: ' . $allergens . ')';
        }
        return $line;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<int,string> $sidesMap
     */
    private function formatMainLine(int $position, array $row, array $sidesMap): string {
        $title = trim((string)($row['title'] ?? ''));
        if ($title === '') {
            $title = 'Bez názvu';
        }
        $line = $position . ') ' . $title;
        $sides = [];
        if (!empty($row['sides']) && is_array($row['sides'])) {
            foreach ($row['sides'] as $termId) {
                $termId = (int)$termId;
                if ($termId && isset($sidesMap[$termId])) {
                    $sides[] = $sidesMap[$termId];
                }
            }
        }
        if (!empty($sides)) {
            $line .= ' (' . implode(', ', $sides) . ')';
        }
        $price = trim((string)($row['price'] ?? ''));
        if ($price !== '') {
            $line .= ' — ' . $price . ' Kč';
        }
        $allergens = $this->formatAllergens($row['allergens'] ?? []);
        if ($allergens !== '') {
            $line .= ' (A: ' . $allergens . ')';
        }
        return $line;
    }

    private function formatAllergens($value): string {
        $clean = $this->sanitizeAllergens($value);
        if (empty($clean)) {
            return '';
        }
        return implode(', ', $clean);
    }

    /**
     * @param mixed $value
     * @return array<int,int>
     */
    private function sanitizeAllergens($value): array {
        if (is_string($value)) {
            $value = preg_split('/[,\s]+/', $value) ?: [];
        }
        if (!is_array($value)) {
            return [];
        }

        $clean = [];
        foreach ($value as $item) {
            $code = (int)$item;
            if ($code > 0) {
                $clean[] = $code;
            }
        }

        $clean = array_values(array_unique($clean));
        sort($clean, SORT_NUMERIC);

        return $clean;
    }

    private function formatRange(int $startTs, int $endTs): string {
        return $this->formatTimestamp($startTs) . ' – ' . $this->formatTimestamp($endTs);
    }

    private function formatHumanRange(int $startTs, int $endTs): string {
        $startLabel = $this->formatHumanDate($startTs);
        $endLabel = $this->formatHumanDate($endTs);

        $startYear = (int)date('Y', $startTs);
        $endYear = (int)date('Y', $endTs);

        if ($startYear !== $endYear) {
            $startLabel .= ' ' . $startYear;
            $endLabel .= ' ' . $endYear;
        }

        return $startLabel . ' – ' . $endLabel;
    }

    private function formatTimestamp(int $ts): string {
        return date('d.m.Y', $ts);
    }

    private function formatDate(string $date): string {
        $ts = strtotime($date);
        if ($ts === false) {
            return $date;
        }
        return $this->formatTimestamp($ts);
    }

    private function formatHumanDate(int $ts): string {
        $day = (int)date('j', $ts);
        $month = (int)date('n', $ts);

        $months = [
            1 => 'ledna',
            2 => 'února',
            3 => 'března',
            4 => 'dubna',
            5 => 'května',
            6 => 'června',
            7 => 'července',
            8 => 'srpna',
            9 => 'září',
            10 => 'října',
            11 => 'listopadu',
            12 => 'prosince',
        ];

        $monthName = $months[$month] ?? date('n', $ts);

        return $day . '. ' . $monthName;
    }

    /**
     * @return array<int,string>
     */
    private function extractLines($value): array {
        if (!is_string($value)) {
            return [];
        }
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $lines = array_map('trim', explode("\n", $value));
        $lines = array_values(array_filter($lines, static function ($line) {
            return $line !== '';
        }));
        return $lines;
    }

    /**
     * @return array<int|string,array<string,mixed>>
     */
    private function getTopLineStyles(): array {
        return [
            0 => ['font' => 'F2', 'size' => 20.0, 'align' => 'C', 'spacing_after' => 0.0],
            1 => ['size' => 11.0, 'align' => 'C', 'spacing_after' => 6.0],
            2 => ['font' => 'F2', 'size' => 14.0, 'align' => 'C', 'spacing_after' => 2.0],
            3 => ['size' => 9.5, 'align' => 'C', 'spacing_after' => 6.0],
            4 => ['size' => 9.0, 'align' => 'C', 'spacing_after' => 7.0],
            'default' => ['size' => 10.0, 'align' => 'C', 'spacing_after' => 5.0],
        ];
    }

    /**
     * @return array<int|string,array<string,mixed>>
     */
    private function getBottomLineStyles(): array {
        return [
            0 => ['size' => 8.5, 'spacing_after' => 2.4],
            1 => ['size' => 8.5, 'spacing_after' => 3.0],
            'default' => ['size' => 8.5, 'spacing_after' => 2.4],
        ];
    }

    /**
     * @param array<int|string,array<string,mixed>> $styles
     * @return array<string,mixed>
     */
    private function resolveStyle(array $styles, int $index): array {
        if (isset($styles[$index])) {
            return $styles[$index];
        }
        return $styles['default'];
    }

    /**
     * @return array<int,array{text:string,options:array<string,mixed>}> 
     */
    private function getTopLineFallback(): array {
        return [
            ['text' => 'HOSPODA POD KOSTELEM', 'options' => ['font' => 'F2', 'size' => 20.0, 'align' => 'C', 'spacing_after' => 0.0]],
            ['text' => 'Jarošov nad Nežárkou', 'options' => ['size' => 11.0, 'align' => 'C', 'spacing_after' => 6.0]],
            ['text' => 'Denní nabídka', 'options' => ['font' => 'F2', 'size' => 14.0, 'align' => 'C', 'spacing_after' => 2.0]],
            ['text' => 'K hlavnímu jídlu polévka za 20 Kč · Kola 0,3 l k menu za 15 Kč', 'options' => ['size' => 9.5, 'align' => 'C', 'spacing_after' => 6.0]],
            ['text' => 'Vaříme PO–PÁ od 10:30 do 14:00. Objednávky přijímáme den předem do 16:00 na telefonu hospody nebo osobně u obsluhy.', 'options' => ['size' => 9.0, 'align' => 'C', 'spacing_after' => 7.0]],
        ];
    }

    /**
     * @return array<int,array{text:string,options:array<string,mixed>}> 
     */
    private function getBottomLineFallback(): array {
        return [
            ['text' => 'Seznam alergenů je k nahlédnutí u obsluhy. Pro více informací se ptejte personálu.', 'options' => ['size' => 8.5, 'spacing_after' => 2.4]],
            ['text' => 'V nabídce mohou nastat drobné změny podle dostupnosti surovin. Děkujeme za pochopení.', 'options' => ['size' => 8.5, 'spacing_after' => 3.0]],
        ];
    }

    /**
     * @param mixed $value
     * @return array<int,array<string,mixed>>
     */
    private function normalizeStaticMenuItems($value): array {
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $row) {
            if (!is_array($row)) {
                continue;
            }
            $title = trim((string)($row['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $price = trim((string)($row['price'] ?? ''));
            $sides = [];
            if (!empty($row['sides']) && is_array($row['sides'])) {
                foreach ($row['sides'] as $side) {
                    $sideId = (int)$side;
                    if ($sideId > 0) {
                        $sides[] = $sideId;
                    }
                }
            }
            $sides = array_values(array_unique($sides));

            $items[] = [
                'title' => $title,
                'price' => $price,
                'sides' => $sides,
                'allergens' => $this->sanitizeAllergens($row['allergens'] ?? []),
            ];
        }

        return $items;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<int,string> $sidesMap
     */
    private function formatStaticLine(array $row, array $sidesMap): string {
        $title = trim((string)($row['title'] ?? ''));
        if ($title === '') {
            $title = 'Bez názvu';
        }
        $line = '• ' . $title;

        $sides = [];
        if (!empty($row['sides']) && is_array($row['sides'])) {
            foreach ($row['sides'] as $termId) {
                $termId = (int)$termId;
                if ($termId && isset($sidesMap[$termId])) {
                    $sides[] = $sidesMap[$termId];
                }
            }
        }
        if (!empty($sides)) {
            $line .= ' (' . implode(', ', $sides) . ')';
        }

        $price = trim((string)($row['price'] ?? ''));
        if ($price !== '') {
            $line .= ' — ' . $price . ' Kč';
        }

        $allergens = $this->formatAllergens($row['allergens'] ?? []);
        if ($allergens !== '') {
            $line .= ' (A: ' . $allergens . ')';
        }

        return $line;
    }
}
