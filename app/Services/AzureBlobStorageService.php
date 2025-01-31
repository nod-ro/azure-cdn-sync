<?php
namespace App\Services;

use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use MicrosoftAzure\Storage\Blob\Models\CreateBlockBlobOptions;
use Intervention\Image\Facades\Image;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery\Exception;

class AzureBlobStorageService
{
    protected $blobClients = [];
    protected $containers = [];
    protected $storageUrls = [];

    public function __construct()
    {
        // Only process SBX environment
        $this->environments = ['sbx'];

        foreach ($this->environments as $env) {
            $connectionString = env("AZURE_STORAGE_CONNECTION_STRING_" . strtoupper($env));
            $container = env("AZURE_STORAGE_CONTAINER_" . strtoupper($env));
            $storageUrl = env("AZURE_STORAGE_URL_" . strtoupper($env));

//            echo getenv("AZURE_STORAGE_CONNECTION_STRING_SBX") . PHP_EOL;
//            echo getenv("NOD_PASSWORD_SBX") . PHP_EOL;
//            echo getenv("NOD_USER_SBX") . PHP_EOL;
//            echo $env . PHP_EOL;
//            echo strtoupper($env) . PHP_EOL;
//            echo "AZURE_STORAGE_CONNECTION_STRING_" . strtoupper($env) . PHP_EOL;
//            echo "connectionString:" . $connectionString . PHP_EOL;
//            echo "container:" .  $container . PHP_EOL;
//            echo "connectionString:" . $connectionString . PHP_EOL;

            if (!$connectionString || !$container || !$storageUrl) {
                Log::error("Missing Azure Storage credentials for environment: $env");
                continue;
            }

            try {
                $this->blobClients[$env] = BlobRestProxy::createBlobService($connectionString);
                $this->containers[$env] = $container;
                $this->storageUrls[$env] = $storageUrl;
                Log::info("Successfully initialized Azure Blob Storage for $env");
            } catch (\Exception $e) {
                Log::error("Failed to initialize Azure Blob for $env: " . $e->getMessage());
            }
        }

        if (empty($this->blobClients)) {
           throw new \Exception("No valid Azure Storage configurations found.");
        }
    }

    public function syncCDN(){
        $nod_service = new NODService();
        foreach( $this->environments as $env) {
            $products = $nod_service->getFullFeedV2($env);
            foreach($products as $product) {
                $images = $product['pictures'];
                foreach($images as $image){
                    try {
                        $this->syncImage($env, $image['picture_url']);
                    } catch (Exception $e) {
                        Log::error("Error in syncImage(): " . $e->getMessage());
                    }
                }
            }
        }
    }

    public function syncImage($env, $nodImageUrl)
    {
        echo "Processing image: " . json_encode($nodImageUrl) . PHP_EOL;
        try {
            Log::info("Downloading image from: $nodImageUrl");

            // Ensure temporary directory exists
            $tempDir = storage_path('app/temp');
            if (!file_exists($tempDir)) {
                mkdir($tempDir, 0755, true);
            }

            // Extract original filename and extension correctly
            $pathInfo = pathinfo(parse_url($nodImageUrl, PHP_URL_PATH));
            $originalFileName = preg_replace('/[^a-zA-Z0-9_-]/', '', $pathInfo['filename']); // Remove special characters
            $originalExtension = isset($pathInfo['extension']) ? strtolower($pathInfo['extension']) : 'jpg'; // Default to JPG if missing

            // Preserve original filename exactly as it is
            $originalImagePath = "{$tempDir}/{$originalFileName}.{$originalExtension}";

            // ✅ Check if original image already exists before saving
            if (!file_exists($originalImagePath)) {
                $imageContents = @file_get_contents($nodImageUrl);
                if (!$imageContents) {
                    throw new \Exception("Failed to download image from URL: $nodImageUrl");
                }

                file_put_contents($originalImagePath, $imageContents);
                Log::info("Original image saved: $originalImagePath");
            } else {
                Log::info("Original image already exists, skipping download: $originalImagePath");
            }

            // 3. Generate thumbnails only if they don’t exist
            $sizes = [
                '300x300' => [300, 300],
                '600x600' => [600, 600],
            ];

            $uploadedFiles = [];

            foreach ($sizes as $sizeName => $dimensions) {
                [$width, $height] = $dimensions;
                $resizedFileName = "{$originalFileName}-{$sizeName}.{$originalExtension}";
                $resizedPath = "{$tempDir}/{$resizedFileName}";

                if (!file_exists($resizedPath)) {
                    // Resize and handle interlaced PNG
                    $image = Image::make($originalImagePath)
                        ->resize($width, $height, function ($constraint) {
                            $constraint->aspectRatio();
                            $constraint->upsize();
                        });

                    if ($originalExtension === 'png') {
                        $image->interlace(true); // Enable interlace for PNG
                    }

                    $image->save($resizedPath);
                    Log::info("Generated thumbnail: $resizedFileName");
                } else {
                    Log::info("Thumbnail already exists, skipping: $resizedPath");
                }

                // 4. Upload to Azure SBX
                if (!isset($this->blobClients[$env])) {
                    throw new \Exception("Invalid environment specified: $env");
                }

                $blobClient = $this->blobClients[$env];
                $azureFilePath = "testing/{$resizedFileName}";
                $uploadedUrl = $this->uploadFile($blobClient, $this->containers[$env], $resizedPath, $azureFilePath, $env);

                if ($uploadedUrl) {
                    $uploadedFiles[$env][$sizeName] = $uploadedUrl;
                    Log::info("Uploaded to $env: $uploadedUrl");
                } else {
                    Log::error("Failed to upload $resizedFileName to $env");
                }
            }

            Log::info("Thumbnails generated and uploaded successfully!");

            return $uploadedFiles;
        } catch (\Exception $e) {
            Log::error("Error in syncImage(): " . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    private function uploadFile($blobClient, $container, $localFilePath, $azureFilePath, $env)
    {
        try {
            if (!file_exists($localFilePath)) {
                throw new \Exception("File does not exist: $localFilePath");
            }

            $content = fopen($localFilePath, "r");
            $options = new CreateBlockBlobOptions();
            $options->setContentType(mime_content_type($localFilePath));

            $blobClient->createBlockBlob($container, $azureFilePath, $content, $options);

            return $this->storageUrls[$env] . '/' . $container . '/' . $azureFilePath;
        } catch (\Exception $e) {
            Log::error("Azure Upload Error in $env: " . $e->getMessage());
            return false;
        }
    }
}
