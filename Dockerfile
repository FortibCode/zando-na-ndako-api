FROM php:8.2-cli

# Set environment variables for Composer
ENV COMPOSER_ALLOW_SUPERUSER=1
ENV COMPOSER_MEMORY_LIMIT=-1

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libonig-dev \
    libxml2-dev \
    libpq-dev \
    zip \
    unzip \
    libzip-dev

# Clear cache
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# Configure & install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg
RUN docker-php-ext-install pdo pdo_pgsql pdo_mysql mbstring exif pcntl bcmath gd zip opcache

# OPcache : sans lui, PHP relit et recompile TOUS les fichiers de Laravel à chaque requête, ce qui
# coûtait à lui seul ~0,7 s par appel sur cette instance. Le code d'un conteneur ne changeant jamais
# en cours d'exécution, on désactive aussi la revérification des dates de fichiers.
RUN { \
      echo 'opcache.enable=1'; \
      echo 'opcache.enable_cli=0'; \
      echo 'opcache.memory_consumption=192'; \
      echo 'opcache.interned_strings_buffer=16'; \
      echo 'opcache.max_accelerated_files=20000'; \
      echo 'opcache.validate_timestamps=0'; \
    } > /usr/local/etc/php/conf.d/zando-opcache.ini

# Get latest Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www

# Copy existing application directory contents
COPY . /var/www

# Ensure .env exists during build
RUN cp .env.example .env

# Create required Laravel framework directories
RUN mkdir -p storage/framework/sessions storage/framework/views storage/framework/cache bootstrap/cache

# Set permissions for www-data
RUN chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache

# Install PHP dependencies ignoring platform extension strict checks & unlimited memory
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts --ignore-platform-reqs

# Expose port 10000
EXPOSE 10000

# Le serveur web intégré de PHP traite les requêtes UNE PAR UNE tant que cette variable n'est pas
# posée : les ~28 appels que l'application mobile lance au démarrage se mettaient en file, chacun
# attendant la fin du précédent (mesuré : 5 requêtes parallèles rendues en escalier 2s/3,7s/5,4s/
# 6,9s/8,7s). Avec des workers, elles sont servies en parallèle — ce qui compte d'autant plus ici
# que ces requêtes passent l'essentiel de leur temps à attendre la base de données, pas le CPU.
ENV PHP_CLI_SERVER_WORKERS=8

# Démarrage : préparer les caches PUIS lancer le serveur.
#   - `config:cache` et `route:cache` remplacent l'ancien `config:clear`, qui laissait Laravel
#     relire tous les fichiers de configuration et reconstruire la table de routage à chaque
#     requête. Ils sont exécutés ici (au démarrage) et non à la construction de l'image, car les
#     vraies variables d'environnement ne sont disponibles qu'à l'exécution.
#   - vérifié au préalable : le code n'appelle `env()` nulle part en dehors de `config/`, seule
#     condition qui rendrait `config:cache` dangereux.
CMD sh -c "rm -f bootstrap/cache/*.php \
  && php artisan package:discover --ansi \
  && php artisan storage:link --force \
  ; php artisan migrate --force \
  ; php artisan config:cache \
  ; php artisan route:cache \
  ; php -S 0.0.0.0:${PORT:-10000} -t public public/index.php"

