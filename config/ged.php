<?php

return [
    'ocr' => [
        'enabled' => (bool) env('GED_OCR_ENABLED', true),
        'queue' => env('GED_OCR_QUEUE', 'ged'),
        'language' => env('GED_OCR_LANGUAGE', 'por+eng'),
        'mode' => env('GED_OCR_MODE', 'skip'),
        'output_type' => env('GED_OCR_OUTPUT_TYPE', 'pdf'),
        'deskew' => (bool) env('GED_OCR_DESKEW', false),
        'rotate_pages' => (bool) env('GED_OCR_ROTATE_PAGES', false),
        'max_pages' => (int) env('GED_OCR_MAX_PAGES', 10),
        'timeout' => (int) env('GED_OCR_TIMEOUT', 600),
        'binaries' => [
            'ocrmypdf' => env('GED_OCR_OCRMYPDF_BIN', 'ocrmypdf'),
            'pdftotext' => env('GED_OCR_PDFTOTEXT_BIN', 'pdftotext'),
            'pdfinfo' => env('GED_OCR_PDFINFO_BIN', 'pdfinfo'),
            'pdftoppm' => env('GED_OCR_PDFTOPPM_BIN', 'pdftoppm'),
            'tesseract' => env('GED_OCR_TESSERACT_BIN', 'tesseract'),
        ],
        'tessdata_dir' => env('GED_OCR_TESSDATA_DIR'),
    ],
];
