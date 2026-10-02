<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\AppConfig;
use App\Support\ApiException;
use App\Support\Logger;

/**
 * Download history. Scoped to the anonymous client cookie by default so visitors of a shared
 * instance do not see each other's downloads (HISTORY_SCOPE=global disables this for single-user setups).
 */
final class HistoryService
{
    private const LIMIT = 100;

    public function __construct(
        private AppConfig $config,
        private JobService $jobs,
        private FileService $files,
        private Logger $logger,
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function list(?string $clientId): array
    {
        $items = [];
        foreach ($this->jobs->all() as $job) {
            if (!$this->visible($job, $clientId)) {
                continue;
            }
            $public = $this->jobs->toPublic($job);
            $public['file_available'] = $job['status'] === JobService::COMPLETED && $this->files->resolveJobFile($job) !== null;
            $items[] = $public;
            if (count($items) >= self::LIMIT) {
                break;
            }
        }
        return $items;
    }

    public function remove(string $id, ?string $clientId): void
    {
        $job = $this->jobs->get($id);
        if (!$this->visible($job, $clientId)) {
            throw new ApiException('JOB_NOT_FOUND', 'Download job not found.');
        }
        if (in_array($job['status'], [JobService::QUEUED, ...JobService::ACTIVE], true)) {
            throw new ApiException('JOB_ACTIVE', 'Cancel the download before removing it from the history.');
        }
        FileService::removeDir($this->files->jobDownloadDir($id));
        $this->jobs->delete($id);
        $this->logger->info('history entry removed', ['job_id' => $id, 'op' => 'history.delete', 'status' => 'ok']);
    }

    /** @param array<string,mixed> $job */
    private function visible(array $job, ?string $clientId): bool
    {
        if ($this->config->historyScope === 'global') {
            return true;
        }
        return $clientId !== null && $clientId !== '' && ($job['client_id'] ?? null) === $clientId;
    }
}
