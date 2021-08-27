<?php

namespace ProcessMaker\Flysystem\Adapter;

use finfo;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Stream;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Config;
use League\Flysystem\FileNotFoundException;
use League\Flysystem\FilesystemInterface;
use League\Flysystem\PluginInterface;
use ProcessMaker\Flysystem\Adapter\MSGraph\AuthException;
use ProcessMaker\Flysystem\Adapter\MSGraph\ModeException;
use ProcessMaker\Flysystem\Adapter\MSGraph\SiteInvalidException;
use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use Microsoft\Graph\Graph;
use Microsoft\Graph\Http\GraphResponse;
use Microsoft\Graph\Model;

class MSGraph extends FilesystemAdapter
{


    const MODE_SHAREPOINT = 'sharepoint';
    const MODE_ONEDRIVE = 'onedrive';

    // Our mode, if sharepoint or onedrive
    private $mode;
    // Our Microsoft Graph Client
    private $graph;
    // Our Microsoft Graph Access Token
    private $token;
    // Our targetId, sharepoint site if sharepoint, drive id if onedrive
    private $targetId;
    // Our driveId, which if non empty points to a Drive
    private $driveId;
    // Our url prefix to be used for most file operations. This gets created in our constructor
    private $prefix;

    private $chunkSize;
    /** @var int $largeFileUploadProgress */
    private $largeFileUploadProgress;
    /** @var int $currentLargeFileRetryInterval */
    private $currentLargeFileRetryInterval;
    // If we get a server error, retry for a maximum of 10 times in the intervals below (in seconds)
    private $retryIntervals = [0, 1, 1, 2, 3, 5, 8, 13, 21, 34];


    public function __construct($token, $mode = self::MODE_ONEDRIVE, $targetId, $driveName = null)
    {
        if ($mode != self::MODE_ONEDRIVE && $mode != self::MODE_SHAREPOINT) {
            throw new ModeException("Unknown mode specified: " . $mode);
        }
        $this->mode = $mode;
        $this->largeFileUploadProgress = 0;

        //Aplication should take care of token and refresh it


//        // Initialize the OAuth client
//        $oauthClient = new \League\OAuth2\Client\Provider\GenericProvider([
//            'clientId' => $appId,
//            'clientSecret' => $appPassword,
//            'urlAuthorize' => '',
//            'urlResourceOwnerDetails' => '',
//            'urlAccessToken' => $tokenEndpoint,
//        ]);
//
//        try {
//            $this->token = $oauthClient->getAccessToken('client_credentials', [
//                'scope' => 'https://graph.microsoft.com/.default'
//            ]);
//        } catch(IdentityProviderException $e) {
//            throw new AuthException($e->getMessage());
//        }

        // Assign graph instance
        $this->graph = new Graph();
//        $this->graph->setAccessToken($this->token->getToken());
        $this->graph->setAccessToken($token);

        if ($mode == self::MODE_ONEDRIVE) {
//            try {
//                $site = $this->graph->createRequest('GET', '/sites/' . $targetId)
//                    ->setReturnType(Model\Site::class)
//                    ->execute();
//                // Assign the site id triplet to our targetId
//                $this->targetId = $site->getId();
//            } catch(\Exception $e) {
//                if($e->getCode() == 400) {
//                    throw new SiteInvalidException("The sharepoint site " . $targetId . " is invalid.");
//                }
//                throw $e;
//            }
            $this->prefix = "/sites/" . $this->targetId . '/drive/items/';
            $this->prefix = "/me/drive/";
//            $this->prefix = "/me/drive/items/A7669AFABA6AA37A!1240/";

//            if($driveName != '') {
//                // Then we specified a drive name, so let's enumerate the drives and find it
//                $drives = $this->graph->createRequest('GET', '/sites/' . $this->targetId . '/drives')
//                    ->execute();
//                $drives = $drives->getBody()['value'];
//                foreach($drives as $drive) {
//                    if($drive['name'] == $driveName) {
//                        $this->driveId = $drive['id'];
//                        $this->prefix = "/drives/" . $this->driveId . "/items/";
//                        break;
//                    }
//                }
//                if(!$this->driveId) {
//                    throw new SiteInvalidException("The sharepoint drive with name " . $driveName  . " could not be found.");
//                }
//
//            }
        }//ONEDRIVE?

        // Check for existence
        if ($mode == self::MODE_SHAREPOINT) {
            try {
                $site = $this->graph->createRequest('GET', '/sites/' . $targetId)
                    ->setReturnType(Model\Site::class)
                    ->execute();
                // Assign the site id triplet to our targetId
                $this->targetId = $site->getId();
            } catch (\Exception $e) {
                if ($e->getCode() == 400) {
                    throw new SiteInvalidException("The sharepoint site " . $targetId . " is invalid.");
                }
                throw $e;
            }
            $this->prefix = "/sites/" . $this->targetId . '/drive/items/';
            if ($driveName != '') {
                // Then we specified a drive name, so let's enumerate the drives and find it
                $drives = $this->graph->createRequest('GET', '/sites/' . $this->targetId . '/drives')
                    ->execute();
                $drives = $drives->getBody()['value'];
                foreach ($drives as $drive) {
                    if ($drive['name'] == $driveName) {
                        $this->driveId = $drive['id'];
                        $this->prefix = "/drives/" . $this->driveId . "/items/";
                        break;
                    }
                }
                if (!$this->driveId) {
                    throw new SiteInvalidException("The sharepoint drive with name " . $driveName . " could not be found.");
                }

            }
        }

    }

