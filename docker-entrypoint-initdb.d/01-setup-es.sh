#!/bin/bash
set -e

# Configurar permisos
chown -R www-data:www-data /var/www/html/
chmod -R 755 /var/www/html/wp-content

# Esperar a que WordPress esté listo
until wp core is-installed --allow-root; do
    sleep 5
done

# Configurar el idioma
wp language core install es_ES --activate --allow-root
wp site switch-language es_ES --allow-root
wp language core update --allow-root

# Configurar los enlaces permanentes
wp rewrite structure '/%postname%/' --hard --allow-root
wp rewrite flush --hard --allow-root

# Establecer la zona horaria
wp option update timezone_string 'Europe/Madrid' --allow-root

# Forzar la actualización del idioma
wp eval 'switch_to_locale("es_ES");' --allow-root
