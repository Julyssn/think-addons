<?php
/**
 * Created by PhpStorm
 * User Julyssn
 * Date 2021/8/9 15:41
 */


namespace think\addons\command;


use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\Output;

class PackAddon extends Command
{
    public function configure()
    {
        $this->setName('addons:pack')
             ->addArgument('name', Argument::REQUIRED, "应用名称")
             ->setDescription('Pack Addon');
    }

    public function execute(Input $input, Output $output)
    {
        $name = trim($input->getArgument('name'));

        $addonDir = ADDON_PATH . $name . DS;
        $publicDir = public_path() . 'static' . DS;

        $infoFile = $addonDir . 'info.ini';
        if (!is_file($infoFile)) {
            throw new \RuntimeException('Addon info file was not found');
        }

        $info = get_addons_info($name);
        if (!$info) {
            throw new \RuntimeException('Addon info file data incorrect');
        }


        $infoName = isset($info['name']) ? $info['name'] : '';
        if (!$infoName || !preg_match("/^[a-z]+$/i", $infoName) || $infoName != $name) {
            throw new \RuntimeException('Addon info name incorrect');
        }

        $infoVersion = isset($info['version']) ? $info['version'] : '';
        if (!$infoVersion || !preg_match("/^\d+\.\d+\.\d+$/i", $infoVersion)) {
            throw new \RuntimeException('Addon info version incorrect');
        }

        $addonTmpDir = RUNTIME_PATH;

        if (!is_dir($addonTmpDir)) {
            @mkdir($addonTmpDir, 0755, true);
        }
        $addonFile = $addonTmpDir . $infoName . '-' . $infoVersion . '.zip';
        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException('ZinArchive not install');
        }

        $zip = new \ZipArchive;
        $zip->open($addonFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($addonDir), \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $fileName => $file) {

            if (!$file->isDir()) {
                $filePath     = $file->getRealPath();
                $relativePath = str_replace(DS, '/', substr($filePath, strlen($addonDir)));
                if (!in_array($file->getFilename(), ['.git', '.DS_Store', 'Thumbs.db'])) {
                    $zip->addFile($filePath, $relativePath);
                }
            }
        }

        //添加静态资源
        $addonPublicDir = RUNTIME_PATH . $name . DS;
        $publicFiles    = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($addonPublicDir), \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($publicFiles as $fileName => $file) {
            if (!$file->isDir()) {
                $filePath     = $file->getRealPath();
                $relativePath = str_replace(DS, '/', 'static' . DS . substr($filePath, strlen($publicDir)));
                if (!in_array($file->getFilename(), ['.git', '.DS_Store', 'Thumbs.db'])) {
                    $zip->addFile($filePath, $relativePath);
                }
            }
        }

        $zip->close();
        $output->writeln("Package succeeded! File path: " . PHP_EOL . $addonFile);
    }

}