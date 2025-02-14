<?php
namespace App\Services;

use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use MicrosoftAzure\Storage\Blob\Models\CreateBlockBlobOptions;
use Intervention\Image\Facades\Image;
use Illuminate\Support\Facades\Log;
use MicrosoftAzure\Storage\Blob\Models\ListBlobsOptions;
use Mockery\Exception;

class AzureBlobStorageService
{
    protected $blobClients = [];
    protected $containers = [];
    protected $storageUrls = [];

    public function __construct()
    {
        // Only process SBX environment
        $this->environments = ['prod','sbx'];

        foreach ($this->environments as $env) {
            $connectionString = env("AZURE_STORAGE_CONNECTION_STRING_" . strtoupper($env));
            $container = env("AZURE_STORAGE_CONTAINER_" . strtoupper($env));
            if (!$connectionString || !$container) {
                Log::error("Missing Azure Storage credentials for environment: $env");
                continue;
            }

            try {
                $this->blobClients[$env] = BlobRestProxy::createBlobService($connectionString);
                $this->containers[$env] = $container;
                Log::info("Successfully initialized Azure Blob Storage for $env");
            } catch (\Exception $e) {
                Log::error("Failed to initialize Azure Blob for $env: " . $e->getMessage());
            }
        }

        if (empty($this->blobClients)) {
       //     throw new \Exception("No valid Azure Storage configurations found.");
        }
    }

    public function syncCDN()
    {
        $nod_service = new NODService();
        foreach ($this->environments as $env) {
            $products = $nod_service->getFullFeedV2($env);
            $total_products = count($products);
            echo "Processing $total_products products for $env...\n";
            if (!is_dir('thumbnails')) {
                mkdir('thumbnails', 0777, true);
            }
            $counter = 0;
            foreach ($products as $product) {
                $counter++;
                $images = $product['pictures'];
                $message = "Processing product $counter of $total_products with " . count($images) . " images to process..";
                Log::info($message);
                echo $message . PHP_EOL;
                foreach ($images as $image) {
                    try {
                        $this->syncImage($env, $image['picture_url']);
                    } catch (Exception $e) {
                        Log::error("Error in syncImage(): " . $e->getMessage());
                    }
                }
            }
        }
    }

