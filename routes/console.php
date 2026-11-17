<?php

use App\Imports\ImportService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('outbox:process --limit=100')->everyMinute()->withoutOverlapping(1);
Artisan::command('imports:prune {--dry-run}', function (ImportService $imports): void {
    $dryRun = (bool) $this->option('dry-run');
    $this->info(($dryRun ? 'Corridas elegibles: ' : 'Corridas depuradas: ').$imports->prune($dryRun));
})->purpose('Depurar los archivos y errores de importación vencidos');
Schedule::command('imports:prune')->daily()->withoutOverlapping();
if (config('security.login_attempt_schedule_prune', true)) {
    Schedule::command('security:prune-login-attempts')->daily()->withoutOverlapping();
}

if (config('commerce.sync.enabled')) {
    $options = ['--fetch' => true];
    if (config('commerce.sync.auto_apply')) {
        $options['--apply'] = true;
        $options['--actor'] = config('commerce.sync.actor_id');
    }
    Schedule::command('supplier:sync', $options)->hourly()->withoutOverlapping(15)->appendOutputTo(storage_path('logs/supplier-sync.log'));
}
