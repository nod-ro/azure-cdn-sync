<?php

namespace App\Jobs;

use App\Services\AzureBlobStorageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncCdnImagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $env;
    protected $imageUrls;

    /**
     * Create a new job instance.
     *
     * @param string $env
     * @param array  $imageUrls
     */
    public function __construct($env, array $imageUrls)
    {
        $this->env       = $env;
        $this->imageUrls = $imageUrls;
    }

    /**
     * Execute the job.
     */
    public function handle()
    {
        // 1) Instantiate your existing AzureBlobStorageService
        $azureService = new AzureBlobStorageService();

        // 2) Loop through the chunk of images
        foreach ($this->imageUrls as $url) {
            try {
                Log::info("Processing image {$url} in Job for env={$this->env}");
                $azureService->syncImage($this->env, $url);
            } catch (\Exception $e) {
                Log::error("Error processing {$url}: ".$e->getMessage());
            }
        }

        Log::info("Finished chunk of ".count($this->imageUrls)." images (env={$this->env}).");
    }
}
