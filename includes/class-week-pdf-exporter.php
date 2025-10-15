<?php

namespace HospodaPlugin;

class Week_Pdf_Exporter {
    /**
     * Build PDF binary string for given week data structure.
     *
     * @param array{monday:string,labels:array<int,string>,dates:array<int,string>,days:array<int,array>,sides_map:array<int,string>} $week
     */
    public function build(array $week): string {
        $pdf = new Simple_Pdf();
        $startTs = strtotime($week['monday']);
        $endDate = end($week['dates']);
        $endTs = $endDate ? strtotime($endDate) : false;
        $rangeLabel = $this->formatRange($startTs ?: time(), $endTs ?: $startTs ?: time());

        $pdf->set_title('Týdenní menu ' . $rangeLabel);

        $pdf->add_text('HOSPODA POD KOSTELEM', ['font' => 'F2', 'size' => 26.0, 'align' => 'C', 'spacing_after' => 0.0]);
        $pdf->add_text('Jarošov nad Nežárkou', ['size' => 13.0, 'align' => 'C', 'spacing_after' => 12.0]);
        $pdf->add_text('Denní nabídka', ['font' => 'F2', 'size' => 18.0, 'align' => 'C', 'spacing_after' => 4.0]);
        $pdf->add_text('K hlavnímu jídlu polévka za 20 Kč · Kola 0,3 l k menu za 15 Kč', ['size' => 11.0, 'align' => 'C', 'spacing_after' => 10.0]);
        $pdf->add_text('Vaříme PO–PÁ od 10:30 do 14:00. Objednávky přijímáme den předem do 16:00 na telefonu hospody nebo osobně u obsluhy.', ['size' => 11.0, 'align' => 'C', 'spacing_after' => 14.0]);

        foreach ($week['dates'] as $index => $date) {
            $label = $week['labels'][$index] ?? '';
            $heading = trim($label . ' ' . $this->formatDate($date));
            $pdf->add_text($heading, ['font' => 'F2', 'size' => 15.0, 'spacing_after' => 3.0]);

            $dayData = $week['days'][$index] ?? ['soup' => [], 'mains' => []];
            $soupLine = $this->formatSoupLine($dayData['soup'] ?? []);
            $pdf->add_text($soupLine, ['indent' => 14.0, 'size' => 12.0, 'spacing_after' => 4.0]);

            $mains = $dayData['mains'] ?? [];
            if (!empty($mains)) {
                foreach ($mains as $position => $row) {
                    $line = $this->formatMainLine($position + 1, $row, $week['sides_map']);
                    $pdf->add_text($line, ['indent' => 20.0, 'size' => 12.0, 'spacing_after' => 3.0]);
                }
            } else {
                $pdf->add_text('Žádná hlavní jídla nejsou nastavena.', ['indent' => 20.0, 'size' => 12.0, 'spacing_after' => 3.0]);
            }

            $pdf->add_spacer(10.0);
        }

        $pdf->add_text('Seznam alergenů je k nahlédnutí u obsluhy. Pro více informací se ptejte personálu.', ['size' => 10.0, 'spacing_after' => 4.0]);
        $pdf->add_text('V nabídce mohou nastat drobné změny podle dostupnosti surovin. Děkujeme za pochopení.', ['size' => 10.0, 'spacing_after' => 6.0]);

        $generated = 'Vygenerováno: ' . $this->formatDate(date('Y-m-d'));
        $pdf->add_text($generated, ['size' => 10.0, 'spacing_after' => 0.0]);

        return $pdf->output();
    }

    /**
     * @param array<string,mixed> $soup
     */
    private function formatSoupLine(array $soup): string {
        $title = trim((string)($soup['title'] ?? ''));
        if ($title === '') {
            return 'Polévka: nenastaveno';
        }
        $line = 'Polévka: ' . $title;
        $price = trim((string)($soup['price'] ?? ''));
        if ($price !== '') {
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
}
