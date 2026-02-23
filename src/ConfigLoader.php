<?php


namespace WanPHP\Core;
class ConfigLoader
{
  public static function load(string $env_path = '/.env'): void
  {
    $cacheFile = ROOT_PATH . '/var/cache/config.cache.php';
    $envFile = ROOT_PATH . $env_path;

    // 优先使用 cache
    if (file_exists($cacheFile)) {
      $config = require $cacheFile;
    } else {
      // 第一次运行：解析 .env
      if (!file_exists($envFile)) exit("$env_path not found");

      $config = self::parseEnvFile($envFile);

      // 将配置缓存
      file_put_contents(
        $cacheFile,
        "<?php\nreturn " . var_export($config, true) . ";"
      );
    }

    self::applyConfig($config);
  }

  private static function parseEnvFile(string $file): array
  {
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $config = [];

    foreach ($lines as $line) {
      // 跳过注释
      if (str_starts_with(trim($line), '#')) continue;

      [$key, $value] = array_map('trim', explode('=', $line, 2));
      if ($value != '') $config[$key] = $value;
    }

    return $config;
  }

  private static function applyConfig(array $config): void
  {
    foreach ($config as $key => $value) {
      putenv("$key=$value");
    }
  }
}