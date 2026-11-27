<?php

namespace HamzaWakrim\SafeMigrations\Support;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Foundation\Console\QueuedCommand;

/**
 * Stands in for the console kernel while a migration is checked, so
 * Artisan::call('...') inside up() is recorded instead of run.
 */
final class FakeConsoleKernel implements Kernel
{
    /** @var list<array{string, array<mixed>}> */
    public array $calls = [];

    public function bootstrap() {}

    public function handle($input, $output = null)
    {
        return 0;
    }

    /** @param  array<mixed>  $parameters */
    public function call($command, array $parameters = [], $outputBuffer = null)
    {
        $this->calls[] = [(string) $command, $parameters];

        return 0;
    }

    /** @param  array<mixed>  $parameters */
    public function queue($command, array $parameters = [])
    {
        $this->calls[] = [(string) $command, $parameters];

        // Dispatched while the check runs, so the faked bus records it instead of queueing it.
        return new PendingDispatch(new QueuedCommand([$command, $parameters]));
    }

    /** @return array<string, \Symfony\Component\Console\Command\Command> */
    public function all()
    {
        return [];
    }

    public function output()
    {
        return '';
    }

    public function terminate($input, $status) {}
}
