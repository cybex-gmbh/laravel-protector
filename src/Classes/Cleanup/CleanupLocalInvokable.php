<?php

namespace Cybex\Protector\Classes\Cleanup;

use Cybex\Protector\Facades\DiskHelperFacade as DiskHelper;

class CleanupLocalInvokable
{
    public function __invoke(): bool
    {
        return DiskHelper::cleanupOldLocalFiles();
    }
}
