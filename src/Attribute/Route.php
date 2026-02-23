<?php

declare(strict_types=1);

namespace WanPHP\Core\Attribute;


use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Route
{
  /**
   * @param string $path 路由路径，如 "/users/{id}"
   * @param array $methods 允许的 HTTP 方法，如 ["GET", "POST"]
   * @param string|null $description 路由描述，用于文档或调试
   * @param string|null $name 路由名称，用于 urlFor()
   * @param bool $isNav 是否可以配置到菜单
   * @param array $middleware 应用于此路由的中间件，字符串类名、[class, params]
   */
  public function __construct(
    public string  $path,
    public array   $methods = ['GET'],
    public ?string $description = null,
    public ?string $name = null,
    public ?bool   $isNav = false,
    /** @var array<int, string|array> */
    public array  $middleware = []
  )
  {
  }
}