<?php

namespace WanPHP\Core\Worker;

use Redis;

final readonly class RedisStream
{
  public function __construct(private Redis $redis)
  {
  }

  public function push(string $key, array $payload): void
  {
    if (!empty($key) && !empty($payload)) $this->redis->xAdd(
      $key,
      '*',
      ['payload' => json_encode($payload, JSON_UNESCAPED_UNICODE)]
    );
  }

}