    public function has($path)
    {
        if ($this->mode == self::MODE_ONEDRIVE) {
            try {
                $driveItem = $this->graph->createRequest('GET', $this->prefix . 'root:/' . $path)
                    ->setReturnType(Model\DriveItem::class)
                    ->execute();
                // Successfully retrieved meta data.
                return true;
            } catch (ClientException $e) {
                if ($e->getCode() == 404) {
                    // Not found, let's return false;
                    return false;
                }
                throw $e;
            } catch (Exception $e) {
                throw $e;
            }
        }
        if ($this->mode == self::MODE_SHAREPOINT) {
            try {
                $driveItem = $this->graph->createRequest('GET', $this->prefix . 'root:/' . $path)
                    ->setReturnType(Model\DriveItem::class)
                    ->execute();
                // Successfully retrieved meta data.
                return true;
            } catch (ClientException $e) {
                if ($e->getCode() == 404) {
                    // Not found, let's return false;
                    return false;
                }
                throw $e;
            } catch (Exception $e) {
                throw $e;
            }
        }
        return false;
    }

    public function read($path)
    {
        if ($this->mode == self::MODE_ONEDRIVE) {
            try {
                $driveItem = $this->graph->createRequest('GET', $this->prefix . 'root:/' . $path)
                    ->setReturnType(Model\DriveItem::class)
                    ->execute();
                // Successfully retrieved meta data.
                // Now get content
                $itemId = $driveItem->getId();
                $contentStream = $this->graph->createRequest("GET", "/me/drive/items/" . $itemId . "/content")
                    ->setReturnType(Stream::class)
                    ->execute();

                $contents = '';
                $bufferSize = 8012;
                // Copy over the data into a string
                while (!$contentStream->eof()) {
                    $contents .= $contentStream->read($bufferSize);
                }
                return ['contents' => $contents];
            }catch (ClientException $e) {
                if ($e->getCode() == 404) {
                    // Not found, let's return false;
                    return false;
                }
                throw $e;
            } catch (Exception $e) {
                throw $e;
            }

        }

        if ($this->mode == self::MODE_SHAREPOINT) {
            try {
                $driveItem = $this->graph->createRequest('GET', $this->prefix . 'root:/' . $path)
                    ->setReturnType(Model\DriveItem::class)
                    ->execute();
                // Successfully retrieved meta data.
                // Now get content
                $contentStream = $this->graph->createRequest('GET', $this->prefix . $driveItem->getId() . '/content')
                    ->setReturnType(Stream::class)
                    ->execute();
                $contents = '';
                $bufferSize = 8012;
                // Copy over the data into a string
                while (!$contentStream->eof()) {
                    $contents .= $contentStream->read($bufferSize);
                }
                return ['contents' => $contents];
            } catch (ClientException $e) {
                if ($e->getCode() == 404) {
                    // Not found, let's return false;
                    return false;
                }
                throw $e;
            } catch (Exception $e) {
                throw $e;
            }
        }
        return false;
    }

    public function url($path)
    {
        return $this->getUrl($path);
    }

