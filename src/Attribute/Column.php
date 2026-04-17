<?php

declare(strict_types=1);

namespace WanPHP\Core\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class Column
{

  /**
   * @param string $type 字段类型
   * @param int|null $length
   * @param int|null $precision
   * @param int|null $scale
   * @param bool $nullable
   * @param mixed|null $default
   * @param bool $autoIncrement
   * @param bool $primary
   * @param bool $unique
   * @param bool $index 索引
   * @param string|null $comment
   * @param string|null $renameFrom
   */
  public function __construct(
    public string  $type,                 // Types::STRING
    public ?int    $length = null,
    public ?int    $precision = null,
    public ?int    $scale = null,
    public bool    $nullable = false,
    public mixed   $default = null,
    public bool    $autoIncrement = false,
    public bool    $primary = false,
    public bool    $unique = false,
    public bool    $index = false,
    public ?string $comment = '',
    public ?string $renameFrom = null,    // 字段重命名
  )
  {
  }
}