    public function syncImage($env, $nodImageUrl, $azureFolder = false)
    {
        echo "Processing image: " . json_encode($nodImageUrl) . PHP_EOL;
        try {
            Log::info("[$env] Downloading image from: $nodImageUrl");

            // Extract original filename and extension correctly
            $pathInfo = pathinfo(parse_url($nodImageUrl, PHP_URL_PATH));
            $originalFileName = preg_replace('/[^a-zA-Z0-9_-]/', '', $pathInfo['filename']); // Remove special characters
            $originalExtension = $pathInfo['extension'] ?? 'jpg'; // Default to jpg if no extension provided

            // Use base_path to dynamically build the path inside the application directory
            $originalImagePath = base_path( "thumbnails/{$originalFileName}.{$originalExtension}");

            // Generate Azure path for the original image
            $blobClient = $this->blobClients[$env];
            $container = $this->containers[$env];
            if ($azureFolder) {
                $originalAzureFilePath = "{$azureFolder}/{$originalFileName}.{$originalExtension}";
            } else {
                $originalAzureFilePath = "{$originalFileName}.{$originalExtension}";
            }

            // Check if the image already exists in Azure
            $existsInAzure = $this->azureImageExists($blobClient, $container, $originalAzureFilePath);

            // If the image does not exist locally, download it
            if (!file_exists($originalImagePath)) {
                Log::info("[$env] Downloading image from URL: $nodImageUrl");
                $imageContents = file_get_contents($nodImageUrl);
                file_put_contents($originalImagePath, $imageContents);
                Log::info("[$env] Original image saved: $originalImagePath");
            }

            // Upload the original image to Azure if it's missing
            if (!$existsInAzure) {
                $uploadedOriginalUrl = $this->uploadFile($blobClient, $container, $originalImagePath, $originalAzureFilePath, $env);
                if ($uploadedOriginalUrl) {
                    Log::info("[$env] Original image uploaded to Azure: $uploadedOriginalUrl");
                } else {
                    Log::error("[$env] Failed to upload original image to Azure: $originalImagePath");
                }
            } else {
                Log::info("[$env] Image already exists in Azure, skipping upload: $originalAzureFilePath");
            }

            // WordPress Image Sizes (Ensuring All Are Processed)
            $sizes = [
                'thumbnail' => [150, 150, true], // Crop
                'medium' => [300, 300, false], // No Crop
                'medium_second' => [600, 600, false], // No Crop
                'large' => [1024, 1024, false], // No Crop
                'full' => [0, 0, false], // Keeps original without modifying filename
                'woocommerce_thumbnail' => [300, 300, true], // Crop
                'woocommerce_single' => [600, 0, false], // Auto height
                'woocommerce_gallery_thumbnail' => [100, 100, true], // Crop
            ];

            foreach ($sizes as $sizeName => [$width, $height, $crop]) {
                Log::info("[$env] Processing size: $sizeName ({$width}x{$height})");

                // Skip "full" size because it's just the original image
                if ($sizeName === 'full') {
                    Log::info("[$env] Skipping full-size processing (original already uploaded).");
                    continue;
                }

                // Load the original image safely
                try {
                    $imageOriginal = Image::make($originalImagePath);
                } catch (\Exception $e) {
                    Log::error("[$env] Failed to open image: " . $originalImagePath . " - Error: " . $e->getMessage());
                    continue; // Skip this image if it's invalid
                }

                // Ensure the image has valid dimensions before calculations
                $imageWidth = $imageOriginal->width();
                $imageHeight = $imageOriginal->height();

                // Handle auto-height images correctly (height = 0 means "auto-calculate based on aspect ratio")
                if ($height === 0) {
                    if ($imageHeight > 0) { // Prevent division by zero
                        $aspectRatio = $imageWidth / $imageHeight;
                        $height = (int) round($width / $aspectRatio);
                        Log::info("[$env] Auto height calculated: {$width}x{$height} for {$sizeName}");
                    } else {
                        Log::error("[$env] Aspect ratio calculation failed for {$sizeName}. Skipping...");
                        continue; // Skip processing if aspect ratio is invalid
                    }
                }

                // Generate correct file name for resized image
                $resizedFileName = "{$originalFileName}-{$width}x{$height}." . strtolower($originalExtension);
                $resizedPath = base_path("thumbnails/{$resizedFileName}");

                if ($azureFolder) {
                    $azureFilePath = "{$azureFolder}/{$resizedFileName}";
                } else {
                    $azureFilePath = "{$resizedFileName}";
                }

                // Check if the file already exists in Azure
                if (!$this->azureImageExists($blobClient, $container, $azureFilePath)) {
                    $image = Image::make($originalImagePath);

                    if ($crop) {
                        // Ensure the image is large enough to crop
                        $image->resize($width, $height, function ($constraint) {
                            $constraint->aspectRatio();
                            $constraint->upsize(); // Ensures small images are resized up
                        });

                        // Apply cropping
                        $image->fit($width, $height);
                    } else {
                        $image->resize($width, $height, function ($constraint) {
                            $constraint->aspectRatio();
                            $constraint->upsize(); // Ensures images are always created
                        });
                    }

                    // Save the resized image locally
                    $image->save($resizedPath);
                    Log::info("[$env] Generated image: $resizedFileName");

                    // Upload resized image to Azure
                    $this->uploadFile($blobClient, $container, $resizedPath, $azureFilePath, $env);
                    Log::info("[$env] Uploaded to Azure: $azureFilePath");
                } else {
                    Log::info("[$env] Image already exists in Azure, skipping upload: $azureFilePath");
                }
            }

            return true;
        } catch (\Exception $e) {
            Log::error("[$env] Error in syncImage(): " . $e->getMessage());
            return false;
        }
    }

    private function azureImageExists($blobClient, $container, $azureFilePath)
    {
        try {
            $listOptions = new ListBlobsOptions();
            $listOptions->setPrefix($azureFilePath);

            $blobList = $blobClient->listBlobs($container, $listOptions);
            return !empty($blobList->getBlobs());
        } catch (\Exception $e) {
            Log::error("Error checking Azure file existence: " . $e->getMessage());
            return false;
        }
    }


    private function downloadAzureBlob($blobClient, $container, $blobPath)
    {
        try {
            Log::info("Attempting to fetch Azure blob: $blobPath");
            $blob = $blobClient->getBlob($container, $blobPath);
            return stream_get_contents($blob->getContentStream());
        } catch (\Exception $e) {
            Log::error("Failed to download image from Azure: " . $e->getMessage());
            return false;
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

            return  $container . '/' . $azureFilePath;
        } catch (\Exception $e) {
            Log::error("Azure Upload Error in $env: " . $e->getMessage());
            return false;
        }
    }
}
