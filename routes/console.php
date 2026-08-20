<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sauvegardes automatiques quotidiennes (base de données + fichiers de l'app), stockées sur le
// disque local du serveur (storage/app/private, cf. config/backup.php — spatie/laravel-backup).
// Nécessite qu'un cron serveur appelle `php artisan schedule:run` chaque minute (standard Laravel) :
// * * * * * cd /chemin/vers/le/projet && php artisan schedule:run >> /dev/null 2>&1
Schedule::command('backup:clean')->daily()->at('01:30');
Schedule::command('backup:run')->daily()->at('02:00')->onSuccess(function () {
    \Illuminate\Support\Facades\Log::info('[Backup] Sauvegarde quotidienne terminée avec succès.');
})->onFailure(function () {
    \Illuminate\Support\Facades\Log::error('[Backup] Échec de la sauvegarde quotidienne.');
});

// Diffuse les campagnes de notifications admin programmées pour une date passée ou présente
// (même mécanisme cron que les sauvegardes ci-dessus).
Schedule::command('notifications:envoyer-programmees')->everyMinute();
