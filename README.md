# azure-cdn-sync
Laravel based repo to sync NOD images to Azure CDN container stg/prod


composer require predis/predis

sudo apt-get install redis-server
sudo systemctl enable redis-server
sudo systemctl start redis-server

# This should be the cron that runs at 6AM
php artisan nod:sync-azure-cdn sbx --chunk=5


# Start multiple Laravel queue workers in the background
for i in $(seq 1 100); do
php artisan queue:work redis --queue=default &
done

php artisan queue:clear
