<?php

namespace App\Console\Commands;

use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;

class DemoSeedCommand extends Command
{
    protected $signature = 'demo:seed
        {--versions=1 : Number of versions to create}
        {--players=100 : Players per version}
        {--events=20 : Events per player}
        {--fresh : Drop all tables and re-run migrations first}';

    protected $description = 'Seed demo statistics data with parametric volumes';

    public function handle()
    {
        if ($this->option('fresh')) {
            $this->call('migrate:fresh');
        }

        $versions = max(1, (int) $this->option('versions'));
        $players = max(1, (int) $this->option('players'));
        $events = max(0, (int) $this->option('events'));

        $this->info("Seeding {$versions} version(s), {$players} players each, {$events} events per player...");

        $seeder = new DemoSeeder();
        $seeder->setContainer($this->laravel);
        $seeder->setCommand($this);
        $seeder->runParametric($versions, $players, $events);

        $this->info('Done.');

        return 0;
    }
}
