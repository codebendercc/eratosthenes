<?php
/**
 * Created by PhpStorm.
 * User: fpapadopou
 * Date: 1/28/15
 * Time: 11:54 AM
 */

namespace Codebender\LibraryBundle\Handler;

use Codebender\LibraryBundle\Entity\Example;
use Codebender\LibraryBundle\Entity\ExternalLibrary;
use Doctrine\ORM\EntityManager;
use Symfony\Component\Finder\Finder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

class DefaultHandler
{

    protected $entityManager;
    protected $container;

    function __construct(EntityManager $entityManager, ContainerInterface $containerInterface)
    {
        $this->entityManager = $entityManager;
        $this->container = $containerInterface;
    }

    public function getLibraryCode($library, $disabled, $renderView = false)
    {
        $arduino_library_files = $this->container->getParameter('arduino_library_directory') . "/";

        $finder = new Finder();
        $exampleFinder = new Finder();

        if ($disabled != 1)
            $getDisabled = false;
        else
            $getDisabled = true;


        $filename = $library;
        $directory = "";

        $last_slash = strrpos($library, "/");
        if ($last_slash !== false) {
            $filename = substr($library, $last_slash + 1);
            $vendor = substr($library, 0, $last_slash);
        }

        //TODO handle the case of different .h filenames and folder names
        if ($filename == "ArduinoRobot")
            $filename = "Robot_Control";
        else if ($filename == "ArduinoRobotMotorBoard")
            $filename = "Robot_Motor";

        $exists = json_decode($this->checkIfBuiltInExists($filename), true);

        if ($exists["success"]) {
            $response = $this->fetchLibraryFiles($finder, $arduino_library_files . "/libraries/" . $filename);

            if ($renderView) {
                $examples = $this->fetchLibraryExamples($exampleFinder, $arduino_library_files . "/libraries/" . $filename);
                $meta = array();
            }
        } else {
            $response = json_decode($this->checkIfExternalExists($filename, $getDisabled), true);
            if (!$response['success']) {
                return new Response(json_encode($response));
            } else {
                $response = $this->fetchLibraryFiles($finder, $arduino_library_files . "/external-libraries/" . $filename);
                if (empty($response))
                    return new Response(json_encode(array("success" => false, "message" => "No files for Library named " . $library . " found.")));
                if ($renderView) {
                    $examples = $this->fetchLibraryExamples($exampleFinder, $arduino_library_files . "/external-libraries/" . $filename);

                    $libmeta = $this->entityManager->getRepository('CodebenderLibraryBundle:ExternalLibrary')->findBy(array('machineName' => $filename));
                    $filename = $libmeta[0]->getMachineName();
                    $meta = array("humanName" => $libmeta[0]->getHumanName(), "description" => $libmeta[0]->getDescription(), "verified" => $libmeta[0]->getVerified(), "gitOwner" => $libmeta[0]->getOwner(), "gitRepo" => $libmeta[0]->getRepo(), "url" => $libmeta[0]->getUrl(), "active" => $libmeta[0]->getActive());

                }
            }
        }
        if (!$renderView)
            return new Response(json_encode(array("success" => true, "message" => "Library found", "files" => $response)));
        else {

            return new Response(json_encode(array(
                "success" => true,
                "library" => $filename,
                "files" => $response,
                "examples" => $examples,
                "meta" => $meta
            )));
        }
    }

    public function checkGithubUpdates()
    {
        $needToUpdate = array();
        $libraries = $this->entityManager->getRepository('CodebenderLibraryBundle:ExternalLibrary')->findAll();

        foreach ($libraries as $lib) {
            $gitOwner = $lib->getOwner();
            $gitRepo = $lib->getRepo();

            if ($gitOwner !== null and $gitRepo !== null) {
                $lastCommitFromGithub = $this->getLastCommitFromGithub($gitOwner, $gitRepo);
                if ($lastCommitFromGithub !== $lib->getLastCommit())
                    $needToUpdate[] = array('Machine Name' => $lib->getMachineName(), "Human Name" => $lib->getHumanName(), "Git Owner" => $lib->getOwner(), "Git Repo" => $lib->getRepo());
            }
        }
        if (empty($needToUpdate))
            $response = array("success" => true, "message" => "No Libraries need to update");
        else
            $response = array("success" => true, "message" => "There are Libraries that need to update", "libraries" => $needToUpdate);

        return new Response(json_encode($response));
    }