    public function getUrl($path)
    {
        if ($this->mode == self::MODE_ONEDRIVE) {

//            return 'http://www.google.com';
            try {
                $driveItem = $this->graph->createRequest('GET', $this->prefix . 'root:/' . $path)
                    ->setReturnType(Model\DriveItem::class)
                    ->execute();
                // Successfully retrieved meta data.
//                dd($driveItem);
                $permission = $this->graph->createRequest("POST", $this->prefix . "items/" . $driveItem->getId() . "/createLink")
                    ->attachBody(array("type" => "edit", "scope" => "anonymous"))
                    ->setReturnType(Model\Permission::class)
                    ->execute();
                $link = $permission->getLink();

//                dd($permission);
                // Return url property
                return $link->getWebUrl();
            } catch (ClientException $e) {
                if ($e->getCode() == 404) {
                    // Not found, let's return false;
                    return false;
                }
                throw $e;
            } catch (Exception $e) {
                throw $e;
            }
        }

        if ($this->mode == self::MODE_SHAREPOINT) {
            try {
                $driveItem = $this->graph->createRequest('GET', $this->prefix . 'root:/' . $path)
                    ->setReturnType(Model\DriveItem::class)
                    ->execute();
                // Successfully retrieved meta data.
                // Return url property
                return $driveItem->getWebUrl();
            } catch (ClientException $e) {
                if ($e->getCode() == 404) {
                    // Not found, let's return false;
                    return false;
                }
                throw $e;
            } catch (Exception $e) {
                throw $e;
            }
        }
        return false;
    }

    public function readStream($path)
    {

    }

    public function listContents($directory = '', $recursive = false)
    {
        $results = [];
//        if ($this->mode == self::MODE_SHAREPOINT) {
//            try {
//                    $drive = $this->graph->createRequest('GET', $this->prefix . 'root:/' . $directory)
//                        ->setReturnType(Model\Drive::class)
//                        ->execute();
//
//                // Successfully retrieved meta data.
//                // Now get content
//                $driveItems = $this->graph->createRequest('GET', $this->prefix . $drive->getId() .'/children')
//                    ->setReturnType(Model\DriveItem::class)
//                    ->execute();
//
//                $children = [];
//                foreach ($driveItems as $driveItem) {
//                    $item = $driveItem->getProperties();
//                    $item['path'] = $directory . '/' . $driveItem->getName();
//                    $children[] = $item;
//                }
//                return $children;
//            } catch (ClientException $e) {
//                throw $e;
//            } catch (Exception $e) {
//                throw $e;
//            }
//        }

        if ($this->mode == self::MODE_ONEDRIVE) {
            try {
//                dd($this->prefix);

                $drive = $this->graph->createRequest('GET', $this->prefix . 'root:/' . $directory)
                    ->setReturnType(Model\Drive::class)
                    ->execute();

//                dd($drive);

//                $driveItems = $this->graph->createRequest('GET', $this->prefix . '' . $directory)
//                        ->setReturnType(Model\DriveItem::class)
//                        ->execute();

//                dd('/me/drive/items/'.$drive->getId().'/children');
                // Successfully retrieved meta data.
                // Now get content
                $driveItems = $this->graph->createRequest('GET', '/me/drive/items/' . $drive->getId() . '/children')
                    ->setReturnType(Model\DriveItem::class)
                    ->execute();

                $children = [];
                foreach ($driveItems as $driveItem) {
                    $item = $driveItem->getProperties();

                    //Folder Or File
                    $item['type'] = isset($item['folder']) ? 'folder' : 'file';
                    $item['path'] = $directory . '/' . $driveItem->getName();

                    if($item)
                    $children[] = $item;
                    if($item['type'] == 'folder'){
                        if ($recursive) {
                            $children = array_merge($children, $this->listContents($item['path'], true));

                        }
                    }
                }
                return $children;
            } catch (ClientException $e) {
                throw $e;
            } catch (Exception $e) {
                throw $e;
            }
        }
        return $results;
    }

    public function getMetadata($path)
    {

    }

    public function getSize($path)
    {

    }

    public function getMimetype($path)
    {
        $finfo = new finfo(FILEINFO_MIME);
        return $finfo->buffer($this->get($path));
    }

    public function getTimestamp($path)
    {

    }

    public function getVisibility($path)
    {

    }


