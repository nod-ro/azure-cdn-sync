<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\AzureBlobStorageService;
use App\Services\NODService;         // The service that returns the product feed
use App\Jobs\SyncCdnImagesJob;       // <-- We'll create this Job
use Illuminate\Support\Facades\Log;

class AzureCdnSync extends Command
{
    // For example:
    protected $signature = 'nod:sync-azure-cdn {env=sbx} {--chunk=50}';
    protected $description = 'Syncs NOD images with Azure CDN (prod/stg/etc) in parallel using queue jobs.';

    public function handle()
    {
        $env       = $this->argument('env');   // e.g. "sbx"
        $chunkSize = (int) $this->option('chunk');

        $this->info("Starting Azure CDN Sync (env: $env) with chunk size = $chunkSize.");

        // 1) Get your product feed
        $nodService = new NODService();
        $products   = $nodService->getFullFeedV2($env);

        // 2) Gather all images across all products
        $allImages = [];
        foreach ($products as $product) {
            // $product['pictures'] => array of ['picture_url' => '...']
            foreach ($product['pictures'] as $img) {
                $allImages[] = $img['picture_url'];
            }
        }

        $totalImages = count($allImages);
        $this->info("Found $totalImages images to sync.");

        // 3) Split images into chunks
        $chunks = array_chunk($allImages, $chunkSize);
        $chunkCount = count($chunks);

        foreach ($chunks as $index => $chunk) {
            // 4) Dispatch one queue job per chunk
            SyncCdnImagesJob::dispatch($env, $chunk);
            $this->info("Dispatched job for chunk #{$index} with ".count($chunk)." images.");
        }

        $this->info("Dispatched $chunkCount total jobs. Now run `php artisan queue:work` (in multiple workers) to process them in parallel.");
    }
}
