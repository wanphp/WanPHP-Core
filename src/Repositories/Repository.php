<?php

namespace WanPHP\Core\Repositories;

use Doctrine\DBAL\Types\Exception\TypesException;
use Exception;
use InvalidArgumentException;
use WanPHP\Core\Database\EntityManager;
use WanPHP\Core\Factory\EntityMetadataFactory;
use WanPHP\Core\Worker\AuditLogContext;

class Repository
{
  protected EntityManager $em;
  protected string $tableName;
  private string $entityClass;

  public function __construct(EntityManager $em, string $tableName, string $entityClass)
  {
    $this->em = $em;
    $this->tableName = $tableName;
    $this->entityClass = $entityClass;
  }

  /**
   * @throws Exception
   */
  public function insert(array $data, bool $check = true): int|string
  {
    $batch_insert = true;
    if (!isset($data[0])) {
      $batch_insert = false;
      $data = [$data];
    }
    if ($check) {
      foreach ($data as &$item) $item = new $this->entityClass()->inArray($item)->toArray();
    }
    if (empty($data)) throw new Exception('写入数据不能为空！');
    $res = $this->em->insert($this->tableName, $data);
    if ($this->isSchemaError($this->em->error) && $this->syncSchema()) {
      // 同步表后重复一次
      $res = $this->em->insert($this->tableName, $data);
    }
    if ($this->em->error) throw new Exception($this->em->error);

    $id = $this->em->id() ?? 0;
    // 日志审计
    if (!str_ends_with($this->tableName, '_logs')) {
      if ($batch_insert) {
        AuditLogContext::markChanged(resource: $this->tableName,
          id: null,
          action: 'batch_insert',
          changes: [
            'lastInsertId' => $id,
            'count' => $res->rowCount(),
            'data' => $data,
          ]
        );
      } else {
        AuditLogContext::markChanged($this->tableName, $id, 'insert', $data[0]);
      }
    }
    return $id;
  }

  /**
   * @throws Exception
   */
  public function update(array $data, array $where, bool $check = true): int
  {
    if (str_ends_with($this->tableName, '_logs')) {
      throw new Exception('不允许修改日志！');
    }
    // 保留[+]、[-]、[*]和[/]数学运算,$check=false
    if ($check) {
      $data = new $this->entityClass()->inArray($data)->toArray();
    }
    if (empty($data)) throw new Exception('更新数据不能为空！');
    $res = $this->em->update($this->tableName, $data, $where);
    if ($this->isSchemaError($this->em->error) && $this->syncSchema()) {
      // 同步表后重复一次
      $res = $this->em->update($this->tableName, $data, $where);
    }
    if ($this->em->error) throw new Exception($this->em->error);
    if ($res) {
      $counts = $res->rowCount() ?? 0;

      // 日志审计
      if ($counts > 0) {
        AuditLogContext::markChanged(
          resource: $this->tableName,
          id: $this->getPkId($counts, $where),
          action: $counts == 1 ? 'update' : 'batch_update',
          changes: [
            'where' => $where,
            'set' => $data,
            'affected' => $counts,
          ]
        );
      }
    }
    return $counts ?? 0;
  }

  /**
   * 批量更新（CASE WHEN）
   *
   * @param array $rows 数据行
   * @param string $key 主键字段（如 id）
   * @return int 影响行数
   */
  public function batchUpdate(array $rows, string $key): int
  {
    if (str_ends_with($this->tableName, '_logs')) {
      return 0;
    }
    if (empty($rows)) {
      return 0;
    }

    $fields = [];
    foreach ($rows as $row) {
      foreach ($row as $field => $_) {
        if ($field !== $key) $fields[$field] = true;
      }
    }
    $fields = array_keys($fields);

    if (empty($fields)) {
      throw new InvalidArgumentException('No fields to update');
    }

    $cases = [];
    $ids = [];
    $binds = [];

    foreach ($fields as $field) {
      $cases[$field] = [];
    }

    foreach ($rows as $i => $row) {
      if (!isset($row[$key])) {
        throw new InvalidArgumentException("Missing key: {$key}");
      }

      $idKey = ":id_{$i}_i";
      $ids[] = $idKey;
      $binds[$idKey] = $row[$key];

      foreach ($fields as $field) {
        if (!array_key_exists($field, $row)) {
          continue;
        }

        $valKey = ":{$field}_{$i}_i";
        $cases[$field][] = "WHEN {$idKey} THEN {$valKey}";

        $binds[$valKey] = is_array($row[$field])
          ? json_encode($row[$field], JSON_UNESCAPED_UNICODE)
          : $row[$field];
      }
    }

    $setSql = [];
    $key = $this->em->columnQuote($key);
    foreach ($cases as $field => $case) {
      if (!$case) continue;
      $field = $this->em->columnQuote($field);
      $setSql[] = "$field = CASE $key " . implode(' ', $case) . " ELSE $field END";
    }

    $sql = sprintf(
      "UPDATE %s SET %s WHERE %s IN (%s)",
      $this->em->tableQuote($this->tableName),
      implode(', ', $setSql),
      $key,
      implode(', ', $ids)
    );

    $stmt = $this->em->query($sql, $binds);
    $updateNum = $stmt->rowCount() ?? 0;
    if ($updateNum > 0) {
      // 日志审计
      AuditLogContext::markChanged(
        resource: $this->tableName,
        id: null,
        action: 'batch_update',
        changes: [
          'key' => $key,
          'keyValues' => $ids,
          'fields' => array_keys($cases),
          'count' => $updateNum,
        ]
      );
    }

    return $updateNum;
  }


