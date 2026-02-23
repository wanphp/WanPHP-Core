<?php

namespace WanPHP\Core\Factory;

use Predis\Client;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\RedisAdapter;

final class RedisCacheFactory
{
  private string $host;
  private int $port;
  private string $password;

  public function __construct(string $host, int $port, string $password)
  {
    $this->host = $host;
    $this->port = $port;
    $this->password = $password;
  }

  public function create(int $database = 0, string $prefix = 'wp_'): CacheItemPoolInterface
  {
    $client = $this->createClient($database);
    return new RedisAdapter($client, $prefix);
  }

  public function clear(int $database = 0, string $prefix = 'wp_'): void
  {
    $client = $this->createClient($database);
    $cursor = 0;

    do {
      [$cursor, $keys] = $client->scan($cursor, ['match' => $prefix . '*', 'count' => 100]);
      if (!empty($keys)) {
        $client->del(...$keys);
      }
    } while ($cursor !== 0);
  }

  private function createClient(int $database): Client
  {
    return new Client([
      'scheme' => 'tcp',
      'host' => $this->host,
      'port' => $this->port,
      'password' => $this->password,
      'database' => $database,
    ]);
  }
}