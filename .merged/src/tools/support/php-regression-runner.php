<?php

declare(strict_types=1);

/** 各专项测试独立加载全局函数和 Fake 类，保留原始断言与失败退出码。 */
return static function (array $regressions): void {
    $phpCommand = [PHP_BINARY];
    $loadedIni = php_ini_loaded_file();
    if ($loadedIni !== false) {
        $phpCommand[] = '-c';
        $phpCommand[] = $loadedIni;
    } else {
        // 兼容本地使用 -n -d extension=... 的运行器。
        $phpCommand[] = '-n';
        $extensionDirectory = (string) ini_get('extension_dir');
        $phpCommand[] = '-d';
        $phpCommand[] = 'extension_dir=' . $extensionDirectory;
        foreach (get_loaded_extensions() as $extension) {
            foreach ([$extension . '.so', 'php_' . $extension . '.dll'] as $library) {
                if (!is_file($extensionDirectory . DIRECTORY_SEPARATOR . $library)) continue;
                $phpCommand[] = '-d';
                $phpCommand[] = 'extension=' . $library;
                break;
            }
        }
    }

    foreach ($regressions as $regression) {
        $process = proc_open(
            array_merge($phpCommand, [$regression]),
            [0 => STDIN, 1 => STDOUT, 2 => STDERR],
            $pipes,
            dirname(__DIR__, 2)
        );
        if (!is_resource($process)) {
            fwrite(STDERR, '无法启动专项测试：' . $regression . "\n");
            exit(1);
        }
        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            fwrite(STDERR, '专项测试失败：' . $regression . "\n");
            exit($exitCode > 0 ? $exitCode : 1);
        }
    }
};
