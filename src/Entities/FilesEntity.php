<?php

namespace WanPHP\Core\Entities;


use Doctrine\DBAL\Types\Types;
use OpenApi\Attributes as OA;
use WanPHP\Core\Attribute\Column;
use WanPHP\Core\Attribute\DataTable;
use WanPHP\Core\Traits\EntityArrayTrait;

#[DataTable(name: 'upload_files', required: ["name", "url"])]
#[OA\Schema(title: "上传文件", description: "上传文件", required: ["name", "url"])]
class FilesEntity
{
  use EntityArrayTrait;

  #[Column(type: Types::INTEGER, autoIncrement: true, primary: true)]
  #[OA\Property(description: "文件ID")]
  private ?int $id;
  #[Column(type: Types::STRING, length: 28, nullable: true, index: true)]
  #[OA\Property(description: "上传用户ID", type: "string")]
  private string $openid;
  #[Column(type: Types::STRING, length: 100, index: true)]
  #[OA\Property(description: "文件名称", type: "string")]
  private string $name;
  #[Column(type: Types::STRING, length: 32, unique: true)]
  #[OA\Property(description: "文件md5值", type: "string")]
  private string $md5;
  #[Column(type: Types::STRING, length: 120)]
  #[OA\Property(description: "文件地址", type: "string")]
  private string $url;
  #[Column(type: Types::INTEGER)]
  #[OA\Property(description: "文件大小", type: "integer")]
  private int $size;
  #[Column(type: Types::STRING, length: 80, index: true)]
  #[OA\Property(description: "文件类型", type: "string")]
  private string $type;
  #[Column(type: Types::STRING, length: 10)]
  #[OA\Property(description: "文件扩展名", type: "string")]
  private string $extension;
  #[Column(type: Types::STRING, length: 10)]
  #[OA\Property(description: "文件扩展名", type: "integer")]
  private int $uptime;

  /**
   * @return int|null
   */
  public function getId(): ?int
  {
    return $this->id;
  }

  /**
   * @param int|null $id
   * @return FilesEntity
   */
  public function setId(?int $id): self
  {
    $this->id = $id;
    return $this;
  }

  /**
   * @return string
   */
  public function getOpenid(): string
  {
    return $this->openid;
  }

  /**
   * @param string $openid
   * @return FilesEntity
   */
  public function setOpenid(string $openid): self
  {
    $this->openid = $openid;
    return $this;
  }

  /**
   * @return string
   */
  public function getName(): string
  {
    return $this->name;
  }

  /**
   * @param string $name
   * @return FilesEntity
   */
  public function setName(string $name): self
  {
    $this->name = $name;
    return $this;
  }

  /**
   * @return string
   */
  public function getMd5(): string
  {
    return $this->md5;
  }

  /**
   * @param string $md5
   * @return FilesEntity
   */
  public function setMd5(string $md5): self
  {
    $this->md5 = $md5;
    return $this;
  }

  /**
   * @return string
   */
  public function getUrl(): string
  {
    return $this->url;
  }

  /**
   * @param string $url
   * @return FilesEntity
   */
  public function setUrl(string $url): self
  {
    $this->url = $url;
    return $this;
  }

  /**
   * @return int
   */
  public function getSize(): int
  {
    return $this->size;
  }

  /**
   * @param int $size
   * @return FilesEntity
   */
  public function setSize(int $size): self
  {
    $this->size = $size;
    return $this;
  }

  /**
   * @return string
   */
  public function getType(): string
  {
    return $this->type;
  }

  /**
   * @param string $type
   * @return FilesEntity
   */
  public function setType(string $type): self
  {
    $this->type = $type;
    return $this;
  }

  /**
   * @return string
   */
  public function getExtension(): string
  {
    return $this->extension;
  }

  /**
   * @param string $extension
   * @return FilesEntity
   */
  public function setExtension(string $extension): self
  {
    $this->extension = $extension;
    return $this;
  }

  /**
   * @return int
   */
  public function getUptime(): int
  {
    return $this->uptime;
  }

  /**
   * @param int $uptime
   * @return FilesEntity
   */
  public function setUptime(int $uptime): self
  {
    $this->uptime = $uptime;
    return $this;
  }

}
