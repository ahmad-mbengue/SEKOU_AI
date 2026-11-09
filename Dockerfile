# Dockerfile for Sekou backend (PHP + Apache + PostgreSQL)
# -------------------------------------------------------
# 1️⃣ Base image
FROM php:8.2-apache

# 2️⃣ Install required extensions
RUN apt-get update && apt-get install -y \
        libpq-dev \
        libcurl4-openssl-dev \
    && docker-php-ext-install pdo_pgsql curl mbstring

# 3️⃣ Enable Apache modules
RUN a2enmod rewrite

# 4️⃣ Copy backend source code (the Backend folder becomes the web root)
COPY Backend/ /var/www/html/

# 5️⃣ (Optional) copy any .htaccess file if it exists
COPY Backend/.htaccess /var/www/html/.htaccess 2>/dev/null || true

# 6️⃣ Expose the standard HTTP port (Render forwards $PORT internally)
EXPOSE 80

# 7️⃣ Start Apache in the foreground
CMD ["apache2-foreground"]
