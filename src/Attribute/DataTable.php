<?php

declare(strict_types=1);

namespace WanPHP\Core\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class DataTable
{

  /**
   * @param string $name 数据库表名
   * @param array|null $required 必填字段
   * @param string|null $repositoryClass
   */
  public function __construct(
    public string  $name,
    public ?array  $required = [],
    public ?string $repositoryClass = null
  )
  {
  }
}