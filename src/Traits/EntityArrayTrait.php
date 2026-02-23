<?php

namespace WanPHP\Core\Traits;

use WanPHP\Core\Exception\ValidationException;

trait EntityArrayTrait
{
  /**
   * 将对象中已初始化的属性转数组，并检查必填字段
   * @param bool $check_required_fields
   * @return array
   * @throws ValidationException
   */
  public function toArray(bool $check_required_fields = true): array
  {
    $data = [];
    $ref = new \ReflectionClass($this);

    foreach ($ref->getProperties() as $prop) {
      // 只导出已初始化的属性
      if ($prop->isInitialized($this)) {
        $key = $prop->getName();
        $value = $prop->getValue($this);
        if (is_array($value)) $key .= '[JSON]';
        $data[$key] = $value;
      }
    }

    // 检查必填字段
    $errors = [];
    if ($check_required_fields) {
      $required = [];

      $schemaAttr = $ref->getAttributes(\WanPHP\Core\Attribute\DataTable::class)[0] ?? null;
      if ($schemaAttr) $required = $schemaAttr->newInstance()->required ?? [];

      foreach ($required as $field) {
        if (!array_key_exists($field, $data) || empty($data[$field])) {
          $label = $field;
          if ($ref->hasProperty($field)) {
            $property = $ref->getProperty($field);
            $oaAttr = $property->getAttributes(\OpenApi\Attributes\Property::class)[0] ?? null;
            if ($oaAttr) $label = $oaAttr->newInstance()->description ?? null;
          }
          $errors[$field][] = "{$label}为必填字段，不能为空。";
        }
      }
    }

    if ($errors) {
      throw new ValidationException($errors);
    }
    return $data;
  }

  /**
   * 将数组导入对象中
   *
   * - 只写入实体中已定义的属性
   * - 自动进行类型转换
   * - 忽略未知字段
   */
  public function inArray(array $data): self
  {
    $ref = new \ReflectionClass($this);

    foreach ($data as $name => $value) {
      if (!$ref->hasProperty($name) || $value === null) continue;

      $prop = $ref->getProperty($name);

      $type = $prop->getType();
      if ($type instanceof \ReflectionNamedType) {
        $value = $this->castValue($value, $type->getName());
      }

      $prop->setValue($this, $value);
    }

    return $this;
  }


  /**
   * 根据属性类型进行安全类型转换
   */
  private function castValue(mixed $value, string $type): mixed
  {
    return match ($type) {
      'int' => (int)$value,
      'float' => (float)$value,
      'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
      'string' => (string)$value,
      'array' => (array)$value,
      default => $value,
    };
  }

  /**
   * 强密码检测
   *
   * @param string $password
   * @param int $minLength
   * @return void
   * @throws ValidationException
   */
  public function checkStrongPassword(string $password, int $minLength = 8): void
  {
    $errors = [];

    if (strlen($password) < $minLength) {
      $errors[] = "密码长度不能少于 {$minLength} 位";
    }

    if (!preg_match('/[A-Z]/', $password)) {
      $errors[] = '必须包含至少一个大写字母';
    }

    if (!preg_match('/[a-z]/', $password)) {
      $errors[] = '必须包含至少一个小写字母';
    }

    if (!preg_match('/\d/', $password)) {
      $errors[] = '必须包含至少一个数字';
    }

    if (!preg_match('/[!@#$%^&*()_+\-=\[\]{}|;:\'",.<>?\/`~]/', $password)) {
      $errors[] = '必须包含至少一个特殊字符';
    }

    if (preg_match('/[\x{4e00}-\x{9fa5}]/u', $password)) {
      $errors[] = '不能包含中文字符';
    }

    if (preg_match('/\s/', $password)) {
      $errors[] = '不能包含空格';
    }
    if (!empty($errors)) {
      throw new ValidationException(['password' => $errors]);
    }
  }

  /**
   * @throws ValidationException
   */
  public function checkPhoneNumber(string $phone): void
  {
    $regex = '/^(13[0-9]|14[579]|15[0-35-9]|16[6]|17[0-9]|18[0-9]|19[12589])\d{8}$/';

    if (!preg_match($regex, $phone)) {
      throw new ValidationException(['password' => ['手机号格式错误']]);
    }
  }

}