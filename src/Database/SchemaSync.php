<?php

namespace WanPHP\Core\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Types\Exception\TypesException;
use ReflectionException;

final readonly class SchemaSync
{
  public function __construct(private Connection $conn, private SchemaBuilder $builder)
  {
  }

  /**
   * @param array<class-string> $entities
   * @param bool $execute
   * @return array
   * @throws Exception
   * @throws ReflectionException
   * @throws TypesException
   */
  public function sync(array $entities, bool $execute = true): array
  {
    $schemaManager = $this->conn->createSchemaManager();

    // 当前数据库结构
    $currentSchema = $schemaManager->introspectSchema();

    // 目标结构
    $toSchema = clone $currentSchema;
    $targetSchema = $this->builder->build($toSchema, $entities);

    $platform = $this->conn->getDatabasePlatform();
    $comparator = new Comparator($platform);

    // 计算差异
    $diff = $comparator->compareSchemas($currentSchema, $targetSchema);

    // 生成 SQL
    $sql = $platform->getAlterSchemaSQL($diff);

    if ($execute) {
      foreach ($sql as $statement) {
        $this->conn->executeStatement($statement);
      }
    }

    return $sql;
  }

  /**
   * @param string $entityClass
   * @param bool $execute
   * @return array
   * @throws Exception
   * @throws ReflectionException
   * @throws TypesException
   */
  public function syncSchema(string $entityClass, bool $execute = true): array
  {
    $schemaManager = $this->conn->createSchemaManager();
    $fromSchema = $schemaManager->introspectSchema();
    $toSchema = clone $fromSchema;

    $this->builder->buildTable($toSchema, $entityClass);

    $platform = $this->conn->getDatabasePlatform();
    $comparator = new Comparator($platform);
    $diff = $comparator->compareSchemas($fromSchema, $toSchema);

    // 生成 SQL
    $sql = $platform->getAlterSchemaSQL($diff);

    if ($execute) {
      foreach ($sql as $statement) {
        $this->conn->executeStatement($statement);
      }
    }
    return $sql;
  }

}


