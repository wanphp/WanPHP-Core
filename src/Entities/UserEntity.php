<?php

namespace WanPHP\Core\Entities;


use Doctrine\DBAL\Types\Types;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\UserEntityInterface;
use WanPHP\Core\Attribute\Column;
use WanPHP\Core\Attribute\DataTable;
use WanPHP\Core\Exception\ValidationException;
use WanPHP\Core\Traits\EntityArrayTrait;
use OpenApi\Attributes as OA;

#[DataTable(name: 'user', required: ["openid"])]
#[OA\Schema(title: "微信用户", description: "微信授权用户", required: ["openid"])]
class UserEntity implements UserEntityInterface
{
  use EntityTrait;
  use EntityArrayTrait;

  #[Column(type: Types::STRING, length: 28, primary: true)]
  #[OA\Property(description: "公众号/小程序openid", type: "string")]
  private string $openid;
  #[Column(type: Types::STRING, length: 32, index: true)]
  #[OA\Property(description: "用户昵称", type: "string")]
  protected string $nickname;
  #[Column(type: Types::STRING, length: 150)]
  #[OA\Property(description: "用户头像", type: "string")]
  protected string $avatar;
  #[Column(type: Types::STRING, length: 16, index: true)]
  #[OA\Property(description: "用户姓名", type: "string")]
  protected string $name;
  #[Column(type: Types::STRING, length: 11, nullable: true, unique: true)]
  #[OA\Property(description: "用户联系电话", type: "string")]
  protected string $tel;
  #[Column(type: Types::STRING, length: 50, index: true)]
  #[OA\Property(description: "用户备注", type: "string")]
  protected string $remark;
  #[Column(type: Types::STRING, length: 60)]
  #[OA\Property(description: "用户密码", type: "string")]
  private string $password;
  #[Column(type: Types::STRING, length: 28, index: true)]
  #[OA\Property(description: "分享用户", type: "string")]
  private string $share;
  #[Column(type: Types::BOOLEAN)]
  #[OA\Property(description: "用户状态", type: "bool")]
  protected int $status = 1;
  #[Column(type: Types::STRING, length: 10, index: true)]
  #[OA\Property(description: "创建时间", type: "string")]
  private string $createdAt;

  public function getOpenid(): string
  {
    return $this->openid;
  }

  public function setOpenid(string $openid): self
  {
    $this->openid = $openid;
    return $this;
  }

  public function getNickname(): string
  {
    return $this->nickname;
  }

  public function setNickname(string $nickname): self
  {
    $this->nickname = $nickname;
    return $this;
  }

  public function getAvatar(): string
  {
    return $this->avatar;
  }

  public function setAvatar(string $avatar): self
  {
    $this->avatar = $avatar;
    return $this;
  }

  public function getName(): string
  {
    return $this->name;
  }

  public function setName(string $name): self
  {
    $this->name = $name;
    return $this;
  }

  public function getTel(): string
  {
    return $this->tel;
  }

  /**
   * @throws ValidationException
   */
  public function setTel(string $tel): self
  {
    $this->checkPhoneNumber($tel);
    $this->tel = $tel;
    return $this;
  }

  public function getRemark(): string
  {
    return $this->remark;
  }

  public function setRemark(string $remark): self
  {
    $this->remark = $remark;
    return $this;
  }

  public function getPassword(): string
  {
    return $this->password;
  }

  /**
   * @throws ValidationException
   */
  public function setPassword(string $password): self
  {
    $this->checkStrongPassword($password);
    $this->password = password_hash($password, PASSWORD_BCRYPT);
    return $this;
  }

  public function getShare(): string
  {
    return $this->share;
  }

  public function setShare(string $share): self
  {
    $this->share = $share;
    return $this;
  }

  public function getStatus(): int
  {
    return $this->status;
  }

  public function setStatus(int $status): self
  {
    $this->status = $status;
    return $this;
  }

}
