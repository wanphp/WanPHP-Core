<?php

namespace WanPHP\Core\Database;

class EntityMetadata
{
  public string $table;
  public array $columns = [];

  /** @var string[] */
  public array $primaryKeys = [];
}