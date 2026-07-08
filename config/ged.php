<?php

return [
    'document_disk' => env('GED_DOCUMENT_DISK', 'public'),

    'ocr' => [
        'enabled' => (bool) env('GED_OCR_ENABLED', true),
        'queue' => env('GED_OCR_QUEUE', 'ged'),
        'language' => env('GED_OCR_LANGUAGE', 'por+eng'),
        'mode' => env('GED_OCR_MODE', 'skip'),
        'output_type' => env('GED_OCR_OUTPUT_TYPE', 'pdfa'),
        'deskew' => (bool) env('GED_OCR_DESKEW', true),
        'rotate_pages' => (bool) env('GED_OCR_ROTATE_PAGES', true),
        'max_pages' => (int) env('GED_OCR_MAX_PAGES', 25),
        'timeout' => (int) env('GED_OCR_TIMEOUT', 900),
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
