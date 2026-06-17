FROM php:8.4-fpm-alpine

# Installer les dépendances nécessaires
RUN apk add --no-cache \
    nginx \
    && rm -rf /var/cache/apk/*

# Working directory pour l'application
WORKDIR /var/www/html

# Mise en place d'un utilisateur non privilégié
# RUN adduser -D appuser

# RUN chown -R appuser:appuser /var/www/html/

# USER appuser

# Copier configuration par défaut Nginx
COPY nginx.conf /etc/nginx/nginx.conf

# Copier le code de l'application dans le conteneur
COPY src/ /var/www/html/

COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Exposer le port 80
EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
