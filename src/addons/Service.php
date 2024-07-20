<?php

// +----------------------------------------------------------------------
// | 插件服务类
// | https://github.com/5ini99/think-addons
// +----------------------------------------------------------------------
namespace think\addons;

use PhpZip\Exception\ZipException;
use PhpZip\ZipFile;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use think\Exception;
use think\facade\Cache;
use think\facade\Config;
use think\facade\Db;
use util\File;
use util\Sql;

class Service
{
    /**
     * 安装插件.
     * @param string $name   插件名称
     * @param boolean $force  是否覆盖
     * @param array   $extend 扩展参数
     * @throws Exception
     * @return bool
     */
    public static function install($name, $force = false, $extend = [])
    {
        if (!$name || (is_dir(ADDON_PATH . $name) && !$force)) {
            throw new Exception('插件已经存在');
        }

        $addonDir = self::getAddonDir($name);

        try {
            // 解压插件压缩包到插件目录
            self::unzip($name);
            // 检查插件是否完整
            self::check($name);
            if (!$force) {
                self::noconflict($name);
            }
        } catch (AddonException $e) {
            @File::del_dir($addonDir);
            throw new AddonException($e->getMessage(), $e->getCode(), $e->getData());
        } catch (Exception $e) {
            @File::del_dir($addonDir);
            throw new Exception($e->getMessage());
        }

        // 默认启用该插件
        $info = get_addon_info($name);
        try {
            $info['init'] = 1;
            set_addon_info($name, $info);
            // 执行安装脚本
            $class = get_addon_class($name);
            if (class_exists($class)) {
                $addon = new $class();
                $addon->install();
            }
        } catch (Exception $e) {
            @File::del_dir($addonDir);
            throw new Exception($e->getMessage());
        }
        self::runSQL($name);
        // 启用插件
        self::enable($name, true);

        $info['config']   = get_addon_config($name) ? 1 : 0;
        $info['testdata'] = is_file(Service::getTestdataFile($name));
        return $info;
    }

