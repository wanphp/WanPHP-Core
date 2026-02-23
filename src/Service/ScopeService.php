<?php

namespace WanPHP\Core\Service;

use Exception;
use WanPHP\Core\Database\EntityMetadata;
use WanPHP\Core\Entities\ScopeEntity;
use WanPHP\Core\Factory\EntityMetadataFactory;
use WanPHP\Core\Repositories\Repository;

class ScopeService extends Service
{
  /**
   * @throws Exception
   */
  protected function repo(): Repository
  {
    return $this->em->getRepository(ScopeEntity::class);
  }

  protected function meta(): EntityMetadata
  {
    return EntityMetadataFactory::from(ScopeEntity::class);
  }

  /**
   * @throws Exception
   */
  public function addScope(array $data): int
  {
    return $this->save($data);
  }

  /**
   * @throws Exception
   */
  public function updateScope(int $id, array $data): int
  {
    return $this->update($id, $data);
  }

  /**
   * @throws Exception
   */
  public function deleteScope(int $id): int
  {
    return $this->delete($id);
  }

  /**
   * @throws Exception
   */
  public function getAll(): array
  {
    return $this->repo()->select('id,identifier,name,description,scopes[JSON]');
  }
}