<?php

namespace JeroenG\Packager;

use ZipArchive;
use RuntimeException;
use GuzzleHttp\Client;
use Illuminate\Filesystem\Filesystem;

/**
 * Helper functions for the Packager commands.
 *
 * @package Packager
 * @author JeroenG
 * 
 **/
class PackagerHelper
{
    /**
     * Default folders
     * @var array
     */
    protected $defaultFolders = [
        'Controllers',
        'Facades',
        'Models',
        'Repositories',
        'config',
        'database' => ['migrations'],
        'resources' => [
            'assets' => ['js', 'sass'],
            'views' => ['elements'],
        ],
    ];

    /**
     * @var string
     */
    protected $templatesPath = __DIR__ . '/templates/';

    /**
     * The filesystem handler.
     * @var object
     */
    protected $files;

    /**
     * Create a new instance.
     * @param Illuminate\Filesystem\Filesystem $files
     */
    public function __construct(Filesystem $files)
    {
        $this->files = $files;
    }

    /**
     * Setting custom formatting for the progress bar.
     *
     * @param  object $bar Symfony ProgressBar instance
     *
     * @return object $bar Symfony ProgressBar instance
     */
    public function barSetup($bar)
    {
        // the finished part of the bar
        $bar->setBarCharacter('<comment>=</comment>');

        // the unfinished part of the bar
        $bar->setEmptyBarCharacter('-');

        // the progress character
        $bar->setProgressCharacter('>');

        // the 'layout' of the bar
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% ');

        return $bar;
    }

    /**
     * Open haystack, find and replace needles, save haystack.
     *
     * @param  string $oldFile The haystack
     * @param  mixed  $search  String or array to look for (the needles)
     * @param  mixed  $replace What to replace the needles for?
     * @param  string $newFile Where to save, defaults to $oldFile
     *
     * @return void
     */
    public function replaceAndSave($oldFile, $search, $replace, $newFile = null)
    {
        $newFile = ($newFile == null) ? $oldFile : $newFile;
        $file = $this->files->get($oldFile);
        $replacing = str_replace($search, $replace, $file);
        $this->files->put($newFile, $replacing);
    }

    /**
     * Check if the package already exists.
     *
     * @param  string $path   Path to the package directory
     * @param  string $vendor The vendor
     * @param  string $name   Name of the package
     *
     * @return void          Throws error if package exists, aborts process
     */
    public function checkExistingPackage($path, $vendor, $name)
    {
        if (is_dir($path.$vendor.'/'.$name)) {
            throw new RuntimeException('Package already exists');
        }
    }

    /**
     * Create a directory if it doesn't exist.
     *
     * @param  string $path Path of the directory to make
     *
     * @return void
     */
    public function makeDir($path)
    {
        if (!is_dir($path)) {
            return mkdir($path, 0777, true);
        }
    }

    /**
     * Remove a directory if it exists.
     *
     * @param  string $path Path of the directory to remove.
     *
     * @return void
     */
    public function removeDir($path)
    {
        if ($path == 'packages' or $path == '/') {
            return false;
        }

        $files = array_diff(scandir($path), ['.', '..']);
        foreach ($files as $file) {
            if (is_dir("$path/$file")) {
                $this->removeDir("$path/$file");
            } else {
                @chmod("$path/$file", 0777);
                @unlink("$path/$file");
            }

        }
        return rmdir($path);
    }

    /**
     * Generate a random temporary filename for the package zipfile.
     *
     * @return string
     */
    public function makeFilename()
    {
        return getcwd().'/package'.md5(time().uniqid()).'.zip';
    }

    /**
     * Download the temporary Zip to the given file.
     *
     * @param  string  $zipFile
     * @param  string  $source
     *
     * @return $this
     */
    public function download($zipFile, $source)
    {
        $client = new Client(['verify' => env('CURL_VERIFY', true)]);
        $response = $client->get($source);
        file_put_contents($zipFile, $response->getBody());
        return $this;
    }

    /**
     * Extract the zip file into the given directory.
     *
     * @param  string  $zipFile
     * @param  string  $directory
     *
     * @return $this
     */
    public function extract($zipFile, $directory)
    {
        $archive = new ZipArchive;
        $archive->open($zipFile);
        $archive->extractTo($directory);
        $archive->close();
        return $this;
    }

    /**
     * Clean-up the Zip file.
     *
     * @param  string  $zipFile
     *
     * @return $this
     */
    public function cleanUp($zipFile)
    {
        @chmod($zipFile, 0777);
        @unlink($zipFile);
        return $this;
    }

    /**
     * @param $folderPath
     */
    public function createFolderStructure($folderPath)
    {
//        dd($this->defaultFolders);
        foreach($this->defaultFolders as $key => $folder) {
            if (is_numeric($key)) {
                $this->makeDir($folderPath . $folder);
            } else {
                $folderSubPath = $folderPath;
                foreach($folder as $subFolder) {
                    if (is_array($subFolder) && !is_numeric($subFolder[0])) {
                        $this->createFolderStructure($folderSubPath);
                    } else {
                        $this->makeDir($key . '/' . $subFolder);
                    }
//                    $folderSubPath .= $key . '/' . $subFolder;
                }
            }
        }
    }

    /**
     * @param $path
     * @param $packageName
     * @param $nameSpace
     * @return int
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    public function createResourceController($path, $packageName, $nameSpace)
    {
        $controllerTemplate = $this->files->get($this->templatesPath . 'resource_controller_template.txt');
        $search = [':package_name:', ':controller_namespace:'];
        $replace = [$packageName, $nameSpace];
        $controllerTemplateChanged = str_replace($search, $replace, $controllerTemplate);

        $this->files->put($path . '/' . $packageName . 'Controller.php', $controllerTemplateChanged);
    }

    /**
     * @param $path
     * @param $packageName
     * @param $nameSpace
     * @return int
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    public function createFacadeClass($path, $packageName, $nameSpace)
    {
        $facadeTemplate = $this->files->get($this->templatesPath . 'facade_template.txt');
        $search = [':package_name:', ':facade_namespace:'];
        $replace = [$packageName, $nameSpace];
        $facadeTemplateChanged = str_replace($search, $replace, $facadeTemplate);

        $this->files->put($path . '/' . $packageName . '.php', $facadeTemplateChanged);
    }

    /**
     * @param $path
     * @param $packageName
     * @return int
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    public function createConfigFile($path, $packageName)
    {
        $configTemplate = $this->files->get($this->templatesPath . 'config_template.txt');

        $this->files->put($path . '/' . strtolower($packageName) . '.php', $configTemplate);
        $this->files->put($path . '/' . '.gitkeep', '');
    }

    public function createRepositoryClass($path, $packageName, $nameSpace)
    {
        $repositoryTemplate = $this->files->get($this->templatesPath . 'repository_template.txt');
        $search = [':package_name:', ':repository_namespace:'];
        $replace = [$packageName, $nameSpace];
        $repositoryTemplateChanged = str_replace($search, $replace, $repositoryTemplate);

        $this->files->put($path . '/' . $packageName . 'Repository.php', $repositoryTemplateChanged);
    }

    public function fillMigrationsDirectory($path)
    {
        return $this->files->put($path . '/migrations/' . '.gitkeep', '');
    }
}