    public function getLastCommitFromGithub($gitOwner, $gitRepo)
    {
        $client_id = $this->container->getParameter('github_app_client_id');
        $client_secret = $this->container->getParameter('github_app_client_secret');
        $github_app_name = $this->container->getParameter('github_app_name');
        $url = "https://api.github.com/repos/" . $gitOwner . "/" . $gitRepo . "/commits" . "?client_id=" . $client_id . "&client_secret=" . $client_secret;
        $json_contents = json_decode($this->curlRequest($url, null, array('User-Agent: ' . $github_app_name)), true);

        return $json_contents[0]['sha'];
    }

    public function checkIfBuiltInExists($library)
    {
        $arduino_library_files = $this->container->getParameter('arduino_library_directory') . "/";
        if (is_dir($arduino_library_files . "/libraries/" . $library))
            return json_encode(array("success" => true, "message" => "Library found"));
        else
            return json_encode(array("success" => false, "message" => "No Library named " . $library . " found."));
    }

    public function checkIfExternalExists($library, $getDisabled = false)
    {
        $lib = $this->entityManager->getRepository('CodebenderLibraryBundle:ExternalLibrary')->findBy(array('machineName' => $library));
        if (empty($lib) || (!$getDisabled && !$lib[0]->getActive())) {
            return json_encode(array("success" => false, "message" => "No Library named " . $library . " found."));
        } else {
            return json_encode(array("success" => true, "message" => "Library found"));
        }

    }

    public function fetchLibraryFiles($finder, $directory, $getContent = true)
    {
        if (!is_dir($directory)) {
            return array();
        }

        $finder->in($directory)->exclude('examples')->exclude('Examples');
        // Left this here, just in case we need it again.
        // $finder->name('*.cpp')->name('*.h')->name('*.c')->name('*.S')->name('*.inc')->name('*.txt');
        $finder->name('*.*');

        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        $response = array();
        foreach ($finder as $file) {
            if ($getContent) {
                $mimeType = finfo_file($finfo, $file);
                if (strpos($mimeType, "text/") === false)
                    $content = "/*\n *\n * We detected that this is not a text file.\n * Such files are currently not supported by our editor.\n * We're sorry for the inconvenience.\n * \n */";
                else
                    $content = (!mb_check_encoding($file->getContents(), 'UTF-8')) ? mb_convert_encoding($file->getContents(), "UTF-8") : $file->getContents();
                $response[] = array("filename" => $file->getRelativePathname(), "content" => $content);
            } else
                $response[] = array("filename" => $file->getRelativePathname());
        }
        return $response;
    }

    public function fetchLibraryExamples($finder, $directory)
    {
        if (is_dir($directory)) {
            $finder->in($directory);
            $finder->name('*.pde')->name('*.ino');

            $response = array();
            foreach ($finder as $file) {
                $response[] = array("filename" => $file->getRelativePathname(), "content" => (!mb_check_encoding($file->getContents(), 'UTF-8')) ? mb_convert_encoding($file->getContents(), "UTF-8") : $file->getContents());
            }

            return $response;
        }

    }

    public function getLibFromGithub($owner, $repo, $branch, $folder, $onlyMeta = false)
    {

        $processedDirectory = json_decode($this->processGitDir($owner, $repo, $branch, $folder, $onlyMeta), true);

        if (!$processedDirectory['success']) {
            return json_encode($processedDirectory);
        }

        $dir = $processedDirectory['directory'];

        /*
         * Get the root directory of the repo
         */
        $baseDirectory = json_decode($this->findBaseDir($dir), true);
        if (!$baseDirectory['success']) {
            return json_encode($baseDirectory);
        }

        $baseDir = $baseDirectory['directory'];

        return json_encode(array("success" => true, "library" => $baseDir));
    }

    private function processGitDir($owner, $repo, $branch, $path, $onlyMeta = false)
    {

        $clientId = $this->container->getParameter('github_app_client_id');
        $clientSecret = $this->container->getParameter('github_app_client_secret');
        $githubAppName = $this->container->getParameter('github_app_name');
        $currentUrl = "https://api.github.com/repos/$owner/$repo/git/trees/$branch";

        $currentUrl = $currentUrl . "?recursive=1&client_id=$clientId&client_secret=$clientSecret";

        /*
         * See the docs here https://developer.github.com/v3/git/trees/
         * for more info on the json returned.
         * Note: Not sure if setting the User-Agent is necessary
         */
        $gitResponse = json_decode($this->curlRequest($currentUrl, null, array('User-Agent: ' . $githubAppName)), true);

        if (array_key_exists('message', $gitResponse)) {
            return json_encode(array('success' => false, 'message' => $gitResponse['message']));
        }
        // TODO: Could try some recursive call to all tree nodes of the response, instead of just quitting
        if ($gitResponse['truncated'] !== false) {
            return json_encode(array('success' => false, 'message' => 'Truncated data. Try using a subtree of the repo'));
        }

        /*
         * The value of `tree` key in the response contains all the files
         * and their metadata.
         * If a specific folder of the repo is requested, only paths
         * matching the folder will be returned.
         */
        $filePaths = array();
        /*
         * Only `blobs` are valid files, as a result we need to filter the `tree` values out of the array
         */
        $gitResponse['tree'] = array_filter($gitResponse['tree'], function ($file) {if ($file['type'] != 'blob') {return false;} return true;});
        foreach ($gitResponse['tree'] as $file) {

            if ($path != '' && strpos($file['path'], $path) === false) {
                continue;
            }
            $filePaths[] = $file['path'];
        }

        $directory = $owner . '/' . $repo;
        if ($path != '') {
            $directory .= '/' . $path;
        }
        return json_encode(array('success' => true, 'name' => $directory, 'type' => 'dir', 'contents' => $filePaths));
    }

