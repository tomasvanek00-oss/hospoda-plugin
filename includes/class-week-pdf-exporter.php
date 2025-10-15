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
        $endTs = strtotime(end($week['dates']));
        $rangeLabel = $this->formatRange($startTs ?: time(), $endTs ?: $startTs ?: time());

        $pdf->set_title('Tydenni menu ' . $rangeLabel);
        $pdf->add_text('Hospoda – Týdenní menu', ['font' => 'F2', 'size' => 18.0, 'spacing_after' => 10.0]);
        $pdf->add_text('Týden: ' . $rangeLabel, ['size' => 12.0, 'spacing_after' => 12.0]);

        foreach ($week['dates'] as $index => $date) {
            $label = $week['labels'][$index] ?? '';
            $heading = trim($label . ' ' . $this->formatDate($date));
            $pdf->add_text($heading, ['font' => 'F2', 'size' => 14.0, 'spacing_after' => 4.0]);

            $dayData = $week['days'][$index] ?? ['soup' => [], 'mains' => []];
            $soupLine = $this->formatSoupLine($dayData['soup'] ?? []);
            $pdf->add_text($soupLine, ['indent' => 12.0, 'size' => 12.0, 'spacing_after' => 4.0]);

            $mains = $dayData['mains'] ?? [];
            if (!empty($mains)) {
                foreach ($mains as $row) {
                    $line = $this->formatMainLine($row, $week['sides_map']);
                    $pdf->add_text($line, ['indent' => 18.0, 'size' => 12.0, 'spacing_after' => 2.0]);
                }
            } else {
                $pdf->add_text('- žádná hlavní jídla', ['indent' => 18.0, 'size' => 12.0, 'spacing_after' => 2.0]);
            }

            $pdf->add_spacer(10.0);
        }

        $generated = 'Vygenerováno: ' . $this->formatDate(date('Y-m-d')); // today
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
        return $line;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<int,string> $sidesMap
     */
    private function formatMainLine(array $row, array $sidesMap): string {
        $title = trim((string)($row['title'] ?? ''));
        if ($title === '') {
            $title = 'Bez názvu';
        }
        $line = '- ' . $title;
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
        return $line;
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
