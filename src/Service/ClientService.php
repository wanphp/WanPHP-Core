<?php

namespace WanPHP\Core\Service;

use Exception;
use WanPHP\Core\Database\EntityMetadata;
use WanPHP\Core\Entities\ClientEntity;
use WanPHP\Core\Factory\EntityMetadataFactory;
use WanPHP\Core\Repositories\Repository;

class ClientService extends Service
{

  /**
   * @throws Exception
   */
  protected function repo(): Repository
  {
    return $this->em->getRepository(ClientEntity::class);
  }

  protected function meta(): EntityMetadata
  {
    return EntityMetadataFactory::from(ClientEntity::class);
  }

  /**
   * @throws Exception
   */
  public function addClient(array $data): int
  {
    $data = new ClientEntity($data)->inArray($data)->toArray();
    unset($data['identifier']);
    return $this->repo()->insert($data, false);
  }

  /**
   * @throws Exception
   */
  public function getClient(int $id): array
  {
    return $this->repo()->get('id,name,client_id,redirect_uri,client_ip[JSON],scopes[JSON],confidential', ['id' => $id]);
  }

  /**
   * @throws Exception
   */
  public function updateClient(int $id, array $data): int
  {
    $data = new ClientEntity($data)->inArray($data)->toArray();
    unset($data['identifier']);
    return $this->repo()->update($data, ['id' => $id], false);
  }

  /**
   * @throws Exception
   */
  public function resetSecret(int $id, $client_secret): int
  {
    return $this->repo()->update(['client_secret' => password_hash($client_secret, PASSWORD_BCRYPT)], ['id' => $id], false);
  }

  /**
   * @throws Exception
   */
  public function deleteClient(int $id): int
  {
    return $this->delete($id);
  }

  /**
   * @throws Exception
   */
  public function getAll(): array
  {
    return $this->repo()->select('id,name,client_id,redirect_uri,client_ip[JSON],scopes[JSON],confidential');
  }


}