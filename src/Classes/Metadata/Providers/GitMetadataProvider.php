<?php

namespace Cybex\Protector\Classes\Metadata\Providers;

use Cybex\Protector\Contracts\MetadataProvider;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class GitMetadataProvider implements MetadataProvider
{
    /**
     * @inheritDoc
     */
    public function getKey(): string
    {
        return 'git';
    }

    /**
     * @inheritDoc
     */
    public function shouldAppend(): bool
    {
        return $this->isUnderGitVersionControl();
    }

    /**
     * @inheritDoc
     */
    public function getMetadata(): array|string
    {
        return [
            'revision' => $this->getGitRevision(),
            'branch' => $this->getGitBranch(),
            'revisionDate' => $this->getGitHeadDate(),
        ];
    }

    protected function isUnderGitVersionControl(): bool
    {
        return File::exists(base_path('.git'));
    }

    protected function getGitRevision(): ?string
    {
        return $this->executeCommand('git rev-parse HEAD');
    }

    protected function getGitBranch(): ?string
    {
        return $this->executeCommand('git rev-parse --abbrev-ref HEAD');
    }

    protected function getGitHeadDate(): ?string
    {
        return $this->executeCommand('git show -s --format=%ci HEAD');
    }

    protected function executeCommand(string $command): ?string
    {
        // stdin, stdout, stderr.
        $descriptors = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];

        $process = proc_open($command, $descriptors, $pipes);

        $output = trim(stream_get_contents($pipes[1]));
        $error = trim(stream_get_contents($pipes[2]));

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        if ($error) {
            Log::warning(sprintf('%s::%s - Error "%s" when executing command "%s"', $this::class, __FUNCTION__, $error, $command));

            return null;
        }

        return $output;
    }
}
