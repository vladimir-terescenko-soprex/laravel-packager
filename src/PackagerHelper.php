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
     * Creates folder structure that is set in $defaultFolders property
     *
     * @param string $folderPath
     * @param null $subFolders
     */
    public function createFolderStructure($folderPath, $subFolders = null)
    {
        $foldersToLoop = $subFolders ? $subFolders : $this->defaultFolders;
        foreach($foldersToLoop as $key => $folder) {
            if (is_numeric($key)) {
                $this->makeDir($folderPath . $folder);
            } else {
                $folderSubPath = $folderPath;
                foreach($folder as $subKey => $subFolder) {
                    if (is_numeric($subKey)) {
                        $folderSubPath .= $key . '/' . $subFolder;
                        $this->makeDir($folderSubPath);
                    } else {
                        $this->createFolderStructure($folderPath . '/'. $key .'/' . $subKey . '/', $subFolder);
                    }
                }
            }
        }
    }

    /**
     * Creates resource controller file from resource_controller_template.txt file
     *
     * @param string $path
     * @param string $packageName
     * @param string $nameSpace
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
     * Creates facade class file from facade_template.txt
     *
     * @param string $path
     * @param string $packageName
     * @param string $nameSpace
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
     * Creates config file from config_template.txt
     *
     * @param string $path
     * @param string $packageName
     * @return int
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    public function createConfigFile($path, $packageName)
    {
        $configTemplate = $this->files->get($this->templatesPath . 'config_template.txt');

        $this->files->put($path . '/' . strtolower($packageName) . '.php', $configTemplate);
        $this->files->put($path . '/' . '.gitkeep', '');
    }

    /**
     * Creates repository class form repository_template.txt
     *
     * @param string $path
     * @param string $packageName
     * @param string $nameSpace
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    public function createRepositoryClass($path, $packageName, $nameSpace)
    {
        $repositoryTemplate = $this->files->get($this->templatesPath . 'repository_template.txt');
        $search = [':package_name:', ':repository_namespace:'];
        $replace = [$packageName, $nameSpace];
        $repositoryTemplateChanged = str_replace($search, $replace, $repositoryTemplate);

        $this->files->put($path . '/' . $packageName . 'Repository.php', $repositoryTemplateChanged);
    }

    /**
     * Creating .gitkeep file inside migrations folder
     *
     * @param string $path
     * @return int
     */
    public function fillMigrationsDirectory($path)
    {
        return $this->files->put($path . '/migrations/' . '.gitkeep', '');
    }

    /**
     * Creates empty routes file from routes_template.txt
     *
     * @param string $path
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    public function createRoutesFile($path)
    {
        $routesTemplate = $this->files->get($this->templatesPath . 'routes_template.txt');

        $this->files->put($path . '/routes.php', $routesTemplate);
    }

    /**
     * Creates service provider class from service_provider_template.txt
     *
     * @param string $path
     * @param string $packageName
     * @param string $nameSpace
     * @param string $controllersNamespace
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    public function createServiceProvidesClass($path, $packageName, $nameSpace, $controllersNamespace)
    {
        $serviceProviderTemplate = $this->files->get($this->templatesPath . 'service_provider_template.txt');
        $search = [
            ':service_provider_namespace:',
            ':package_name:',
            ':config_file:',
            ':controllers_namespace:'
        ];
        $replace = [
            $nameSpace,
            $packageName,
            strtolower($packageName),
            $controllersNamespace
        ];
        $serviceProviderTemplateChanged = str_replace($search, $replace, $serviceProviderTemplate);

        $this->files->put($path . '/' . $packageName . 'ServiceProvider.php', $serviceProviderTemplateChanged);
    }

    /**
     * Replaces default Skeleton class with package name class
     *
     * @param string $path
     * @param string $packageName
     * @param string $nameSpace
     * @return bool
     */
    public function replaceSkeletonClassWithPackageNameClass($path, $packageName, $nameSpace)
    {
        $skeletonReplaceTemplate = $this->files->get($this->templatesPath . 'skeleton_replace_template.txt');
        $search = [':namespace:', ':class_name:'];
        $replace = [$nameSpace, $packageName];
        $skeletonReplaceTemplateChanged = str_replace($search, $replace, $skeletonReplaceTemplate);
        $this->files->put($path . '/' . 'SkeletonClass.php', $skeletonReplaceTemplateChanged);

        rename($path . '/' . 'SkeletonClass.php', $path . '/' . $packageName . '.php');
    }
}