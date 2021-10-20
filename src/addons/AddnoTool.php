<?php
declare(strict_types=1);

namespace think\addons;


use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;
use PhpZip\Exception\ZipException;
use PhpZip\ZipFile;
use think\addons\exception\AddonException;
use think\Exception;
use think\facade\Db;

class AddnoTool
{

    /**
     * 远程下载插件
     *
     * @param string $name 插件名称
     * @param string $url 下载地址
     * @param array $extend 扩展参数
     * @return  string
     */
    public static function download($name, $url, $extend = [])
    {
        $addonsTempDir = self::getAddonsBackupDir();
        $tmpFile       = $addonsTempDir . $name . ".zip";
        try {
            $client   = self::getClient();
            $response = $client->get($url, ['query' => array_merge(['name' => $name], $extend)]);
            $body     = $response->getBody();
            $content  = $body->getContents();
            if (substr($content, 0, 1) === '{') {
                $json = (array)json_decode($content, true);

                //如果传回的是一个下载链接,则再次下载
                if ($json['data'] && isset($json['data']['url'])) {
                    $response = $client->get($json['data']['url']);
                    $body     = $response->getBody();
                    $content  = $body->getContents();
                } else {
                    //下载返回错误，抛出异常
                    throw new AddonException($json['msg'], $json['code'], $json['data']);
                }
            }
        } catch (TransferException $e) {
            throw new Exception("Addon package download failed");
        }

        if ($write = fopen($tmpFile, 'w')) {
            fwrite($write, $content);
            fclose($write);
            return $tmpFile;
        }
        throw new Exception("No permission to write temporary files");
    }

