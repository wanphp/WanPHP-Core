<?php

namespace WanPHP\Core\Exception;

use Throwable;

class ValidationException extends \Exception
{
  public readonly array $errors;

  public function __construct(
    array      $errors,
    string     $message = '数据验证失败',
    int        $code = 422,
    ?Throwable $previous = null
  )
  {
    parent::__construct($message, $code, $previous);
    $this->errors = $errors;
  }

}