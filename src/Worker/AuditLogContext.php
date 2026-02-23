<?php

namespace WanPHP\Core\Worker;

final class AuditLogContext
{
  public bool $audit = false;
  public bool $debug = false;
  public array $entries = [];
  public array $actor = [];

  private static ?self $current = null;

  public static function markDebug(string $message, array $data = []): void
  {
    $ctx = self::$current;
    if ($ctx === null) return;

    $ctx->debug = true;
    $ctx->audit = false;
    $ctx->entries[] = compact('message', 'data');
  }

  public static function markActor(
    string      $type,
    int|string  $id,
    string|null $clientId = null,
  ): void
  {
    $ctx = self::$current;
    if ($ctx === null) return;

    $ctx->actor = compact('type', 'id', 'clientId');
  }

  public static function markChanged(
    string     $resource,
    int|string $id,
    string     $action,
    array      $changes = []
  ): void
  {
    $ctx = self::$current;
    if ($ctx === null) return;

    $ctx->audit = true;
    $ctx->entries[] = compact('resource', 'id', 'action', 'changes');
  }

  public static function start(): self
  {
    return self::$current = new self();
  }

  public static function current(): ?self
  {
    return self::$current;
  }

  public static function clear(): void
  {
    self::$current = null;
  }
}
