<?php

namespace Cybex\Protector\Tests\Feature;

use Cybex\Protector\Commands\CleanupStorage;
use Cybex\Protector\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class CleanupStorageTest extends TestCase
{
    #[Test]
    public function deletesAllValidFiles(): void
    {
        $this->assertContains('dump.sql', $this->storageDisk->files());

        $this->artisan(CleanupStorage::class);

        $this->assertEquals(['legacyDump.sql'], $this->storageDisk->files());
    }
}
