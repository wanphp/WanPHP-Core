<?php

namespace WanPHP\Core\Worker;

use Medoo\Medoo;
use Redis;
use Throwable;

abstract class StreamWorker
{
  private bool $running = true;
  protected Medoo $database;

  public function __construct(
    private readonly Redis  $redis,
    private readonly array  $options,
    private readonly string $consumer,
    private readonly string $table,
    private readonly string $stream = 'audit:stream',
    private readonly string $group = 'audit_workers'
  )
  {
    $this->database = new Medoo($this->options);
  }

  public function run(): void
  {
    $this->initGroup();
    $this->registerSignal();
    $this->recoverPending();

    while ($this->running) {
      try {
        $messages = $this->redis->xReadGroup(
          $this->group,
          $this->consumer,
          [$this->stream => '>'],
          10, // 每次只取 10 条
          5000 // 最多阻塞等待 5000 毫秒
        );

        if (!$messages) {
          continue;
        }

        [$batch, $ids] = $this->formatData($messages[$this->stream]);

        if ($batch) {
          if ($this->safeInsert($batch)) {
            $this->redis->xAck($this->stream, $this->group, $ids);
          }
        }
      } catch (Throwable $e) {
        error_log('[AUDIT_STREAM] ' . $e->getMessage());
      }
    }
  }

  private function recoverPending(): void
  {
    $pending = $this->redis->xPending(
      $this->stream,
      $this->group,
      '-',
      '+',
      10
    );

    if (!$pending) {
      return;
    }

    foreach ($pending as $msg) {
      $id = $msg[0];

      $messages = $this->redis->xReadGroup(
        $this->group,
        $this->consumer,
        [$this->stream => $id],
        1,
        0
      );

      if (!$messages) {
        continue;
      }

      [$batch, $ids] = $this->formatData($messages[$this->stream]);

      if ($batch) {
        if ($this->safeInsert($batch)) {
          $this->redis->xAck($this->stream, $this->group, $ids);
        }
      }
    }
  }

  private function initGroup(): void
  {
    try {
      $this->redis->xGroup('CREATE', $this->stream, $this->group, '$', true);
    } catch (\RedisException $e) {

      if (str_contains($e->getMessage(), 'BUSYGROUP')) {
        error_log('GROUP ALREADY EXISTS(' . $this->group . ')');
        return;
      }

      error_log('GROUP CREATE ERROR: ' . $e->getMessage());
      throw $e;
    }
  }

  private function registerSignal(): void
  {
    if (!extension_loaded('pcntl')) {
      return;
    }

    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, fn() => $this->running = false);
    pcntl_signal(SIGINT, fn() => $this->running = false);
  }

  abstract function formatData(array $data): array;

  /**
   * 安全插入（自动重连）
   */
  private function safeInsert(array $batch): bool
  {
    try {
      // 确保 PDO 配置了 ERRMODE_EXCEPTION（建议在初始化 Medoo 的 options 里设置）
      $this->database->insert($this->table, $batch);

      // 检查 Medoo 内部记录的错误（针对非异常类的逻辑错误）
      $error = $this->database->errorInfo; // Medoo 建议使用 error() 方法
      if ($error && $error[1]) {
        throw new \Exception($error[2], (int)$error[1]);
      }
      return true;
    } catch (\Throwable $e) {
      error_log('[AUDIT] DB ERROR: ' . $e->getMessage());

      if ($this->isConnectionLost($e)) {
        $this->reconnectDatabase();
        // 重连后可以尝试立即重试一次，或者返回 false 等待下一次轮询
        try {
          $this->database->insert($this->table, $batch);
          return true;
        } catch (\Throwable $retryException) {
          return false;
        }
      }
      return false;
    }
  }

  /**
   * 判断是否断线
   */
  private function isConnectionLost(\Throwable $e): bool
  {
    // 如果是 PDOException，优先检查错误代码
    if ($e instanceof \PDOException) {
      $errorCode = $e->errorInfo[1] ?? null;
      if (in_array($errorCode, [2006, 2013])) {
        return true;
      }
    }

    $msg = $e->getMessage();
    return str_contains($msg, 'server has gone away')
      || str_contains($msg, 'Lost connection')
      || str_contains($msg, 'Error while sending')
      || str_contains($msg, 'Broken pipe')
      || str_contains($msg, 'connection sessions closed')
      || str_contains($msg, 'Deadlock found');
  }

  /**
   * 重建数据库连接
   */
  private function reconnectDatabase(): void
  {
    try {
      $this->database = new Medoo($this->options);

      error_log('[' . $this->table . '] MySQL Reconnected');

    } catch (\Throwable $e) {
      error_log('[' . $this->table . '] Reconnect Failed: ' . $e->getMessage());
      sleep(2);
    }
  }
}