# azure-cdn-sync
Laravel based repo to sync NOD images to Azure CDN container stg/prod

sudo apt-get install redis-server
sudo systemctl enable redis-server
sudo systemctl start redis-server

# This should be the cron that runs at 6AM
php artisan nod:sync-azure-cdn sbx --chunk=1


# Start multiple Laravel queue workers in the background
for i in $(seq 1 300); do
php artisan queue:work redis --queue=default &
done

php artisan queue:clear


sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start laravel-queue:*
sudo supervisorctl restart laravel-queue:*


sudo crontab -e
0 6 * * * cd /var/www/azure-cdn-sync && /usr/bin/php artisan nod:sync-azure-cdn sbx --chunk=1 >> /var/log/azure-cdn-sync.log 2>&1

sudo supervisorctl reread
sudo supervisorctl update
sudo service supervisor restart

///   comanda                 sudo nano /etc/supervisor/conf.d/laravel-queue.conf  
[program:laravel-queue]
process_name=%(program_name)s_%(process_num)02d
command=/usr/bin/php /var/www/azure-cdn-sync/artisan queue:work redis --queue=default --tries=3 --delay=3
autostart=true
autorestart=true
user=root
numprocs=100
redirect_stderr=true
stdout_logfile=/tmp/worker.log
