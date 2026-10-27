<?php

namespace App\Support;

use Dompdf\FontMetrics;

class RncPdfHeaderFields
{
    public static function contractExtraHeight(string $value, FontMetrics $metrics, float $width = 96): float
    {
        return max(0, self::lines($value, $width, $metrics, $metrics->getFont('DejaVu Sans'), 6.8) - 1) * 9;
    }

    public static function boundedFields(array $fields, bool $showChoices = true): array
    {
        $result = [];
        foreach ($fields as $field) {
            if ($showChoices && $field['choices']) {
                $parts = array_map(fn ($choices) => ['choices' => $choices], array_chunk($field['choices'], 6));
            } else {
                // A table row cannot split over pages in Dompdf. Keep long answers in
                // bounded continuation rows while retaining every character.
                $parts = [];
                $buffer = '';
                foreach (preg_split('/(?<=\n)/u', str_replace(["\r\n", "\r"], "\n", (string) $field['value'])) as $line) {
                    foreach (mb_str_split($line, 800) as $piece) {
                        if ($buffer !== '' && (mb_strlen($buffer) + mb_strlen($piece) > 800 || substr_count($buffer, "\n") >= 20)) {
                            $parts[] = ['value' => $buffer];
                            $buffer = '';
                        }
                        $buffer .= $piece;
                    }
                }
                $parts[] = ['value' => $buffer];
            }
            foreach ($parts as $index => $part) $result[] = [...$field, ...$part, 'label' => $field['label'].($index ? ' (continuação)' : '')];
        }
        return $result;
    }

    public static function inspectionHeight(array $fields, FontMetrics $metrics): float
    {
        $font = $metrics->getFont('DejaVu Sans');
        $bold = $metrics->getFont('DejaVu Sans', 'bold');
        $height = 0;
        foreach (array_chunk($fields, 2) as $row) {
            $rowHeight = 0;
            foreach ($row as $field) {
                $labelHeight = self::lines($field['label'].':', 98, $metrics, $bold, 6.8) * 9.9;
                if ($field['choices']) {
                    $lines = 1;
                    $used = 0;
                    foreach ($field['choices'] as $choice) {
                        $width = $metrics->getTextWidth($choice['label'], $bold, 6.6) + 22;
                        if ($used && $used + $width > 151) { $lines++; $used = 0; }
                        $lines += max(0, self::lines($choice['label'], 129, $metrics, $bold, 6.6) - 1);
                        $used += min(151, $width);
                    }
                    $valueHeight = $lines * 14;
                } else {
                    $valueHeight = self::lines((string) $field['value'], 145, $metrics, $font, 6.8) * 9 + 6;
                }
                $rowHeight = max($rowHeight, $labelHeight, $valueHeight);
            }
            $height += max(20, $rowHeight + 5);
        }
        return ceil($height);
    }

    private static function lines(string $text, float $width, FontMetrics $metrics, string $font, float $size): int
    {
        $lines = 0;
        foreach (preg_split('/\R/u', $text) as $paragraph) {
            $lines++;
            $used = 0;
            foreach (preg_split('/(?<=\s)|(?=\s)/u', $paragraph, -1, PREG_SPLIT_NO_EMPTY) as $word) {
                $wordWidth = $metrics->getTextWidth($word, $font, $size);
                if ($used && $used + $wordWidth > $width) { $lines++; $used = 0; }
                if ($wordWidth > $width) {
                    $lines += (int) ceil($wordWidth / $width) - 1;
                    $used = fmod($wordWidth, $width);
                } else $used += $wordWidth;
            }
        }
        return max(1, $lines);
    }
}
