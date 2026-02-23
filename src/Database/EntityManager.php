<?php

namespace WanPHP\Core\Database;


use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Types\Exception\TypesException;
use Medoo\Medoo;
use PDO;
use ReflectionException;
use WanPHP\Core\Factory\RepositoryFactory;
use WanPHP\Core\Repositories\Repository;

class EntityManager extends Medoo
{
  private SchemaSync $schemaSync;

  public function __construct(array $options, private readonly RepositoryFactory $repositoryFactory)
  {
    parent::__construct($options);
    $this->schemaSync = new SchemaSync(
      DriverManager::getConnection([
        'driver' => 'pdo_' . $options['database_type'],
        'host' => $options['server'],   // 容器名
        'port' => $options['port'],
        'dbname' => $options['database_name'],
        'user' => $options['username'],
        'password' => $options['password'],
        'charset' => $options['charset'],
        'driverOptions' => [
          PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
          PDO::ATTR_PERSISTENT => false,
        ],
      ]), new SchemaBuilder());
  }


  /**
   * Gets the repository for a document class.
   *
   * @param class-string<T> $className The name of the Document.
   *
   * @return Repository<T>  The repository.
   *
   * @template T of object
   * @throws \Exception
   */
  public function getRepository(string $className): Repository
  {
    return $this->repositoryFactory->getRepository($this, $className);
  }

  /**
   * @param array<class-string> $entities 实体类列表
   * @param bool $execute 是不立即执行
   * @return array
   * @throws Exception
   * @throws ReflectionException
   * @throws TypesException
   */
  public function syncAll(array $entities, bool $execute = true): array
  {
    return $this->schemaSync->sync($entities, $execute);
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
    return $this->schemaSync->syncSchema($entityClass, $execute);
  }

}
