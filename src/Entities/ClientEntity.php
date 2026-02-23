<?php

namespace WanPHP\Core\Entities;


use Doctrine\DBAL\Types\Types;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use WanPHP\Core\Attribute\Column;
use OpenApi\Attributes as OA;
use WanPHP\Core\Attribute\DataTable;
use WanPHP\Core\Repositories\OAuth2\ClientRepository;
use WanPHP\Core\Traits\EntityArrayTrait;

#[DataTable(name: 'client', required: ["client_id", "name"],repositoryClass: ClientRepository::class)]
#[OA\Schema(title: "用户授权", description: "用户授权范围", required: ["client_id", "name"])]
class ClientEntity implements ClientEntityInterface
{
  use EntityTrait, EntityArrayTrait;

  #[Column(type: Types::SMALLINT, autoIncrement: true, primary: true)]
  #[OA\Property(description: "ID")]
  private ?int $id;
  #[Column(type: Types::STRING, length: 20)]
  #[OA\Property(description: "客户端名称", type: "string")]
  protected string $name;
  #[Column(type: Types::STRING, length: 20, unique: true)]
  #[OA\Property(description: "客户端ID", type: "string")]
  private string $client_id;
  #[Column(type: Types::STRING, length: 60, index: true)]
  #[OA\Property(description: "客户端密钥", type: "string")]
  private string $client_secret;
  #[Column(type: Types::STRING, length: 100)]
  #[OA\Property(description: "客户端回调URL", type: "string")]
  private string $redirect_uri;
  #[Column(type: Types::JSON)]
  #[OA\Property(description: "客户端授权IP", type: "array", items: new OA\Items(type: "string", example: "192.168.1.100"))]
  private array $client_ip = [];
  #[Column(type: Types::JSON)]
  #[OA\Property(description: "授权范围", type: "array", items: new OA\Items(type: "string", example: "openid"))]
  private array $scopes;
  #[Column(type: Types::BOOLEAN)]
  #[OA\Property(description: "是否机密", type: "int")]
  private int $confidential = 1;

  public function getId(): ?int
  {
    return $this->id;
  }

  public function setId(?int $id): self
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

  public function getClientId(): string
  {
    return $this->client_id;
  }

  public function setClientId(string $client_id): self
  {
    $this->client_id = $client_id;
    return $this;
  }

  public function getClientSecret(): string
  {
    return $this->client_secret;
  }

  public function setClientSecret(string $client_secret): self
  {
    $this->client_secret = password_hash($client_secret, PASSWORD_BCRYPT);
    return $this;
  }

  public function getRedirectUri(): string
  {
    return $this->redirect_uri;
  }

  public function setRedirectUri(string $redirect_uri): self
  {
    $this->redirect_uri = $redirect_uri;
    return $this;
  }

  public function getClientIp(): array
  {
    return $this->client_ip;
  }

  public function setClientIp(array $client_ip): self
  {
    $this->client_ip = $client_ip;
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

  public function isConfidential(): bool
  {
    return $this->confidential;
  }

  public function setConfidential(int $confidential): self
  {
    $this->confidential = $confidential;
    return $this;
  }

  public function __construct(array $data)
  {
    $this->identifier = $data['client_id'];
    $this->name = $data['name'];
    $this->redirect_uri = $data['redirect_uri'];
    $this->confidential = $data['confidential'];
  }

  public function supportsGrantType(string $grantType): bool
  {
    return true;
  }
}