    /**
     * Upload a file that's larger than 4MB.
     */
    public function uploadLargeItem(string $path, string $filesrc): ?Model\DriveItem
    {
        // Optimal chunk size is 5-10MiB and should be a multiple of 320 KiB
        $chunkSizeBytes = 10485760; // is 10 MiB
        $fileSize = filesize($filesrc);
        $options = [
            'item' => ['@microsoft.graph.conflictBehavior' => 'rename']
        ];

        // Get the URL that we can post our file chunks to.
        $createItemUrl = '/me/drive/root:/' . $path . ':/createUploadSession';
        $session = $this->graph->createRequest("POST", $createItemUrl)
            ->setReturnType(Model\UploadSession::class)
            ->attachBody($options)
            ->execute();
        $uploadUrl = $session->getUploadUrl();

        // Upload the various chunks.
        // $status will be false until the process is complete.
        $status = false;
        $handle = fopen($filesrc, "rb");
        while (!$status && !feof($handle)) {
            $chunk = fread($handle, $chunkSizeBytes);
            $status = $this->nextChunk($uploadUrl, $fileSize, $chunk);
        }

        // The final value of $status will be the data from the API for the object
        // that has been uploaded.
        $result = false;
        if ($status !== false) {
            /** @var Model\DriveItem */
            $result = $status;
        }

        fclose($handle);

        if (!$result) {
            return null;
        }

        return $result;
    }

    /**
     * Send the next part of the file to upload.
     * @param [$chunk] the next set of bytes to send. If false will used $data passed
     * at construct time.
     *
     * Got inspiration from:
     * https://github.com/googleapis/google-api-php-client/blob/master/src/Google/Http/MediaFileUpload.php#L113-L141
     */
    private function nextChunk(string $uploadUrl, int $fileSize, $chunk = false)
    {
        if (false == $chunk) {
            $chunk = substr(null, $this->largeFileUploadProgress, $this->chunkSize);
        }
        $lastBytePos = $this->largeFileUploadProgress + strlen($chunk) - 1;
        $headers = array(
            'Content-Range' => "bytes $this->largeFileUploadProgress-$lastBytePos/$fileSize",
            'Content-Length' => strlen($chunk),
        );

//        dd($headers);

        /**
         * We shouldn't send the Authorization header here (as a tempauth token is included in the $uploadUrl),
         * so we can use a plain GuzzleHttp client.
         */
        $client = new Client();
        $response = $client->request(
            "PUT",
            $uploadUrl,
            [
                'headers' => $headers,
                'body' => Utils::streamFor($chunk),
                'timeout' => 90
            ]
        );

        // A 404 code indicates that the upload session no longer exists, thus requiring us to start all over.
        if ($response->getStatusCode() === 404) {
            throw new \Exception('Upload URL has expired, please create new upload session');
        }

        // Retry if we get a server error, for a maximum of 10 times with time intervals.
        if (in_array($response->getStatusCode(), [500, 502, 503, 504])) {
            if ($this->currentLargeFileRetryInterval > 9) {
                throw new \Exception('Upload failed after 10 attempts.');
            }
            // Wait for the amount of seconds defined in the retryIntervals.
            sleep($this->retryIntervals[$this->currentLargeFileRetryInterval]);
            $this->currentLargeFileRetryInterval++;
            $this->nextChunk($uploadUrl, $fileSize, $chunk);
        }

        /**
         * If we have uploaded the last chunk, we should receive a 200 or 201 Created response code,
         * including a DriveItem. We use the Graph function getResponseAsObject to get the DriveItem object.
         */
        if (($fileSize - 1) == $lastBytePos) {
            /**
             * If a conflict occurs after the file is uploaded (for example,
             * an item with the same name was created during the upload session),
             * an error is returned when the last byte range is uploaded.
             */
            if ($response->getStatusCode() === 409) {
                throw new \Exception(
                    'File already exists. A file with the same name might have been created during the upload session.'
                );
            }

            if (in_array($response->getStatusCode(), [200, 201])) {
                $response = new GraphResponse(
                    $this->graph->createRequest('', ''),
                    $response->getBody(),
                    $response->getStatusCode(),
                    $response->getHeaders()
                );

                $item = $response->getResponseAsObject(Model\DriveItem::class);
                return $item;
            }

            throw new \Exception(
                'Unknown error occured while uploading last part of file. HTTP response code is '
                . $response->getStatusCode()
            );
        }

        /**
         * If we didn't receive a 202 Accepted response from the Graph API, something has gone wrong.
         */
        if ($response->getStatusCode() !== 202) {
            throw new \Exception(
                'Unknown error occured while trying to upload file chunk. HTTP status code is '
                . $response->getStatusCode()
            );
        }

        /**
         * If we received a 202 Accepted response, it will include a nextExpectedRanges key, which will tell
         * us the next range we'll upload.
         */
        $body = json_decode($response->getBody()->getContents(), true);
        $nextExpectedRanges = $body['nextExpectedRanges']; // e.g. ["12345-55232","77829-99375"]
        $nextRange = $nextExpectedRanges[0]; // e.g. "12345-55232"
        $nextRangeExploded = explode('-', $nextRange); // e.g. ["12345", "55232"]

        $this->largeFileUploadProgress = $nextRangeExploded[0];

        // Upload not complete yet, return false.
        return false;
    }

