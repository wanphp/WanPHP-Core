<?php

namespace WanPHP\Core\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class Index
{
  public function __construct(
    public array $columns,
    public ?string $name = null
  ) {}
}