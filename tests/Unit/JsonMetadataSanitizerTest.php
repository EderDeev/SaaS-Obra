<?php

namespace Tests\Unit;

use App\Http\Controllers\Tenant\GedController;
use App\Support\JsonMetadataSanitizer;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class JsonMetadataSanitizerTest extends TestCase
{
    public function test_it_removes_null_characters_recursively(): void
    {
        $metadata = JsonMetadataSanitizer::sanitize([
            'pdf:Producer' => "Adobe\0 PDF\0 Library",
            'nested' => ["\0key" => "value\0"],
        ]);

        $this->assertSame('Adobe PDF Library', $metadata['pdf:Producer']);
        $this->assertSame('value', $metadata['nested']['key']);
        $this->assertStringNotContainsString('\\u0000', json_encode($metadata, JSON_THROW_ON_ERROR));
    }

    public function test_pdf_utf_16_strings_are_decoded_without_null_characters(): void
    {
        $method = new ReflectionMethod(GedController::class, 'decodePdfString');
        $value = $method->invoke(new GedController(), "\xFE\xFF\x00A\x00d\x00o\x00b\x00e");

        $this->assertSame('Adobe', $value);
        $this->assertStringNotContainsString("\0", $value);
    }
}
