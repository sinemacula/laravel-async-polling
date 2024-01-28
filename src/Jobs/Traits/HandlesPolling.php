<?php

namespace SineMacula\Jobs\Traits;

use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;

/**
 * Provides helper methods to assist in polling.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2024 Sine Macula Limited.
 */
trait HandlesPolling
{
    /** @var int Set the threshold time (in seconds) for stopping processing when nearing the max execution time */
    private const MAX_EXECUTION_TIME_THRESHOLD = 10;

    /** @var int Set the threshold limit (in percentage) for stopping processing when nearing the percentage limit */
    private const MEMORY_LIMIT_THRESHOLD = 30;

    /** @var int The default maximum lifetime of the poll */
    private const DEFAULT_LIFETIME = 600;

    /** @var int The default maximum allowed attempts of the poll */
    private const DEFAULT_MAX_ATTEMPTS = 200;

    /** @var int The default interval of which to repeat the poll */
    private const DEFAULT_INTERVAL = 3;

    /** @var bool The default indicator for whether job releasing is supported */
    private const SUPPORTS_JOB_RELEASE = true;

    /** @var float The start time of the poll */
    private readonly float $startTime;

    /** @var bool Indicate whether the polling job has expired */
    private bool $expired = false;

    /**
     * Handle the job.
     *
     * @return void
     */
    public function handle(): void
    {
        $this->startTime = microtime(true);

        $this->handlePoll();
    }

    /**
     * Get the id to uniquely identify the polling job.
     *
     * @return string
     */
    abstract protected function getPollingId(): string;

    /**
     * Get the maximum allowed number of attempts.
     *
     * @return int
     */
    protected function getMaxAttempts(): int
    {
        return self::DEFAULT_MAX_ATTEMPTS;
    }

    /**
     * Get the maximum lifetime of the poll.
     *
     * @return int
     */
    protected function getLifetime(): int
    {
        return self::DEFAULT_LIFETIME;
    }

    /**
     * Determine whether the polling job has resolved.
     *
     * @return bool
     */
    abstract protected function hasPollResolved(): bool;

    /**
     * Determine whether jobs can be released.
     *
     * @return bool
     */
    protected function canJobsBeReleased(): bool
    {
        return self::SUPPORTS_JOB_RELEASE;
    }

    /**
     * Get the interval of the poll.
     *
     * @return int
     */
    protected function getInterval(): int
    {
        return self::DEFAULT_INTERVAL;
    }

    /**
     * Resolve the polling job to be dispatched.
     *
     * @return \Illuminate\Contracts\Queue\ShouldQueue
     */
    abstract protected function resolvePollingJob(): ShouldQueue;

    /**
     * Resolve the poll.
     *
     * @return bool
     */
    abstract protected function resolvePoll(): bool;

    /**
     * Handle the poll.
     *
     * @return void
     */
    private function handlePoll(): void
    {
        if (!$this->shouldContinuePolling()) {

            // If the poll has exceeded the maximum number of attempts, or has
            // exceeded the maximum lifetime of the poll, then the poll is
            // considered to have failed, thus the polling is stopped
            if ($this->hasExceededMaxAttempts() || $this->hasExpired()) {
                return;
            }

            if (!$this->hasPollResolved()) {
                $this->dispatchNewPoll();
            }

            return;
        }

        $this->runPoll();
    }

    /**
     * Determine if the poll should continue.
     *
     * @return bool
     */
    private function shouldContinuePolling(): bool
    {
        return !$this->hasExceededMaxAttempts()
            && !$this->hasExpired()
            && !$this->isApproachingMaximumExecutionTime()
            && !$this->isApproachingMemoryLimit()
            && !$this->hasPollResolved();
    }

    /**
     * Determine if the polling job has exceeded the maximum attempts allowed.
     *
     * @return bool
     */
    private function hasExceededMaxAttempts(): bool
    {
        return $this->getAttempts() >= $this->getMaxAttempts();
    }

    /**
     * Get the number of polling attempts made.
     *
     * @return int
     */
    private function getAttempts(): int
    {
        return Cache::get($this->getCacheKey('attempts'), 0);
    }

    /**
     * Get the cache key for the job.
     *
     * @param  string  $property
     * @return string
     */
    private function getCacheKey(string $property): string
    {
        return Str::kebab((new ReflectionClass($this))->getShortName()) . ":{$this->getPollingId()}:{$property}";
    }

    /**
     * Determine if the polling job has expired.
     *
     * @return bool
     */
    private function hasExpired(): bool
    {
        return $this->expired = $this->expired ?: Date::now()->diffInSeconds($this->getStartedAt()) > $this->getLifetime();
    }

    /**
     * Get the started at timestamp of the original polling job.
     *
     * @return \Carbon\Carbon
     */
    private function getStartedAt(): Carbon
    {
        $cache_key = $this->getCacheKey('started-at');

        $started_at = Cache::has($cache_key) ? Cache::get($cache_key) : Date::now();

        if (!Cache::has($cache_key)) {
            Cache::put($cache_key, $started_at);
        }

        return $started_at;
    }

    /**
     * Determine if the job is approaching the maximum allowed execution time.
     *
     * @return bool
     */
    private function isApproachingMaximumExecutionTime(): bool
    {
        $current_execution_time = microtime(true) - $this->startTime;
        $max_execution_time     = ini_get('max_execution_time');

        if ($max_execution_time == 0) {
            return false;
        }

        $time_remaining = $max_execution_time - $current_execution_time;

        return $time_remaining < self::MAX_EXECUTION_TIME_THRESHOLD;
    }

    /**
     * Determine if the job is approaching the maximum allowed memory usage.
     *
     * @return bool
     */
    private function isApproachingMemoryLimit(): bool
    {
        $memory_limit = ini_get('memory_limit');

        if ($memory_limit == -1) {
            return false;
        }

        // Convert memory limit to bytes
        $memory_limit_bytes = $this->convertToBytes($memory_limit);

        // Calculate the memory usage threshold
        $threshold = $memory_limit_bytes * (self::MEMORY_LIMIT_THRESHOLD / 100);

        // Current memory usage
        $current_memory_usage = memory_get_usage();

        return ($memory_limit_bytes - $current_memory_usage) < $threshold;
    }

    /**
     * Convert memory limit string (like '128M') to bytes.
     *
     * @param  string  $value
     * @return int
     */
    private function convertToBytes(string $value): int
    {
        sscanf($value, '%u%c', $bytes, $size);

        if (isset($size)) {
            $bytes = $bytes * pow(1024, strpos(' KMG', strtoupper($size)));
        }

        return $bytes;
    }

    /**
     * Release the job back onto the queue with a delay.
     *
     * @return void
     */
    private function dispatchNewPoll(): void
    {
        // Given Elastic Beanstalk worker environments do not support the
        // `release` method, the method of releasing the job back onto the queue
        // is determined by the value set in the configuration.
        if ($this->canJobsBeReleased()) {
            $this->release($this->getInterval());
        } else {
            Bus::dispatch($this->resolvePollingJob()->delay($this->getInterval()));
        }
    }

    /**
     * Run the poll.
     *
     * @return void
     */
    private function runPoll(): void
    {
        if ($this->resolvePoll()) {
            return;
        }

        $this->incrementAttempts();

        sleep($this->getInterval());

        $this->handlePoll();
    }

    /**
     * Increment the number of polling attempts made.
     *
     * @return void
     */
    private function incrementAttempts(): void
    {
        Cache::put($this->getCacheKey('attempts'), $this->getAttempts() + 1);
    }
}
