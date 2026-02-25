<?php

namespace HospodaPlugin;

if (!defined('ABSPATH')) {
    exit;
}

use HospodaPlugin\Fonts\Noto_Sans_Data;

require_once __DIR__ . '/fonts/noto-sans.php';

class Font_Loader {
    private const FONT_SUBDIR = 'hospoda-plugin/pdf-fonts/';

    /**
     * Ensure Unicode-capable fonts are available and return the filenames registered with tFPDF.
     *
     * @return array{regular:string,bold:string}
     */
    public static function ensure_fonts(): array {
        $uploadDir = \wp_upload_dir();
        if (!empty($uploadDir['error'])) {
            throw new \RuntimeException('Nelze inicializovat adresář pro PDF fonty: ' . $uploadDir['error']);
        }

        $baseDir = \trailingslashit($uploadDir['basedir']) . self::FONT_SUBDIR;
        $uniDir = $baseDir . 'unifont/';

        if (!\wp_mkdir_p($uniDir)) {
            throw new \RuntimeException('Nelze vytvořit adresář pro PDF fonty.');
        }

        self::write_font_if_missing($uniDir . 'NotoSans-Regular.ttf', Noto_Sans_Data::regular());
        self::write_font_if_missing($uniDir . 'NotoSans-Bold.ttf', Noto_Sans_Data::bold());

        if (!defined('FPDF_FONTPATH')) {
            define('FPDF_FONTPATH', $baseDir);
        }
        if (!defined('_SYSTEM_TTFONTS')) {
            define('_SYSTEM_TTFONTS', $uniDir);
        }

        return [
            'regular' => 'NotoSans-Regular.ttf',
            'bold' => 'NotoSans-Bold.ttf',
        ];
    }

    private static function write_font_if_missing(string $targetPath, string $base64): void {
        if (is_file($targetPath)) {
            return;
        }

        $decoded = base64_decode($base64, true);
        if ($decoded === false) {
            throw new \RuntimeException('Nelze dekódovat data fontu pro PDF export.');
        }

        if (file_put_contents($targetPath, $decoded) === false) {
            throw new \RuntimeException('Nelze uložit font pro PDF export.');
        }
    }
}
