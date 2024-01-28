# Laravel Async Polling

[![Build Status](https://github.com/sinemacula/laravel-async-polling/actions/workflows/tests.yml/badge.svg?branch=master)](https://github.com/sinemacula/laravel-async-polling/actions/workflows/tests.yml)
[![StyleCI](https://github.styleci.io/repos/749035570/shield?style=flat&branch=master)](https://github.styleci.io/repos/749035570)
[![Maintainability](https://api.codeclimate.com/v1/badges/a6fec0d28fcb8901ee35/maintainability)](https://codeclimate.com/repos/65b4f3033f0d085ad7ff02c2/maintainability)
[![Test Coverage](https://api.codeclimate.com/v1/badges/a6fec0d28fcb8901ee35/test_coverage)](https://codeclimate.com/repos/65b4f3033f0d085ad7ff02c2/test_coverage)

This repository provides a simple polling trait, designed to extend Laravel's queued jobs with asynchronous polling
capabilities. It's a straightforward solution for jobs that require repeated checks or waiting for specific conditions
before proceeding.

## Features

- **Effortless Integration**: Add polling functionality to any Laravel job with just one trait.
- **Customizable Polling Logic**: Implement your own polling logic with abstract methods.
- **Resource Efficient**: Smart handling of execution time and memory limits.

## Installation

Install the package via Composer:

```bash
composer require sinemacula/laravel-async-polling
```

## Usage

1. **Use the Trait in Your Job Class**: Import and use HandlesPolling in your Laravel job.

```php
use SineMacula\Jobs\Traits\HandlesPolling;

class ExampleJob implements ShouldQueue
{
    use HandlesPolling;

    // Implement required abstract methods (see Step 2)
}
```

2. **Implement Abstract Methods**: Provide implementations for abstract methods defined in the trait to tailor the
   polling process.

```php
    /**
     * Get the id to uniquely identify the polling job.
     *
     * @return string
     */
    protected function getPollingId(): string
    {
        // Return a unique identifier for the polling job
        
        // Example:
        return $this->uniqueId();
    }

    /**
     * Determine whether the polling job has resolved.
     *
     * @return bool
     */
    protected function hasPollResolved(): bool
    {
        // Return true if the polling job has successfully resolved
        
        // Example:
        return $this->isStatusFinite($this->status);
    }

    /**
     * Resolve the polling job to be dispatched.
     *
     * @return \Illuminate\Contracts\Queue\ShouldQueue
     */
    protected function resolvePollingJob(): ShouldQueue
    {
        // Resolve the job to be released back to the queue. This function will
        // most likely just return `new static` but it is here to allow for new
        // parameters to be passed to the next instance of the poll. This method
        // will only be executed when job release is not supported.
        
        // Example:
        return new static($this->status);
    }

    /**
     * Resolve the poll.
     *
     * @return bool
     */
    protected function resolvePoll(): bool;
    {
        // Here is where you insert the logic for the actual poll
        
        // Example
        $response = SomethingCool::getStatus($this->someProperty);

        $this->someCustomMethod($response);

        return $this->hasPollResolved();
    }
```

3. **Customisation Configuration**: If you would like to override certain configurations, you can do so by defining any
   of the following methods:

```php
    /**
     * Determine whether jobs can be released.
     *
     * @return bool
     */
    protected function canJobsBeReleased(): bool
    {
        return config('some.configuration', self::SUPPORTS_JOB_RELEASE);
    }

    /**
     * Get the interval of the poll.
     *
     * @return int
     */
    protected function getInterval(): int
    {
        return config('some.configuration', self::DEFAULT_INTERVAL);
    }

    /**
     * Get the maximum allowed number of attempts.
     *
     * @return int
     */
    protected function getMaxAttempts(): int
    {
        return config('some.configuration', self::DEFAULT_MAX_ATTEMPTS);
    }

    /**
     * Get the maximum lifetime of the poll.
     *
     * @return int
     */
    protected function getLifetime(): int
    {
        return config('some.configuration', self::DEFAULT_LIFETIME);
    }
```

## License

The Laravel Async Polling repository is open-sourced software licensed under
the [Apache License, Version 2.0](https://www.apache.org/licenses/LICENSE-2.0).
