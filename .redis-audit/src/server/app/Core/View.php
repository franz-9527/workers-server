<?php

namespace WorkerAdmin\Core;

use RuntimeException;

class View
{
    private $basePath;

    public function __construct($basePath)
    {
        $this->basePath = rtrim($basePath, '/\\');
    }

    // 2026-08-14：视图只允许加载 views 目录内模板，并支持统一后台布局。
    public function render($template, array $data = [], $layout = 'layouts/admin')
    {
        $templateFile = $this->resolve($template);
        extract($data, EXTR_SKIP);
        ob_start();
        require $templateFile;
        $content = ob_get_clean();

        if ($layout === null) {
            return $content;
        }

        $layoutFile = $this->resolve($layout);
        ob_start();
        require $layoutFile;
        return ob_get_clean();
    }

    private function resolve($template)
    {
        $template = str_replace(['..', '\\'], ['', '/'], (string) $template);
        $file = $this->basePath . DIRECTORY_SEPARATOR . trim($template, '/') . '.php';
        if (!is_file($file)) {
            throw new RuntimeException('视图不存在：' . $template);
        }
        return $file;
    }
}
