<?php

namespace WanPHP\Core\Factory;

use RecursiveIteratorIterator;
use SplFileObject;

final  class ClassScannerFactory
{
  private static array $cache = [];

  /**
   * 从文件中解析完整类名（namespace + class）
   */
  private static function scanFile(string $filePath): ?string
  {
    if (isset(self::$cache[$filePath])) {
      return self::$cache[$filePath];
    }
    $file = new SplFileObject($filePath);
    $namespace = '';
    $className = '';

    while (!$file->eof() && $file->key() < 200) {
      $line = $file->fgets();
      $trimmed = ltrim($line);

      // 跳过注释
      if (
        str_starts_with($trimmed, '//') ||
        str_starts_with($trimmed, '/*') ||
        str_starts_with($trimmed, '*')
      ) {
        continue;
      }

      // namespace
      if (!$namespace && preg_match('/^namespace\s+([^;]+);/i', $line, $m)) {
        $namespace = trim($m[1]);
        continue;
      }

      // abstract class 直接跳过
      if (preg_match('/\babstract\s+class\b/i', $line)) {
        return null;
      }

      // 跳过 interface / trait / enum
      if (preg_match('/\b(interface|trait|enum)\s+[a-zA-Z0-9_]+/i', $line)) {
        return null;
      }

      // class / readonly / final
      if (preg_match('/\b(?:readonly\s+|final\s+)?class\s+([a-zA-Z0-9_]+)/i', $line, $m)) {
        $className = $m[1];
        break;
      }
    }

    if (!$className) {
      return null;
    }

    return $namespace ? "$namespace\\$className" : $className;
  }

  /**
   * 扫描目录（递归）
   */
  public static function scanDirectories(array $paths): array
  {
    $classes = [];

    foreach ($paths as $path) {
      if (!is_dir($path)) continue; // 检查路径是否存在
      $iterator = new RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
      );

      foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
          continue;
        }

        $class = self::scanFile($file->getPathname());
        if ($class) $classes[] = $class;
      }
    }

    return $classes;
  }
}