  /**
   * @param string $columns
   * @param $where
   * @return array
   * @throws Exception
   */
  public function select(string $columns = '*', $where = null): array
  {
    if ($columns != '*' && strpos($columns, ',') > 0) $columns = explode(',', $columns);
    $data = $this->em->select($this->tableName, $columns, $where);
    if ($this->isSchemaError($this->em->error) && $this->syncSchema()) {
      // 同步表后重复一次
      $data = $this->em->select($this->tableName, $columns, $where);
    }
    if ($this->em->error) throw new Exception($this->em->error);
    return $data ?? [];
  }

  /**
   * @param string $columns
   * @param $where
   * @return mixed
   * @throws Exception
   */
  public function get(string $columns = '*', $where = null): mixed
  {
    if ($columns != '*' && strpos($columns, ',') > 0) $columns = explode(',', $columns);
    $data = $this->em->get($this->tableName, $columns, $where);
    if ($this->isSchemaError($this->em->error) && $this->syncSchema()) {
      // 同步表后重复一次
      $data = $this->em->get($this->tableName, $columns, $where);
    }
    if ($this->em->error) throw new Exception($this->em->error);

    return $data ?? [];
  }

  /**
   * @param string $columns
   * @param $where
   * @return int
   * @throws Exception
   */
  public function count(string $columns = '*', $where = null): int
  {
    $count = $this->em->count($this->tableName, $columns, $where);
    if ($this->isSchemaError($this->em->error) && $this->syncSchema()) {
      // 同步表后重复一次
      $count = $this->em->get($this->tableName, $columns, $where);
    }
    if ($this->em->error) throw new Exception($this->em->error);
    return $count ?: 0;
  }

  /**
   * @param string $column
   * @param $where
   * @return string|null
   * @throws Exception
   */
  public function sum(string $column, $where = null): ?string
  {
    $num = $this->em->sum($this->tableName, $column, $where);
    if ($this->isSchemaError($this->em->error) && $this->syncSchema()) {
      // 同步表后重复一次
      $num = $this->em->sum($this->tableName, $column, $where);
    }
    if ($this->em->error) throw new Exception($this->em->error);
    return $num;
  }

  /**
   * @param array $where
   * @return int
   * @throws Exception
   */
  public function delete(array $where): int
  {
    if (str_ends_with($this->tableName, '_logs')) {
      throw new Exception('不允许删除日志！');
    }
    $res = $this->em->delete($this->tableName, $where);
    if ($this->isSchemaError($this->em->error) && $this->syncSchema()) {
      // 同步表后重复一次
      $res = $this->em->delete($this->tableName, $where);
    }
    if ($this->em->error) throw new Exception($this->em->error);
    if ($res) {
      $counts = $res->rowCount() ?? 0;
      if ($counts > 0) {
        AuditLogContext::markChanged(
          resource: $this->tableName,
          id: $this->getPkId($counts, $where),
          action: $counts == 1 ? 'delete' : 'batch_delete',
          changes: [
            'where' => $where,
            'count' => $counts,
          ]
        );
      }
    }

    return $counts ?? 0;
  }

  public function log(): string
  {
    return implode(PHP_EOL, $this->em->log());
  }

  /**
   * @return true
   * @throws Exception
   */
  private function syncSchema(): true
  {
    if (getenv('APP_ENV') === 'prod') throw new Exception($this->em->error);
    if ($this->isSchemaError($this->em->error)) {
      try {
        $this->em->syncSchema($this->entityClass);
        return true;
      } catch (TypesException|\Doctrine\DBAL\Exception|\ReflectionException $e) {
        throw new Exception($e->getMessage());
      }
    }
    throw new Exception($this->em->error);
  }

  private function isSchemaError(string|null $msg): bool
  {
    if (is_null($msg)) return false;
    return
      str_contains($msg, 'doesn\'t exist')
      || str_contains($msg, 'Unknown column')
      || str_contains($msg, 'undefined_column')
      || str_contains($msg, 'no such table')
      || str_contains($msg, 'no such column');
  }

  /**
   * @param int $counts
   * @param array $where
   * @return mixed|null
   * @throws Exception
   */
  private function getPkId(int $counts, array $where): mixed
  {
    $id = null;
    if ($counts == 1) {
      $meta = EntityMetadataFactory::from($this->entityClass);
      $pks = $meta->primaryKeys;
      if (count($pks) == 1) {
        $pk = $pks[0];
        $id = $where[$pk] ?? null;
      }
    }
    return $id;
  }
}
