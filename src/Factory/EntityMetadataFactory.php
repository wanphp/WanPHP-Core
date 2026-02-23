<?php

namespace WanPHP\Core\Factory;

use Exception;
use ReflectionClass;
use WanPHP\Core\Attribute\Column;
use WanPHP\Core\Attribute\DataTable;
use WanPHP\Core\Database\EntityMetadata;

final class EntityMetadataFactory
{
  private static array $cache = [];

  /**
   * @throws Exception
   */
  public static function from(string $entityClass): EntityMetadata
  {
    if (isset(self::$cache[$entityClass])) {
      return self::$cache[$entityClass];
    }

    $ref = new ReflectionClass($entityClass);
    $meta = new EntityMetadata();

    // 表名
    $dt = $ref->getAttributes(Datatable::class)[0] ?? null;
    $meta->table = $dt ? $dt->newInstance()->name : strtolower($ref->getShortName());
    $suffix = "entity";
    if (str_ends_with($meta->table, $suffix)) {
      $meta->table = substr($meta->table, 0, -strlen($suffix));
    }

    foreach ($ref->getProperties() as $prop) {
      $attr = $prop->getAttributes(Column::class)[0] ?? null;
      if (!$attr) continue;

      $field = $attr->newInstance();
      $name = $prop->getName();

      $meta->columns[$name] = $field;

      if ($field->primary === true) {
        $meta->primaryKeys[] = $name;
      }
    }

    if (!$meta->primaryKeys) {
      throw new Exception(
        "Entity {$entityClass} has no primary key"
      );
    }

    return self::$cache[$entityClass] = $meta;
  }
}