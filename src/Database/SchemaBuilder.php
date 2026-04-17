<?php

namespace WanPHP\Core\Database;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Exception\TypesException;
use Doctrine\DBAL\Types\Type;
use ReflectionClass;
use ReflectionException;
use WanPHP\Core\Attribute\DataTable;
use WanPHP\Core\Attribute\Column;
use WanPHP\Core\Attribute\Index;
use WanPHP\Core\Attribute\Unique;

final class SchemaBuilder
{
  /**
   * @throws ReflectionException
   * @throws TypesException
   */
  public function build(Schema $schema, array $entities): Schema
  {
    foreach ($entities as $entity) {
      $this->buildTable($schema, $entity);
    }

    return $schema;
  }

  /**
   * @throws ReflectionException
   * @throws TypesException
   */
  public function buildTable(Schema $schema, string $entity): void
  {
    $ref = new ReflectionClass($entity);
    $datTable = $ref->getAttributes(Datatable::class)[0]?->newInstance();
    if ($datTable) {
      $tableName = $datTable->name;
    } else {
      $tableName = $ref->getShortName();
      $suffix = "Entity";
      if (str_ends_with($tableName, $suffix)) {
        $tableName = substr($tableName, 0, -strlen($suffix));
      }
    }
    $tableName = getenv('DATABASE_TABLE_PREFIX') . $tableName;

    // 当前数据库的列
    $columns = [];
    // 实体已声明的列
    $declaredColumns = [];
    // 当前数据库索引
    $indexes = [];
    // 实体已声明的索引
    $declaredIndexes = [];
    // 重命名字段
    $renames = [];
    if ($schema->hasTable($tableName)) {
      $table = $schema->getTable($tableName);
      $columns = $table->getColumns();
      $indexes = $table->getIndexes();
    } else {
      $table = $schema->createTable($tableName);
    }

    $primary = [];

    foreach ($ref->getProperties() as $property) {
      $attr = $property->getAttributes(Column::class)[0] ?? null;
      if (!$attr) continue;

      $field = $attr->newInstance();
      $name = $property->getName();
      $declaredColumns[] = $name;
      if ($field->renameFrom) {
        $renames[$field->renameFrom] = $name;
      }

      if ($table->hasColumn($name)) {
        $column = $table->getColumn($name);
        $column->setType(Type::getType($field->type));
        $column->setNotnull(!$field->nullable);
        if ($field->precision) $column->setPrecision($field->precision);
        if ($field->scale) $column->setScale($field->scale);
        if ($field->default !== null) $column->setDefault($field->default);
        if ($field->length && $field->length > 0) $column->setLength((int)$field->length);
        if ($field->autoIncrement && $field->primary) $column->setAutoincrement(true);
        if ($field->comment !== null && $field->comment !== '') $column->setComment($field->comment);
      } else {
        $options = ['notnull' => !$field->nullable];
        if ($field->autoIncrement && $field->primary) $options['autoincrement'] = true;
        if ($field->precision !== null) $options['precision'] = $field->precision;
        if ($field->scale !== null) $options['scale'] = $field->scale;
        if ($field->comment !== null && $field->comment !== '') $options['comment'] = $field->comment;
        if ($field->default !== null) $options['default'] = $field->default;
        if ($field->length && $field->length > 0) $options['length'] = $field->length;
        if ($field->type === 'decimal') {
          $options['precision'] ??= 10;
          $options['scale'] ??= 2;
        }
        $table->addColumn($name, $field->type, $options);
      }

      if ($field->primary) $primary[] = $name;
      if ($field->unique && !$table->hasIndex('uniq_' . $tableName . '_' . $name)) $table->addUniqueIndex([$name], 'uniq_' . $tableName . '_' . $name);
      if ($field->index && !$table->hasIndex('idx_' . $tableName . '_' . $name)) $table->addIndex([$name], 'idx_' . $tableName . '_' . $name);

      if ($field->unique || $field->index) $declaredIndexes[] = [$name];
    }

    // class index
    foreach ($ref->getAttributes(Index::class) as $attr) {

      $index = $attr->newInstance();

      $name = $index->name ?? 'idx_' . $tableName . '_' . implode('_', $index->columns);

      if (!$table->hasIndex($name)) {
        $table->addIndex($index->columns, $name);
      }

      $declaredIndexes[] = $index->columns;
    }

    foreach ($ref->getAttributes(Unique::class) as $attr) {

      $index = $attr->newInstance();

      $name = $index->name ?? 'uniq_' . $tableName . '_' . implode('_', $index->columns);

      if (!$table->hasIndex($name)) {
        $table->addUniqueIndex($index->columns, $name);
      }

      $declaredIndexes[] = $index->columns;
    }

    // 唯一索引
    if ($primary) {
      $pk = $table->getPrimaryKey();
      if ($pk === null) {
        $table->setPrimaryKey($primary);
      } elseif ($pk->getColumns() !== $primary) {
        $table->dropPrimaryKey();
        $table->setPrimaryKey($primary);
      }
    }

    // 命名字段
    foreach ($renames as $old => $new) {
      if ($table->hasColumn($old) && !$table->hasColumn($new)) {
        $table->renameColumn($old, $new);
      }
    }
    // 删除废弃索引（Indexes）
    foreach ($indexes as $index) {
      if ($index->isPrimary()) continue;

      $indexName = $index->getName();
      $isDeclared = false;

      foreach ($declaredIndexes as $declaredIndex) {
        $indexColumns = $index->getColumns();
        sort($indexColumns);

        $declared = $declaredIndex;
        sort($declared);

        if ($indexColumns === $declared) {
          $isDeclared = true;
          break;
        }
      }

      if (!$isDeclared) {
        $table->dropIndex($indexName);
      }
    }
    // 删除数据库中「实体未声明」的字段
    foreach ($columns as $column) {
      $columnName = $column->getName();
      $renameFrom = array_keys($renames);

      if (
        !str_contains($columnName, '__deprecated_') &&
        !in_array($columnName, $declaredColumns, true) &&
        !in_array($columnName, $renameFrom, true)
      ) {
        //$table->dropColumn($columnName);
        // 删除相关索引
        foreach ($indexes as $index) {
          if ($index->isPrimary()) continue;

          if (in_array($columnName, $index->getColumns(), true)) {
            $table->dropIndex($index->getName());
          }
        }

        $suffix = '__deprecated_' . date('Ymd');
        $maxLength = 64;
        $newName = substr($columnName, 0, $maxLength - strlen($suffix)) . $suffix;

        $table->renameColumn($columnName, $newName);
      }
    }
  }
}
