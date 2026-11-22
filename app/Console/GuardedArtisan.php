<?php

namespace App\Console;

use Illuminate\Console\Application as Artisan;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Artisan application whose single execution point validates the database
 * target of every command before it runs, independently of console events.
 */
class GuardedArtisan extends Artisan
{
    /** @var list<int> */
    private array $running = [];

    protected function doRunCommand(Command $command, InputInterface $input, OutputInterface $output): int
    {
        $boundInput = $this->bindForInspection($command, $input);
        $level = ArtisanCommandGate::classify($command, $boundInput);

        if ($boundInput === null && $level >= ArtisanCommandGate::READ) {
            $level = max($level, ArtisanCommandGate::SCHEMA);
        }

        ArtisanCommandGate::authorize($this->laravel, $command, $boundInput, $level);

        $this->running[] = $level;
        try {
            return parent::doRunCommand($command, $input, $output);
        } finally {
            array_pop($this->running);
        }
    }

    /**
     * Commands invoked from inside another command via $this->call('name')
     * are resolved here without passing through doRunCommand().
     */
    public function find(string $name): Command
    {
        $command = parent::find($name);

        if ($this->running !== []) {
            $resolved = $command instanceof LazyCommand ? $command->getCommand() : $command;
            $level = ArtisanCommandGate::classify($resolved, null);

            if ($level >= ArtisanCommandGate::CLIENT && $level > max($this->running)) {
                ArtisanCommandGate::authorize($this->laravel, $resolved, null, $level);
            }
        }

        return $command;
    }

    private function bindForInspection(Command $command, InputInterface $input): ?InputInterface
    {
        try {
            $inspected = clone $input;
            $command->mergeApplicationDefinition();
            $inspected->bind($command->getDefinition());

            return $inspected;
        } catch (Throwable) {
            return null;
        }
    }
}
