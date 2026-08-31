<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\File;

Route::get('/', function () {
    return view('welcome');
});

// Route universelle pour servir les médias publics (photos de profil, produits, types de boutique, documents)
// y compris sur les hébergeurs Docker/PHP-CLI (Render) qui bloquent les symlinks.
Route::get('/storage/{path}', function ($path) {
    // 1. Chercher dans storage/app/public/
    $fullPath = storage_path('app/public/' . $path);

    if (File::exists($fullPath) && !File::isDirectory($fullPath)) {
        $mime = File::mimeType($fullPath) ?: 'image/png';
        return response()->file($fullPath, ['Content-Type' => $mime]);
    }

    // 2. Si le fichier exact n'existe pas mais est dans un sous-dossier photos,
    // prendre le premier fichier valide disponible dans ce dossier
    $dir = dirname($fullPath);
    if (File::exists($dir) && File::isDirectory($dir)) {
        $files = File::files($dir);
        if (count($files) > 0) {
            $fallback = $files[0]->getRealPath();
            $mime = File::mimeType($fallback) ?: 'image/png';
            return response()->file($fallback, ['Content-Type' => $mime]);
        }
    }

    abort(404);
})->where('path', '.*');
