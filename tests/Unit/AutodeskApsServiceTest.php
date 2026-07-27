<?php

namespace Tests\Unit;

use App\Services\AutodeskApsService;
use Tests\TestCase;

class AutodeskApsServiceTest extends TestCase
{
    public function test_it_uses_svf2_streaming_for_the_us_region(): void
    {
        config()->set('services.autodesk_aps.region', 'US');
        config()->set('services.autodesk_aps.viewer_api', null);

        $this->assertSame('streamingV2', app(AutodeskApsService::class)->viewerApi());
    }

    public function test_it_uses_the_european_svf2_endpoint_for_the_emea_region(): void
    {
        config()->set('services.autodesk_aps.region', 'EMEA');
        config()->set('services.autodesk_aps.viewer_api', null);

        $this->assertSame('streamingV2_EU', app(AutodeskApsService::class)->viewerApi());
    }

    public function test_the_viewer_api_can_be_overridden_by_environment_configuration(): void
    {
        config()->set('services.autodesk_aps.region', 'US');
        config()->set('services.autodesk_aps.viewer_api', 'streamingV2_EU');

        $this->assertSame('streamingV2_EU', app(AutodeskApsService::class)->viewerApi());
    }
}