    /**
     * 解压插件
     *
     * @param string $name 插件名称
     * @return  string
     * @throws  Exception
     */
    public static function unzip($name)
    {
        if (!$name) {
            throw new Exception('Invalid parameters');
        }
        $addonsBackupDir = self::getAddonsBackupDir();
        $file            = $addonsBackupDir . $name . '.zip';

        // 打开插件压缩包
        $zip = new ZipFile();
        try {
            $zip->openFile($file);
        } catch (ZipException $e) {
            $zip->close();
            throw new Exception('Unable to open the zip file');
        }

        $dir = self::getAddonDir($name);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755);
        }

        // 解压插件压缩包
        try {
            $zip->extractTo($dir);
        } catch (ZipException $e) {
            throw new Exception('Unable to extract the file');
        } finally {
            $zip->close();
        }
        return $dir;
    }

    /**
     * 检测插件是否完整
     *
     * @param string $name 插件名称
     * @return  boolean
     * @throws  Exception
     */
    public static function check($name)
    {
        if (!$name || !is_dir(ADDON_PATH . $name)) {
            throw new Exception('Addon not exists');
        }
        $addonClass = get_addons_class($name);
        if (!$addonClass) {
            throw new Exception("The addon file does not exist");
        }
        $addon = new $addonClass(app());
        if (!$addon->checkInfo()) {
            throw new Exception("The configuration file content is incorrect");
        }
        return true;
    }

    /**
     * 备份插件
     * @param string $name 插件名称
     * @return bool
     * @throws Exception
     */
    public static function backup($name)
    {
        $addonsBackupDir = self::getAddonsBackupDir();
        $file            = $addonsBackupDir . $name . '-backup-' . date("YmdHis") . '.zip';
        $zipFile         = new ZipFile();
        try {
            $zipFile
                ->addDirRecursive(self::getAddonDir($name))
                ->saveAsFile($file)
                ->close();
        } catch (ZipException $e) {

        } finally {
            $zipFile->close();
        }


        return true;
    }

    /**
     * 导入SQL
     *
     * @param string $name 插件名称
     * @return  boolean
     */
    public static function importsql($name)
    {
        $sqlFile = self::getAddonDir($name) . 'install.sql';
        if (is_file($sqlFile)) {
            $lines    = file($sqlFile);
            $templine = '';
            foreach ($lines as $line) {
                if (substr($line, 0, 2) == '--' || $line == '' || substr($line, 0, 2) == '/*') {
                    continue;
                }

                $templine .= $line;
                if (substr(trim($line), -1, 1) == ';') {
                    $templine = str_ireplace('__PREFIX__', config('database.prefix'), $templine); //替换表前缀
                    $templine = str_ireplace('INSERT INTO ', 'INSERT IGNORE INTO ', $templine);   //替换数据插入模式
                    try {
                        Db::getPdo()->exec($templine);
                    } catch (\PDOException $e) {
                        //$e->getMessage();
                    }
                    $templine = '';
                }
            }
        }
        return true;
    }

    /**
     * 安装插件
     *
     * @param string $name 插件名称
     * @param string $url 下载地址
     * @param array $extend 扩展参数
     * @return  boolean
     * @throws  Exception
     * @throws  AddonException
     */
    public static function install($name, $url, $extend = [])
    {
        if (!$name || (is_dir(ADDON_PATH . $name))) {
            throw new Exception('Addon already exists');
        }

        // 远程下载插件
        $tmpFile = self::download($name, $url, $extend);

        $addonDir = self::getAddonDir($name);

        try {
            // 解压插件压缩包到插件目录
            self::unzip($name);

            // 检查插件是否完整
            self::check($name);

        } catch (AddonException $e) {
            @rmdirs($addonDir);
            throw new AddonException($e->getMessage(), $e->getCode(), $e->getData());
        } catch (Exception $e) {
            @rmdirs($addonDir);
            throw new Exception($e->getMessage());
        } finally {
            // 移除临时文件
            @unlink($tmpFile);
        }

        // 默认启用该插件
        $info = get_addons_info($name);

        Db::startTrans();
        try {
            if (!$info['status']) {
                $info['status'] = 1;
                set_addons_info($name, $info);
            }

            // 执行安装脚本
            $class = get_addons_class($name);
            if (class_exists($class)) {
                $addon = new $class(app());
                $addon->install();
            }

            //导入sql
            self::importsql($name);

            Db::commit();
        } catch (Exception $e) {
            @rmdirs($addonDir);
            Db::rollback();
            throw new Exception($e->getMessage());
        }

        // 启用插件
        self::enable($name, true);

        $addonDir        = self::getAddonDir($name);
        $sourceAssetsDir = self::getSourceStaticDir($name);
        $destAssetsDir   = self::getDestStaticDir();

        // 复制文件
        if (is_dir($sourceAssetsDir)) {
            copydirs($sourceAssetsDir, $destAssetsDir);
        }

        // 复制app
        if (is_dir($addonDir . "app")) {
            copydirs($addonDir . "app", root_path() . "app");
        }

        $info['config'] = get_addons_config($name) ? 1 : 0;
        return $info;
    }

    /**
     * 卸载插件
     * @param $name
     * @return bool
     * @throws Exception
     */
    public static function uninstall($name)
    {
        if (!$name || !is_dir(ADDON_PATH . $name)) {
            throw new Exception('Addon not exists');
        }

        // 执行卸载脚本
        try {
            $class = get_addons_class($name);
            if (class_exists($class)) {
                $addon = new $class(app());
                $addon->uninstall();
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }

        $addonDir        = self::getAddonDir($name);
        $sourceAssetsDir = self::getSourceStaticDir($name);
        $destAssetsDir   = self::getDestStaticDir();


        // 删除资源文件
        if (is_dir($sourceAssetsDir)) {
            removedirs($sourceAssetsDir, $destAssetsDir);
        }

        // 删除app文件
        if (is_dir($addonDir . "app")) {
            removedirs($addonDir . "app", root_path() . "app");
        }


        // 移除插件目录
        @rmdirs(ADDON_PATH . $name);

        return true;
    }

    /**
     * 升级插件
     *
     * @param string $name 插件名称
     * @param string $rul 插件下载地址
     * @param array $extend 扩展参数
     */
    public static function upgrade($name, $url, $extend = [])
    {
        $info = get_addons_info($name);
        if ($info['status']) {
            throw new Exception(lang('Please disable addon first'));
        }
        $config = get_addons_config($name);

        // 远程下载插件
        $tmpFile = self::download($name, $url, $extend);

        // 备份插件文件
        self::backup($name);

        $addonDir = self::getAddonDir($name);

        try {
            // 解压插件
            self::unzip($name);
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        } finally {
            // 移除临时文件
            @unlink($tmpFile);
        }

        if ($config) {
            // 还原配置
            set_addons_config($name, $config);
        }

        // 导入
        Db::startTrans();
        try {
            //导入sql
            self::importsql($name);

            Db::commit();
        } catch (Exception $e) {
            @rmdirs($addonDir);
            Db::rollback();
            throw new Exception($e->getMessage());
        }

        // 执行升级脚本
        try {
            $addonName = ucfirst($name);
            //创建临时类用于调用升级的方法
            $sourceFile = $addonDir . "Plugin.php";
            $destFile   = $addonDir . "PluginUpgrade.php";

            $classContent = str_replace("class Plugin extends", "class PluginUpgrade extends", file_get_contents($sourceFile));

            //创建临时的类文件
            file_put_contents($destFile, $classContent);

            $className = "\\addons\\" . $name . "\\" . "PluginUpgrade";
            $addon     = new $className(app());

            //调用升级的方法
            if (method_exists($addon, "upgrade")) {
                $addon->upgrade();
            }

            //移除临时文件
            @unlink($destFile);
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }

        $addonDir        = self::getAddonDir($name);
        $sourceAssetsDir = self::getSourceStaticDir($name);
        $destAssetsDir   = self::getDestStaticDir();

        // 复制文件
        if (is_dir($sourceAssetsDir)) {
            copydirs($sourceAssetsDir, $destAssetsDir);
        }

        // 复制app
        if (is_dir($addonDir . "app")) {
            copydirs($addonDir . "app", root_path() . "app");
        }

        //必须变更版本号
        $info['version'] = isset($extend['version']) ? $extend['version'] : $info['version'];
        $info['config']  = get_addons_config($name) ? 1 : 0;
        return $info;
    }


    /**
     * 启用
     * @param string $name 插件名称
     * @return  boolean
     */
    public static function enable($name)
    {
        if (!$name || !is_dir(ADDON_PATH . $name)) {
            throw new Exception('Addon not exists');
        }

        //执行启用脚本
        try {
            $class = get_addons_class($name);
            if (class_exists($class)) {
                $addon = new $class(app());
                if (method_exists($class, "enable")) {
                    $addon->enable();
                }
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }

        $info = get_addons_info($name);

        $info['status'] = 1;
        unset($info['url']);

        set_addons_info($name, $info);

        return true;
    }

    /**
     * 禁用
     *
     * @param string $name 插件名称
     * @param boolean $force 是否强制禁用
     * @return  boolean
     * @throws  Exception
     */
    public static function disable($name)
    {
        if (!$name || !is_dir(ADDON_PATH . $name)) {
            throw new Exception('Addon not exists');
        }

        $info           = get_addons_info($name);
        $info['status'] = 0;
        unset($info['url']);

        set_addons_info($name, $info);

        // 执行禁用脚本
        try {
            $class = get_addons_class($name);
            if (class_exists($class)) {
                $addon = new $class(app());
                if (method_exists($class, "disable")) {
                    $addon->disable();
                }
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }

        return true;
    }

    /**
     * 获取指定插件的目录
     */
    public static function getAddonDir($name)
    {
        $dir = ADDON_PATH . $name . DS;
        return $dir;
    }

    /**
     * 获取插件源资源文件夹
     * @param string $name 插件名称
     * @return  string
     */
    protected static function getSourceStaticDir($name)
    {
        return ADDON_PATH . $name . DS . 'static';
    }

    /**
     * 获取插件目标资源文件夹
     * @param string $name 插件名称
     * @return  string
     */
    protected static function getDestStaticDir()
    {
        $assetsDir = root_path() . str_replace("/", DS, "public/static");
        return $assetsDir;
    }

    /**
     * 获取插件备份目录
     */
    public static function getAddonsBackupDir()
    {
        $dir = app()->getRuntimePath() . 'addons' . DS;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /**
     * 获取请求对象
     * @return Client
     */
    protected static function getClient()
    {
        $options = [
            'timeout' => 30,
            'connect_timeout' => 30,
            'verify' => false,
            'http_errors' => false,
            'headers' => [
                'X-REQUESTED-WITH' => 'XMLHttpRequest',
            ]
        ];
        static $client;
        if (empty($client)) {
            $client = new Client($options);
        }
        return $client;
    }
}