<?php

namespace Tests\Unit;

use App\Support\ApplicationVersion;
use Tests\TestCase;

class ApplicationVersionTest extends TestCase
{
    public function test_current_version_matches_root_version_file(): void
    {
        $version = trim((string) file_get_contents(base_path('VERSION')));

        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $version);
        $this->assertSame($version, ApplicationVersion::current());
    }
}
