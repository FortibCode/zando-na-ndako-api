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
RUN docker-php-ext-install pdo pdo_pgsql pdo_mysql mbstring exif pcntl bcmath gd zip

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

# Start command: clear cache, discover packages, attempt migrations, and start serve
CMD sh -c "rm -f bootstrap/cache/*.php && php artisan config:clear && php artisan package:discover --ansi ; php artisan migrate --force ; php artisan serve --host=0.0.0.0 --port=${PORT:-10000}"
