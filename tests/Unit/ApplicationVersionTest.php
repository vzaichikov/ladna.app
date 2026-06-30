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

    public function test_revision_changes_when_build_manifest_changes(): void
    {
        $manifestPath = tempnam(sys_get_temp_dir(), 'ladna-manifest-');

        $this->assertIsString($manifestPath);

        file_put_contents($manifestPath, '{"resources/js/app.js":{"file":"assets/app-one.js"}}');
        $firstRevision = ApplicationVersion::revision($manifestPath);

        file_put_contents($manifestPath, '{"resources/js/app.js":{"file":"assets/app-two.js"}}');
        $secondRevision = ApplicationVersion::revision($manifestPath);

        @unlink($manifestPath);

        $this->assertStringStartsWith(ApplicationVersion::current().'+', $firstRevision);
        $this->assertStringStartsWith(ApplicationVersion::current().'+', $secondRevision);
        $this->assertNotSame($firstRevision, $secondRevision);
    }
}
