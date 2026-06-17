<?php

namespace Cybex\Protector\Tests\Feature;

use Cybex\Protector\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class FlushStorageCommandTest extends TestCase
{
    #[Test]
    public function deletesAllValidFiles(): void
    {
        $this->assertContains('dump.sql', $this->storageDisk->files());

        $this->artisan('protector:flush-storage');

        $this->assertEquals(['legacyDump.sql'], $this->storageDisk->files());
    }
}
