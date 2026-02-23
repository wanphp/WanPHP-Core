<?php

namespace WanPHP\Core\Entities;


use Doctrine\DBAL\Types\Types;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use OpenApi\Attributes as OA;
use WanPHP\Core\Attribute\Column;
use WanPHP\Core\Attribute\DataTable;
use WanPHP\Core\Repositories\OAuth2\ScopeRepository;
use WanPHP\Core\Traits\EntityArrayTrait;

#[DataTable(name: 'scopes', required: ["identifier", "name"], repositoryClass: ScopeRepository::class)]
#[OA\Schema(title: "用户授权", description: "用户授权范围", required: ["identifier", "name"])]
class ScopeEntity implements ScopeEntityInterface
{
  use EntityTrait, EntityArrayTrait;

  #[Column(type: Types::SMALLINT, autoIncrement: true, primary: true)]
  #[OA\Property(description: "ID")]
  private string $id;
  #[Column(type: Types::STRING, length: 20, unique: true)]
  #[OA\Property(description: "权限ID", type: "string")]
  protected string $identifier;
  #[Column(type: Types::STRING, length: 10)]
  #[OA\Property(description: "名称", type: "string")]
  private string $name;
  #[Column(type: Types::STRING, length: 100)]
  #[OA\Property(description: "权限说明", type: "string")]
  private string $description;
  #[Column(type: Types::JSON)]
  #[OA\Property(description: "授权范围", type: "array", items: new OA\Items(type: "string", example: "user.read"))]
  private array $scopes;

  public function getId(): string
  {
    return $this->id;
  }

  public function setId(string $id): self
  {
    $this->id = $id;
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

  public function getDescription(): string
  {
    return $this->description;
  }

  public function setDescription(string $description): self
  {
    $this->description = $description;
    return $this;
  }

  public function getScopes(): array
  {
    return $this->scopes;
  }

  public function setScopes(array $scopes): self
  {
    $this->scopes = $scopes;
    return $this;
  }

  public function jsonSerialize(): string
  {
    return $this->getIdentifier();
  }
}
