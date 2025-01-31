<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\AzureBlobStorageService;

class AzureCdnSync extends Command
{
    // The signature is the name of the CLI command
    protected $signature = 'nod:sync-azure-cdn';

    // Description (visible with `php artisan list`)
    protected $description = 'Syncs NOD images with Azure CDN (prod/stg)';

    protected $azureService;

    public function __construct(AzureBlobStorageService $azureService)
    {
        parent::__construct();
        $this->azureService = $azureService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info("Starting Azure CDN Sync...");
        $this->azureService->syncCDN();
    }
}
