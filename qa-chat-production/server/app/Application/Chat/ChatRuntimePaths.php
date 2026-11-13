<?php
declare(strict_types=1);

namespace WorkerAdmin\Application\Chat;

/** Managed Go is a sibling of server/ in a full repository checkout. */
final class ChatRuntimePaths
{
    public static function managedRoot(): ?string
    {
        $phpRoot=rtrim(\base_path(),'/\\');
        if (basename($phpRoot)!=='server') return null;

        $root=dirname($phpRoot);
        $chat=$root.'/chat';
        if (!self::insidePhpScope($chat) || !@is_dir($chat) || !@is_readable($chat)) return null;
        return $root;
    }

    public static function binDirectory(): ?string
    {
        $root=self::managedRoot();
        return $root===null ? null : $root.'/chat/bin';
    }

    public static function stateDirectory(): ?string
    {
        if ((string) \app_config('app.env', 'production') === 'production' && \base_path() === '/www/wwwroot/worker') {
            $path=\base_path('storage/private/chat-runtime-control');
            return self::insidePhpScope($path) ? $path : null;
        }
        $bin=self::binDirectory();
        return $bin===null ? null : $bin.'/state';
    }

    private static function insidePhpScope(string $path): bool
    {
        $scope=(string)ini_get('open_basedir');
        if ($scope==='') return true;

        $normalize=static function (string $value): string {
            if (DIRECTORY_SEPARATOR==='\\') $value=strtolower(str_replace('\\','/',$value));
            return rtrim($value,'/');
        };
        $path=$normalize($path);
        foreach (explode(PATH_SEPARATOR,$scope) as $entry) {
            if ($entry==='') continue;
            $allowed=$normalize(@realpath($entry) ?: $entry);
            if ($path===$allowed || str_starts_with($path,$allowed.'/')) return true;
        }
        return false;
    }
}
