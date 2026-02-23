<?php

namespace WanPHP\Core\Service;

use Exception;
use WanPHP\Core\Database\EntityMetadata;
use WanPHP\Core\Database\EntityManager;
use WanPHP\Core\Repositories\Repository;

abstract class Service
{
  public function __construct(protected EntityManager $em)
  {
  }

  /**
   * @throws Exception
   */
  abstract protected function repo(): Repository;

  /**
   * @throws Exception
   */
  abstract protected function meta(): EntityMetadata;

  /**
   * @throws Exception
   */
  public function save(array $data): string|array|int
  {
    $pks = $this->meta()->primaryKeys;
    $where = [];
    if (!empty($pks)) foreach ($pks as $pk) {
      if (isset($data[$pk])) {
        $where[$pk] = $data[$pk];
      } else {
        // 没有包含所有联合主键
        $where = [];
        break;
      }
    }
    // 检查数据是否存在，存在则更新数据
    if (!empty($where)) {
      if ($this->repo()->get('*', $where)) {
        $this->repo()->update($data, $where);
        return $where;
      }
    }
    $data['createdAt'] = time();
    return $this->repo()->insert($data);
  }

  /**
   * @throws Exception
   */
  public function insertEntityToArray(array $data): string|int
  {
    if (!empty($data)) return $this->repo()->insert($data, false);
    throw new Exception('数据不能为空');
  }

  /**
   * @throws Exception
   */
  public function updateEntityToArray(array $data, array $where): string|int
  {
    if (!empty($data) && !empty($where)) {
      return $this->repo()->update($data, $where, false);
    }
    if (empty($data)) throw new Exception('更新数据不能为空');
    throw new Exception('更新条件不合法');
  }

  /**
   * @throws Exception
   */
  protected function getSinglePk(): string
  {
    if (count($this->meta()->primaryKeys) !== 1) {
      throw new Exception('不支持复合主键Load');
    }
    return $this->meta()->primaryKeys[0];
  }

  /**
   * @throws Exception
   */
  public function load(int|string $id): array
  {
    $pk = $this->getSinglePk();
    return $this->repo()->get('*', [$pk => $id]);
  }

  /**
   * @throws Exception
   */
  public function update(int|string $id, array $data): int
  {
    $pk = $this->getSinglePk();
    return $this->repo()->update($data, [$pk => $id]);
  }


  /**
   * @throws Exception
   */
  public function batchUpdate(array $rows, string $key, int $batchSize = 300): int
  {
    if (empty($rows)) {
      return 0;
    }

    $done = 0;

    foreach (array_chunk($rows, $batchSize) as $chunk) {
      try {
        $this->em->pdo->beginTransaction();
        $done += $this->repo()->batchUpdate($chunk, $key);
        $this->em->pdo->commit();
      } catch (Exception $e) {
        $this->em->pdo->rollBack();
        throw $e;
      }
    }

    return $done;
  }


  /**
   * @throws Exception
   */
  public function delete(int|string $id): int
  {
    $pk = $this->getSinglePk();
    return $this->repo()->delete([$pk => $id]);
  }

  /**
   * @throws Exception
   */
  public function deleteMany(array $where): int
  {
    return $this->repo()->delete($where);
  }

  /**
   * @throws Exception
   */
  public function get(array $where): array
  {
    return $this->repo()->get('*', $where);
  }

  /**
   * @throws Exception
   */
  public function getColumn(string $column, array $where): mixed
  {
    return $this->repo()->get($column, $where);
  }

  /**
   * @throws Exception
   */
  public function count(array $where = []): int
  {
    return $this->repo()->count('*', $where);
  }

  /**
   * @throws Exception
   */
  public function select(string $column, array $where = []): array
  {
    return $this->repo()->select($column, $where);
  }

  /**
   * @throws Exception
   */
  public function getAll(): array
  {
    return $this->repo()->select();
  }
}