    private function processGitFile($baseurl, $file, $onlyMeta = false)
    {
        if (!$onlyMeta) {
            $client_id = $this->container->getParameter('github_app_client_id');
            $client_secret = $this->container->getParameter('github_app_client_secret');
            $github_app_name = $this->container->getParameter('github_app_name');
            $url = ($baseurl . "/" . $file['path']) . "?client_id=" . $client_id . "&client_secret=" . $client_secret;

            $contents = $this->curlRequest($url, null, array('Accept: application/vnd.github.v3.raw', 'User-Agent: ' . $github_app_name));
            $json_contents = json_decode($contents, true);

            if ($json_contents === null) {
                if (!mb_check_encoding($contents, 'UTF-8'))
                    $contents = mb_convert_encoding($contents, 'UTF-8');

                return json_encode(array("success" => true, "file" => array("name" => $file['name'], "type" => "file", "contents" => $contents)));
            } else {
                return json_encode(array("success" => false, "message" => $json_contents['message']));
            }
        } else {
            return json_encode(array("success" => true, "file" => array("name" => $file['name'], "type" => "file")));
        }
    }

    public function findBaseDir($dir)
    {
        foreach ($dir['contents'] as $file) {
            if ($file['type'] == 'file' && strpos($file['name'], ".h") !== false)
                return json_encode(array('success' => true, 'directory' => $dir));

        }

        foreach ($dir['contents'] as $file) {
            if ($file['type'] == 'dir') {
                foreach ($file['contents'] as $f) {
                    if ($f['type'] == 'file' && strpos($f['name'], ".h") !== false) {
                        $file = $this->fixDirName($file);
                        return json_encode(array('success' => true, 'directory' => $file));
                    }
                }
            }
        }
    }

    private function fixDirName($dir)
    {
        foreach ($dir['contents'] as &$f) {
            if ($f['type'] == 'dir') {
                $first_slash = strpos($f['name'], "/");
                $f['name'] = substr($f['name'], $first_slash + 1);
                $f = $this->fixDirName($f);
            }
        }
        return $dir;
    }


    public function curlRequest($url, $post_request_data = null, $http_header = null)
    {
        $curl_req = curl_init();
        curl_setopt_array($curl_req, array(
            CURLOPT_URL => $url,
            CURLOPT_HEADER => 0,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ));
        if ($post_request_data !== null)
            curl_setopt($curl_req, CURLOPT_POSTFIELDS, $post_request_data);

        if ($http_header !== null)
            curl_setopt($curl_req, CURLOPT_HTTPHEADER, $http_header);

        $contents = curl_exec($curl_req);

        curl_close($curl_req);
        return $contents;
    }

    public function processGithubUrl($url)
    {
        $urlParts = parse_url($url);
        /*
         * If hostname is other than github.com, the url is invalid
         */
        if ($urlParts['host'] != 'github.com') {
            return array('success' => false);
        }

        $path = $urlParts['path'];
        if ($path == '') {
            return array('success' => false);
        }

        $path = $this->cleanPrependingSlash($path);
        $pathComponents = explode('/', $path);

        $owner = $pathComponents[0]; // The first part of the path is always the author
        $repo = $pathComponents[1]; // The next part of the path is always the repo name
        $folder = str_replace("$owner/$repo", '', $path); // Return the rest of the path, if any

        $folder = $this->cleanPrependingSlash($folder);

        $branch = 'master';
        if (preg_match("/tree\/(\w+)\//", $path, $matches)) {
            $branch = $matches[1];
            $folder = str_replace("tree/$branch", '', $folder);
        }

        $folder = $this->cleanPrependingSlash($folder);

        return array('success' => true, 'owner' => $owner, 'repo' => $repo, 'branch' => $branch, 'folder' => $folder);
    }

    private function cleanPrependingSlash($path)
    {
        if (substr($path, 0, 1) == '/') {
            $path = substr($path, 1);
        }

        return $path;
    }
}