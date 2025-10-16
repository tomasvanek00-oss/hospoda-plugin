<?php

namespace HospodaPlugin;

class Week_Pdf_Exporter {
    /**
     * Build PDF binary string for given week data structure.
     *
     * @param array{monday:string,labels:array<int,string>,dates:array<int,string>,days:array<int,array>,sides_map:array<int,string>} $week
     * @param array<string,mixed> $branding
     * @param array<string,mixed> $options
     */
    public function build(array $week, array $branding = [], array $options = []): string {
        $pdf = new Simple_Pdf();
        $startTs = strtotime($week['monday']);
        $endDate = end($week['dates']);
        $endTs = $endDate ? strtotime($endDate) : false;
        $rangeLabel = $this->formatRange($startTs ?: time(), $endTs ?: $startTs ?: time());
        $rangeHuman = $this->formatHumanRange($startTs ?: time(), $endTs ?: $startTs ?: time());
        $showSoupPrice = !empty($options['show_soup_price']);

        $pdf->set_title('Týdenní menu ' . $rangeLabel);

        $logoPath = is_string($branding['logo_path'] ?? '') ? trim($branding['logo_path']) : '';
        $topCustom = !empty($branding['_top_custom']);
        $bottomCustom = !empty($branding['_bottom_custom']);
        if ($logoPath !== '') {
            $pdf->add_image_with_side_text($rangeHuman, $logoPath, [
                'image_width' => 120.0,
                'spacing_after' => 9.0,
                'gap' => 16.0,
                'text' => ['font' => 'F2', 'size' => 14.0, 'align' => 'L', 'spacing_after' => 0.0],
            ]);
        } else {
            $pdf->add_text($rangeHuman, ['font' => 'F2', 'size' => 14.0, 'align' => 'C', 'spacing_after' => 10.0]);
        }

        $topLines = $this->extractLines($branding['top_text'] ?? '');
        $topStyles = $this->getTopLineStyles();
        foreach ($topLines as $index => $line) {
            $style = $this->resolveStyle($topStyles, $index);
            $pdf->add_text($line, $style);
        }
        if (empty($topLines) && !$topCustom) {
            $fallback = $this->getTopLineFallback();
            foreach ($fallback as $style) {
                $pdf->add_text($style['text'], $style['options']);
            }
        }

        foreach ($week['dates'] as $index => $date) {
            $label = $week['labels'][$index] ?? '';
            $heading = trim($label . ' ' . $this->formatDate($date));
            $pdf->add_text($heading, ['font' => 'F2', 'size' => 13.0, 'spacing_after' => 2.0]);

            $dayData = $week['days'][$index] ?? ['soup' => [], 'mains' => []];
            $soupLine = $this->formatSoupLine($dayData['soup'] ?? [], $showSoupPrice);
            $pdf->add_text($soupLine, ['indent' => 14.0, 'size' => 11.0, 'spacing_after' => 3.0]);

            $mains = $dayData['mains'] ?? [];
            if (!empty($mains)) {
                foreach ($mains as $position => $row) {
                    $line = $this->formatMainLine($position + 1, $row, $week['sides_map']);
                    $pdf->add_text($line, ['indent' => 20.0, 'size' => 11.0, 'spacing_after' => 2.0]);
                }
            } else {
                $pdf->add_text('Žádná hlavní jídla nejsou nastavena.', ['indent' => 20.0, 'size' => 11.0, 'spacing_after' => 2.0]);
            }

            $pdf->add_spacer(6.0);
        }

        $bottomLines = $this->extractLines($branding['bottom_text'] ?? '');
        $bottomStyles = $this->getBottomLineStyles();
        if (!empty($bottomLines)) {
            foreach ($bottomLines as $index => $line) {
                $style = $this->resolveStyle($bottomStyles, $index);
                $pdf->add_text($line, $style);
            }
        } elseif (!$bottomCustom) {
            $fallback = $this->getBottomLineFallback();
            foreach ($fallback as $style) {
                $pdf->add_text($style['text'], $style['options']);
            }
        }

        return $pdf->output();
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
        if (!is_array($value)) {
            return '';
        }
        $clean = array_values(array_filter(array_map('intval', $value)));
        if (empty($clean)) {
            return '';
        }
        return implode(', ', $clean);
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
            0 => ['font' => 'F2', 'size' => 22.0, 'align' => 'C', 'spacing_after' => 0.0],
            1 => ['size' => 12.0, 'align' => 'C', 'spacing_after' => 8.0],
            2 => ['font' => 'F2', 'size' => 16.0, 'align' => 'C', 'spacing_after' => 3.0],
            3 => ['size' => 10.0, 'align' => 'C', 'spacing_after' => 8.0],
            4 => ['size' => 10.0, 'align' => 'C', 'spacing_after' => 10.0],
            'default' => ['size' => 11.0, 'align' => 'C', 'spacing_after' => 6.0],
        ];
    }

    /**
     * @return array<int|string,array<string,mixed>>
     */
    private function getBottomLineStyles(): array {
        return [
            0 => ['size' => 9.0, 'spacing_after' => 3.0],
            1 => ['size' => 9.0, 'spacing_after' => 4.0],
            'default' => ['size' => 9.0, 'spacing_after' => 3.0],
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
            ['text' => 'HOSPODA POD KOSTELEM', 'options' => ['font' => 'F2', 'size' => 22.0, 'align' => 'C', 'spacing_after' => 0.0]],
            ['text' => 'Jarošov nad Nežárkou', 'options' => ['size' => 12.0, 'align' => 'C', 'spacing_after' => 8.0]],
            ['text' => 'Denní nabídka', 'options' => ['font' => 'F2', 'size' => 16.0, 'align' => 'C', 'spacing_after' => 3.0]],
            ['text' => 'K hlavnímu jídlu polévka za 20 Kč · Kola 0,3 l k menu za 15 Kč', 'options' => ['size' => 10.0, 'align' => 'C', 'spacing_after' => 8.0]],
            ['text' => 'Vaříme PO–PÁ od 10:30 do 14:00. Objednávky přijímáme den předem do 16:00 na telefonu hospody nebo osobně u obsluhy.', 'options' => ['size' => 10.0, 'align' => 'C', 'spacing_after' => 10.0]],
        ];
    }

    /**
     * @return array<int,array{text:string,options:array<string,mixed>}> 
     */
    private function getBottomLineFallback(): array {
        return [
            ['text' => 'Seznam alergenů je k nahlédnutí u obsluhy. Pro více informací se ptejte personálu.', 'options' => ['size' => 9.0, 'spacing_after' => 3.0]],
            ['text' => 'V nabídce mohou nastat drobné změny podle dostupnosti surovin. Děkujeme za pochopení.', 'options' => ['size' => 9.0, 'spacing_after' => 4.0]],
        ];
    }
}
