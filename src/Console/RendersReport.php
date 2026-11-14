<?php

namespace HamzaWakrim\SafeMigrations\Console;

use HamzaWakrim\SafeMigrations\Analysis\Report;

/**
 * @mixin \Illuminate\Console\Command
 */
trait RendersReport
{
    protected function renderReport(Report $report, string $format = 'table'): void
    {
        if ($format === 'json') {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        $count = count($report->migrations);

        if ($count === 0) {
            $this->components->info('Nothing to check: no pending migrations.');

            return;
        }

        foreach ($report->byMigration() as $migration => $findings) {
            $this->newLine();
            $this->line("  <options=bold>{$migration}</>");

            foreach ($findings as $finding) {
                $label = $finding->isError() ? '<fg=white;bg=red> ERROR </>' : '<fg=black;bg=yellow> WARN </>';

                $this->line("  {$label} <fg=cyan>[{$finding->rule}]</> {$finding->message}");

                if ($finding->sql !== null) {
                    $this->line('         <fg=gray>'.$this->truncate($finding->sql).'</>');
                }

                if ($finding->suggestion !== null) {
                    $this->line("         <fg=green>→ {$finding->suggestion}</>");
                }
            }
        }

        $this->newLine();

        $summary = sprintf(
            'Checked %d migration(s): %d error(s), %d warning(s)%s.',
            $count,
            count($report->errors()),
            count($report->warnings()),
            $report->suppressed !== [] ? sprintf(', %d allowed by #[AllowUnsafe]', count($report->suppressed)) : '',
        );

        $report->findings === []
            ? $this->components->info($summary)
            : ($report->errors() !== [] ? $this->components->error($summary) : $this->components->warn($summary));
    }

    private function truncate(string $sql, int $length = 160): string
    {
        $sql = (string) preg_replace('/\s+/', ' ', trim($sql));

        return strlen($sql) > $length ? substr($sql, 0, $length - 3).'...' : $sql;
    }
}
