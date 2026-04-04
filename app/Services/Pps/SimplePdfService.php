<?php

namespace App\Services\Pps;

class SimplePdfService
{
    public function render(string $title, array $lines): string
    {
        $lines = array_values(array_filter([$title, ...$lines], fn ($line) => $line !== null));
        $pages = array_chunk($lines, 42);
        $objects = [];

        $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';

        $pageObjectIds = [];
        $nextObjectId = 3;

        foreach ($pages as $pageLines) {
            $pageObjectIds[] = $nextObjectId;
            $nextObjectId += 2;
        }

        $kids = implode(' ', array_map(fn ($id) => "{$id} 0 R", $pageObjectIds));
        $objects[] = "<< /Type /Pages /Count ".count($pageObjectIds)." /Kids [ {$kids} ] >>";

        foreach ($pages as $index => $pageLines) {
            $pageId = $pageObjectIds[$index];
            $contentId = $pageId + 1;
            $objects[$pageId - 1] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> >> >> /Contents {$contentId} 0 R >>";
            $objects[$contentId - 1] = $this->streamObject($pageLines);
        }

        $pdf = "%PDF-1.4\n";
        $offsets = [0];

        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n{$object}\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n";
        $pdf .= "0000000000 65535 f \n";

        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        $pdf .= "trailer << /Size ".(count($objects) + 1)." /Root 1 0 R >>\n";
        $pdf .= "startxref\n{$xrefOffset}\n%%EOF";

        return $pdf;
    }

    private function streamObject(array $lines): string
    {
        $y = 800;
        $commands = ['BT', '/F1 12 Tf'];

        foreach ($lines as $line) {
            $escaped = $this->escape((string) $line);
            $commands[] = "1 0 0 1 40 {$y} Tm ({$escaped}) Tj";
            $y -= 18;
        }

        $commands[] = 'ET';
        $stream = implode("\n", $commands);

        return "<< /Length ".strlen($stream)." >>\nstream\n{$stream}\nendstream";
    }

    private function escape(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\(', '\)'], preg_replace('/[^\x20-\x7E]/', '?', $text) ?? '');
    }
}