    /**
     * 卸载插件.
     * @param string $name
     * @param boolean $force 是否强制卸载
     * @throws Exception
     * @return bool
     */
    public static function uninstall($name, $force = false)
    {
        if (!$name || !is_dir(ADDON_PATH . $name)) {
            throw new Exception('插件不存在！');
        }
        $info = get_addon_info($name);
        if ($info['status'] == 1) {
            throw new Exception('请先禁用插件再进行卸载');
        }
        if (!$force) {
            self::noconflict($name);
        }
        // 移除插件全局资源文件
        if ($force) {
            $list = self::getGlobalFiles($name);
            foreach ($list as $k => $v) {
                @unlink(app()->getRootPath() . $v);
            }
        }
        // 执行卸载脚本
        try {
            $class = get_addon_class($name);
            if (class_exists($class)) {
                $addon = new $class();
                $addon->uninstall();
            };
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
        // 移除插件目录
        File::del_dir(ADDON_PATH . $name);
        // 刷新
        self::refresh();
        return true;
    }

    /**
     * 启用.
     * @param string $name  插件名称
     * @param bool   $force 是否强制覆盖
     * @return bool
     */
    public static function enable($name, $force = false)
    {
        if (!$name || !is_dir(ADDON_PATH . $name)) {
            throw new Exception('插件不存在！');
        }
        if (!$force) {
            self::noconflict($name);
        }

        //备份冲突文件
        if (config::get('yu.backup_global_files')) {
            $conflictFiles = self::getGlobalFiles($name, true);
            if ($conflictFiles) {
                $zip = new ZipFile();
                try {
                    foreach ($conflictFiles as $k => $v) {
                        $zip->addFile(app()->getRootPath() . $v, $v);
                    }
                    $addonsBackupDir = self::getAddonsBackupDir();
                    $zip->saveAsFile($addonsBackupDir . $name . "-conflict-enable-" . date("YmdHis") . ".zip");
                } catch (Exception $e) {

                } finally {
                    $zip->close();
                }
            }
        }

        $addonDir        = self::getAddonDir($name);
        $sourceAssetsDir = self::getSourceAssetsDir($name);
        $destAssetsDir   = self::getDestAssetsDir($name);

        $files = self::getGlobalFiles($name);
        if ($files) {
            //刷新插件配置缓存
            self::config($name, ['files' => $files]);
        }

        if (is_dir($sourceAssetsDir)) {
            File::copy_dir($sourceAssetsDir, $destAssetsDir);
        }

        // 复制application到全局
        foreach (self::getCheckDirs() as $k => $dir) {
            if (is_dir($addonDir . $dir)) {
                File::copy_dir($addonDir . $dir, app()->getRootPath() . $dir);
            }
        }

        // 删除插件目录已复制到全局的文件
        @File::del_dir($sourceAssetsDir);
        foreach (self::getCheckDirs() as $k => $dir) {
            @File::del_dir($addonDir . $dir);
        }

        //执行启用脚本
        try {
            $class = get_addon_class($name);
            if (class_exists($class)) {
                $addon = new $class();
                if (method_exists($class, 'enable')) {
                    $addon->enable();
                }
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }

        $info           = get_addon_info($name);
        $info['status'] = 1;
        set_addon_info($name, $info);

        // 刷新
        self::refresh();
        return true;
    }

    /**
     * 禁用.
     * @param string $name  插件名称
     * @param bool   $force 是否强制禁用
     * @throws Exception
     * @return bool
     */
    public static function disable($name, $force = false)
    {
        if (!$name || !is_dir(ADDON_PATH . $name)) {
            throw new Exception('插件不存在！');
        }
        if (!$force) {
            self::noconflict($name);
        }

        if (config::get('yu.backup_global_files')) {
            //仅备份修改过的文件
            $conflictFiles = self::getGlobalFiles($name, true);
            if ($conflictFiles) {
                $zip = new ZipFile();
                try {
                    foreach ($conflictFiles as $k => $v) {
                        $zip->addFile(app()->getRootPath() . $v, $v);
                    }
                    $addonsBackupDir = self::getAddonsBackupDir();
                    $zip->saveAsFile($addonsBackupDir . $name . "-conflict-disable-" . date("YmdHis") . ".zip");
                } catch (Exception $e) {

                } finally {
                    $zip->close();
                }
            }
        }

        $config = self::config($name);

        $addonDir = self::getAddonDir($name);
        //插件资源目录
        $destAssetsDir = self::getDestAssetsDir($name);

        // 移除插件全局文件
        $list = self::getGlobalFiles($name);

        //插件纯净模式时将原有的文件复制回插件目录
        //当无法获取全局文件列表时也将列表复制回插件目录
        if (!$list) {
            if ($config && isset($config['files']) && is_array($config['files'])) {
                foreach ($config['files'] as $index => $item) {
                    //避免切换不同服务器后导致路径不一致
                    $item = str_replace(['/', '\\'], DS, $item);
                    //插件资源目录，无需重复复制
                    if (stripos($item, str_replace(app()->getRootPath(), '', $destAssetsDir)) === 0) {
                        continue;
                    }
                    //检查目录是否存在，不存在则创建
                    $itemBaseDir = dirname($addonDir . $item);
                    if (!is_dir($itemBaseDir)) {
                        @mkdir($itemBaseDir, 0755, true);
                    }
                    if (is_file(app()->getRootPath() . $item)) {
                        @copy(app()->getRootPath() . $item, $addonDir . $item);
                    }
                }
                $list = $config['files'];
            }
            //复制插件目录资源
            if (is_dir($destAssetsDir)) {
                @File::copy_dir($destAssetsDir, $addonDir . 'assets' . DS);
            }
        }

        $dirs = [];
        foreach ($list as $k => $v) {
            $file   = app()->getRootPath() . $v;
            $dirs[] = dirname($file);
            @unlink($file);
        }

        // 移除插件空目录
        $dirs = array_filter(array_unique($dirs));
        foreach ($dirs as $k => $v) {
            File::remove_empty_folder($v);
        }
        // 执行禁用脚本
        try {
            $class = get_addon_class($name);
            if (class_exists($class)) {
                $addon = new $class();

                if (method_exists($class, 'disable')) {
                    $addon->disable();
                }
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
        $info           = get_addon_info($name);
        $info['status'] = 0;
        set_addon_info($name, $info);

        // 刷新
        self::refresh();
        return true;
    }

    /**
     * 离线安装
     * @param string $file 插件压缩包
     */
    public static function local($file)
    {
        $addonsTempDir = self::getAddonsBackupDir();
        if (!$file || !$file instanceof \think\File) {
            throw new Exception('没有文件上传或服务器上传限制');
        }
        $validate = validate(
            ['zip' => 'filesize:102400000|fileExt:zip'], [], false, false
        );
        if (!$validate->check(['zip' => $file])) {
            // 文件验证错误
            throw new Exception($validate->getError());
        }
        $uploadFile = $file->move($addonsTempDir, $file->hashName('md5'));
        if (!$uploadFile) {
            // 上传失败获取错误信息
            throw new Exception($file->getError());
        }
        $tmpFile = $uploadFile->getPathname();
        $info    = [];
        $zip     = new ZipFile();
        try {
            // 打开插件压缩包
            try {
                $zip->openFile($tmpFile);
            } catch (ZipException $e) {
                @unlink($tmpFile);
                throw new Exception('无法打开压缩文件');
            }

            $config = self::getInfoIni($zip);
            // 判断插件标识
            $name = isset($config['name']) ? $config['name'] : '';
            if (!$name) {
                throw new Exception('插件info.ini文件不正确');
            }

            // 判断插件是否存在
            if (!preg_match("/^[a-zA-Z0-9]+$/", $name)) {
                throw new Exception('插件名称不正确');
            }

            // 判断新插件是否存在
            $newAddonDir = self::getAddonDir($name);
            if (is_dir($newAddonDir)) {
                throw new Exception('插件已经存在');
            }

            //创建插件目录
            @mkdir($newAddonDir, 0755, true);
            // 解压到插件目录
            try {
                $zip->extractTo($newAddonDir);
            } catch (ZipException $e) {
                @unlink($newAddonDir);
                throw new Exception('无法解压缩文件');
            }

            try {
                // 默认启用该插件
                $info = get_addon_info($name);
                $info['init'] = 1;
                set_addon_info($name, $info);
                // 执行安装脚本
                $class = get_addon_class($name);
                if (class_exists($class)) {
                    $addon = new $class();
                    $addon->install();
                }
            } catch (Exception $e) {
                @File::del_dir($newAddonDir);
                throw new Exception($e->getMessage());
            }
            self::runSQL($name);
            // 启用插件
            //self::enable($name, true);
        } catch (AddonException $e) {
            throw new AddonException($e->getMessage(), $e->getCode(), $e->getData());
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        } finally {
            $zip->close();
            unset($uploadFile);
            @unlink($tmpFile);
        }
        $info['config']   = get_addon_config($name) ? 1 : 0;
        $info['testdata'] = is_file(Service::getTestdataFile($name));
        return $info;
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
            throw new Exception('参数不正确');
        }
        $addonsBackupDir = self::getAddonsBackupDir();
        $file            = $addonsBackupDir . $name . '.zip';

        // 打开插件压缩包
        $zip = new ZipFile();
        try {
            $zip->openFile($file);
        } catch (ZipException $e) {
            $zip->close();
            throw new Exception('无法打开ZIP文件');
        }

        $dir = self::getAddonDir($name);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755);
        }

        // 解压插件压缩包
        try {
            $zip->extractTo($dir);
        } catch (ZipException $e) {
            throw new Exception('无法解压ZIP文件');
        } finally {
            $zip->close();
        }
        return $dir;
    }

    /**
     * 刷新插件缓存文件.
     * @throws Exception
     * @return bool
     */
    public static function refresh()
    {
        //刷新addons.js
        $addons       = get_addon_list();
        $bootstrapArr = [];
        foreach ($addons as $name => $addon) {
            $bootstrapFile = self::getBootstrapFile($name);
            if ($addon['status'] > 0 && is_file($bootstrapFile)) {
                $bootstrapArr[] = file_get_contents($bootstrapFile);
            }
        }
        $addonsFile = app()->getRootPath() . str_replace("/", DS, "public/assets/js/addons.js");
        if ($handle = fopen($addonsFile, 'w')) {
            $tpl = <<<EOD
define([], function () {
    {__JS__}
});
EOD;
            fwrite($handle, str_replace("{__JS__}", implode("\n", $bootstrapArr), $tpl));
            fclose($handle);
        } else {
            throw new Exception("文件addons.js没有写入权限");
        }

        Cache::delete("addons");
        Cache::delete("hooks");

        $file = self::getExtraAddonsFile();

        $config = get_addon_autoload_config(true);
        if ($config['autoload']) {
            return;
        }

        if (!File::is_really_writable($file)) {
            throw new Exception('addons.php文件没有写入权限');
        }

        if ($handle = fopen($file, 'w')) {
            fwrite($handle, "<?php\n\n" . 'return ' . var_export($config, true) . ';');
            fclose($handle);
        } else {
            throw new Exception('文件没有写入权限');
        }

        return true;
    }

    /**
     * 执行安装数据库脚本
     * @param type $name 模块名(目录名)
     * @return boolean
     */
    public static function runSQL($name = '', $Dir = 'install')
    {
        $sql_file = ADDON_PATH . "{$name}" . DS . "{$Dir}.sql";
        if (file_exists($sql_file)) {
            $sql_statement = Sql::getSqlFromFile($sql_file);
            if (!empty($sql_statement)) {
                foreach ($sql_statement as $value) {
                    $value = str_ireplace('__PREFIX__', Config::get('database.connections.mysql.prefix'), $value);
                    $value = str_ireplace('INSERT INTO ', 'INSERT IGNORE INTO ', $value);
                    try {
                        Db::execute($value);
                    } catch (\Exception $e) {
                        //throw new Exception("导入SQL失败，请检查{$name}.sql的语句是否正确");
                    }
                }
            }
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
     * 是否有冲突
     *
     * @param string $name 插件名称
     * @return  boolean
     * @throws  AddonException
     */
    public static function noconflict($name)
    {
        // 检测冲突文件
        $list = self::getGlobalFiles($name, true);
        if ($list) {
            //发现冲突文件，抛出异常
            throw new AddonException("发现冲突文件", -3, ['conflictlist' => $list]);
        }
        return true;
    }

    /**
     * 匹配配置文件中info信息
     * @param ZipFile $zip
     * @return array|false
     * @throws Exception
     */
    protected static function getInfoIni($zip)
    {
        $config = [];
        // 读取插件信息
        try {
            $info   = $zip->getEntryContents('info.ini');
            $config = parse_ini_string($info);
        } catch (ZipException $e) {
            throw new Exception('插件info.ini文件不存在！');
        }
        return $config;
    }

    /**
     * 读取或修改插件配置
     * @param string $name
     * @param array  $changed
     * @return array
     */
    public static function config($name, $changed = [])
    {
        $addonDir        = self::getAddonDir($name);
        $addonConfigFile = $addonDir . '.addonrc';
        $config          = [];
        if (is_file($addonConfigFile)) {
            $config = (array) json_decode(file_get_contents($addonConfigFile), true);
        }
        $config = array_merge($config, $changed);
        if ($changed) {
            file_put_contents($addonConfigFile, json_encode($config, JSON_UNESCAPED_UNICODE));
        }
        return $config;
    }

    /**
     * 获取插件在全局的文件
     *
     * @param string  $name         插件名称
     * @param boolean $onlyconflict 是否只返回冲突文件
     * @return  array
     */
    public static function getGlobalFiles($name, $onlyconflict = false)
    {
        $list         = [];
        $addonDir     = self::getAddonDir($name);
        $checkDirList = self::getCheckDirs();
        $checkDirList = array_merge($checkDirList, ['assets']);

        $assetDir = self::getDestAssetsDir($name);

        // 扫描插件目录是否有覆盖的文件
        foreach ($checkDirList as $k => $dirName) {
            //检测目录是否存在
            if (!is_dir($addonDir . $dirName)) {
                continue;
            }
            //匹配出所有的文件
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($addonDir . $dirName, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $fileinfo) {
                if ($fileinfo->isFile()) {
                    $filePath = $fileinfo->getPathName();
                    //如果名称为assets需要做特殊处理
                    if ($dirName === 'assets') {
                        $path = str_replace(app()->getRootPath(), '', $assetDir) . str_replace($addonDir . $dirName . DS, '', $filePath);
                    } else {
                        $path = str_replace($addonDir, '', $filePath);
                    }
                    if ($onlyconflict) {
                        $destPath = app()->getRootPath() . $path;
                        if (is_file($destPath)) {
                            if (filesize($filePath) != filesize($destPath) || md5_file($filePath) != md5_file($destPath)) {
                                $list[] = $path;
                            }
                        }
                    } else {
                        $list[] = $path;
                    }
                }
            }
        }
        $list = array_filter(array_unique($list));
        return $list;
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
     * 获取插件行为、路由配置文件
     * @return string
     */
    public static function getExtraAddonsFile()
    {
        return app()->getConfigPath() . 'addons.php';
    }

    /**
     * 获取bootstrap.js路径
     * @return string
     */
    public static function getBootstrapFile($name)
    {
        return ADDON_PATH . $name . DS . 'bootstrap.js';
    }

    /**
     * 获取testdata.sql路径
     * @return string
     */
    public static function getTestdataFile($name)
    {
        return ADDON_PATH . $name . DS . 'testdata.sql';
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
     * 获取检测的全局文件夹目录
     * @return  array
     */
    protected static function getCheckDirs()
    {
        return [
            'app',
            'public',
            'templates',
        ];
    }

    /**
     * 获取插件源资源文件夹
     * @param   string $name 插件名称
     * @return  string
     */
    protected static function getSourceAssetsDir($name)
    {
        return ADDON_PATH . $name . DS . 'assets' . DS;
    }

    /**
     * 获取插件目标资源文件夹
     * @param   string $name 插件名称
     * @return  string
     */
    protected static function getDestAssetsDir($name)
    {
        $assetsDir = app()->getRootPath() . str_replace("/", DS, "public/assets/addons/{$name}/");
        return $assetsDir;
    }

    /**
     * 检测插件是否完整.
     * @param string $name 插件名称
     * @throws Exception
     * @return bool
     */
    public static function check($name)
    {
        if (!$name || !is_dir(ADDON_PATH . $name)) {
            throw new Exception('插件不存在！');
        }
        $addonClass = get_addon_class($name);
        if (!$addonClass) {
            throw new Exception('插件主启动程序不存在');
        }
        $addon = new $addonClass();
        if (!$addon->checkInfo()) {
            throw new Exception('配置文件不完整');
        }
        return true;
    }
}
