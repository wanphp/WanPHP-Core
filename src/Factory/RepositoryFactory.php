<?php

namespace WanPHP\Core\Factory;

use Exception;
use ReflectionClass;
use ReflectionException;
use WanPHP\Core\Attribute\DataTable;
use WanPHP\Core\Database\EntityManager;
use WanPHP\Core\Repositories\Repository;

final class RepositoryFactory
{
  /**
   * The list of DocumentRepository instances.
   *
   * @var Repository<object>[]
   */
  private array $repositoryList = [];

  /**
   * @param class-string<T> $entityClassName
   *
   * @phpstan-return Repository<T>
   *
   * @template T of object
   * @throws Exception
   */
  public function getRepository(EntityManager $database, string $entityClassName): Repository
  {

    try {
      $reflection = new ReflectionClass($entityClassName);
    } catch (ReflectionException $e) {
      throw new Exception($e->getMessage());
    }
    $hashKey = $reflection->getName() . '_' . spl_object_id($database);

    if (isset($this->repositoryList[$hashKey])) {
      return $this->repositoryList[$hashKey];
    }

    $attr = $reflection->getAttributes(DataTable::class)[0] ?? null;
    if ($attr) {
      $tableName = $attr->newInstance()->name;
      $repositoryClassName = $attr->newInstance()->repositoryClass ?: Repository::class;

      $repository = new $repositoryClassName($database, $tableName, $entityClassName);

      $this->repositoryList[$hashKey] = $repository;
    }

    return $this->repositoryList[$hashKey];
  }
}