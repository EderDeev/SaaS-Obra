<?php

namespace Tests\Unit;

use App\Models\ProjectDocument;
use PHPUnit\Framework\TestCase;

class ProjectEapTest extends TestCase
{
    public function test_revision_is_always_placed_at_the_end_of_the_project_eap(): void
    {
        $document = new ProjectDocument([
            'code' => '0252026-001-PAV-EP-PRJ-001',
        ]);

        $this->assertSame(
            '0252026-001-PAV-EP-PRJ-001-R04',
            $document->eap('r04'),
        );
    }

    public function test_existing_revision_suffix_is_replaced_instead_of_duplicated(): void
    {
        $document = new ProjectDocument([
            'code' => '0252026-001-PAV-EP-PRJ-001-R00',
        ]);

        $this->assertSame(
            '0252026-001-PAV-EP-PRJ-001-R01',
            $document->eap('R01'),
        );
    }
}