    // Write methods
    public function write($path, $contents, array $config = [])
    {

        // Attempt to write to sharepoint
        try {
            $driveItem = $this->graph->createRequest('PUT', '/me/drive/root:/' . $path . ':/content')
                ->attachBody($contents)
                ->addHeaders(isset($config['content-type']) ? array("Content-Type" => $config['content-type']) : [])
                ->setReturnType(Model\DriveItem::class)
                ->execute();
            // Successfully created
            return true;
        } catch (Exception $e) {
            throw $e;
        }

    }

    public function download($path, $name = null, array $headers = [])
    {




        $driveItem = $this->graph->createRequest('GET', $this->prefix . 'root:/' . $path)
            ->setReturnType(Model\DriveItem::class)
            ->execute();
        $finalPath = null;
        //Find the first non-folder resource to download
        if ($driveItem->getFile()) {

            $itemId = $driveItem->getId();
            $itemName = $driveItem->getName();
//            $itemName = str_replace(" ", "_", $itemName);
//            dd(storage_path('storage/app/graph/'));
//            if(!file_exists(storage_path('storage/app/graph/'))){
//                mkdir();
//            }
//            dd(storage_path('storage/app/graph/'));
            Storage::disk('local')->makeDirectory('graph');
            $finalPath = 'storage/app/graph/'.$itemName;
//            dd($finalPath);
            $driveItemContent = $this->graph->createRequest("GET", "/me/drive/items/$itemId/content")
                ->download($finalPath);


        }

        return $finalPath;
    }

    public function writeStream($path, $resource, array $config = [])
    {

    }

    public function update($path, $contents, array $config = [])
    {

    }

    public function updateStream($path, $resource, array $config = [])
    {

    }

    public function rename($path, $newpath)
    {

    }

    public function copy($path, $newpath)
    {

    }

    public function delete($path)
    {
        if ($this->mode == self::MODE_ONEDRIVE) {
            try {
                $drive = $this->graph->createRequest('GET', $this->prefix . 'root:/' . $path)
                    ->setReturnType(Model\Drive::class)
                    ->execute();

//               dd($this->prefix . $drive->getId());
                // Successfully retrieved meta data.
                // Now delete the file
                $this->graph->createRequest('DELETE', $this->prefix . 'items/' . $drive->getId())
                    ->execute();
                return true;
            } catch (ClientException $e) {
                if ($e->getCode() == 404) {
                    // Not found, let's return false;
                    return false;
                }
                throw $e;
            } catch (Exception $e) {
                throw $e;
            }
        }
        if ($this->mode == self::MODE_SHAREPOINT) {
            try {
                $driveItem = $this->graph->createRequest('GET', $this->prefix . 'root:/' . $path)
                    ->setReturnType(Model\DriveItem::class)
                    ->execute();
                // Successfully retrieved meta data.
                // Now delete the file
                $this->graph->createRequest('DELETE', $this->prefix . $driveItem->getId())
                    ->execute();
                return true;
            } catch (ClientException $e) {
                if ($e->getCode() == 404) {
                    // Not found, let's return false;
                    return false;
                }
                throw $e;
            } catch (Exception $e) {
                throw $e;
            }
        }
        return false;

    }

    public function deleteDir($dirname)
    {

    }

    public function createDir($dirname, array $config = [])
    {

    }

    public function setVisibility($path, $visibility)
    {

    }

    public function putStream($path, $resource, array $config = [])
    {
//        $this->write($path, $resource, $config);
        $this->uploadLargeItem($path, $resource);

//        // Attempt to write to onedrive
//        try {
//            $driveItem = $this->graph->createRequest('PUT', $this->prefix . 'root:/' . $path . ':/content')
//                ->attachBody($resource)
//                ->setReturnType(Model\DriveItem::class)
//                ->execute();
//            // Successfully created
//            return true;
//        } catch(Exception $e) {
//            throw $e;
//        }
    }

    public function readAndDelete($path)
    {
        // TODO: Implement readAndDelete() method.
    }

    public function addPlugin(PluginInterface $plugin)
    {
        // TODO: Implement addPlugin() method.
    }
}
