<?php

namespace Arrilot\BitrixMigrations\Commands;

use Arrilot\BitrixMigrations\Migrator;

class StatusCommand extends AbstractCommand
{
    /**
     * Migrator instance.
     *
     * @var Migrator
     */
    protected $migrator;

    /**
     * Constructor.
     *
     * @param Migrator    $migrator
     * @param string|null $name
     */
    public function __construct(Migrator $migrator, $name = null)
    {
        $this->migrator = $migrator;

        parent::__construct($name);
    }

    /**
     * Configures the current command.
     */
    protected function configure(): void
    {
        $this->setName('status')->setDescription('Show status about last migrations');
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    protected function fire()
    {
        $this->showOldMigrations();

        $this->output->write("\r\n");

        $this->showNewMigrations();

        return 0;
    }

    /**
     * Show old migrations.
     *
     * @return void
     */
    protected function showOldMigrations()
    {
        $old = $this->migrator->getRanMigrations();

        $this->output->writeln("<fg=yellow>Old migrations:\r\n</>");

        $max = 5;
        if (count($old) > $max) {
            $this->output->writeln('<fg=yellow>...</>');

            $old = array_slice($old, -$max);
        }

        foreach ($old as $migration) {
            $this->output->writeln("<fg=yellow>{$migration}.php</>");
        }
    }

    /**
     * Show new migrations.
     *
     * @return void
     */
    protected function showNewMigrations()
    {
        $new = $this->migrator->getMigrationsToRun();

        $this->output->writeln("<fg=green>New migrations:\r\n</>");

        foreach ($new as $migration) {
            $this->output->writeln("<fg=green>{$migration}.php</>");
        }
    }
}
