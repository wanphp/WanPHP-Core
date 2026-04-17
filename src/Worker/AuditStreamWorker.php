<?php

namespace WanPHP\Core\Worker;

final class AuditStreamWorker extends StreamWorker
{
  function formatData(array $data): array
  {
    $batch = [];
    $ids = [];
    foreach ($data as $id => $item) {
      try {
        $payload = json_decode(
          $item['payload'],
          true,
          512,
          JSON_THROW_ON_ERROR
        );

        $batch[] = $payload;
        $ids[] = $id;
      } catch (\Throwable $e) {
        error_log("[{$item['payload']}] invalid JSON {$id}，{$e->getMessage()}");
      }
    }
    return [$batch, $ids];
  }
}