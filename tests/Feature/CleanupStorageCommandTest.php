<?php

namespace Cybex\Protector\Tests\Feature;

use Cybex\Protector\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class CleanupStorageCommandTest extends TestCase
{
    #[Test]
    public function deletesAllValidFiles(): void
    {
        $this->assertContains('dump.sql', $this->storageDisk->files());

        $this->artisan('protector:flush-storage');

        $this->assertEquals(['legacyDump.sql'], $this->storageDisk->files());
    }
}
