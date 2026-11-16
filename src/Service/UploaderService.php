<?php

namespace WanPHP\Core\Service;

use Exception;
use finfo;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Slim\Psr7\UploadedFile;
use Throwable;
use WanPHP\Core\Database\EntityMetadata;
use WanPHP\Core\Entities\FilesEntity;
use WanPHP\Core\Database\EntityManager;
use WanPHP\Core\Factory\EntityMetadataFactory;
use WanPHP\Core\Repositories\Repository;
use ZipArchive;

class UploaderService extends Service
{
  private string $filepath;
  private array $mimeTypes = [
    // images
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'image/gif' => 'gif',

    // documents
    'application/pdf' => 'pdf',
    'text/plain' => 'txt',

    // audio
    'audio/mpeg' => 'mp3',

    // video
    'video/mp4' => 'mp4',

    // Office (现代格式)
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',

    // Office (旧格式)
    'application/msword' => 'doc',
    'application/vnd.ms-excel' => 'xls',
    'application/vnd.ms-powerpoint' => 'ppt'
  ];

  public function __construct(EntityManager $em)
  {
    parent::__construct($em);
    $this->filepath = ROOT_PATH . getenv('APP_UPLOAD_FILE_PATH');
    if (getenv('APP_ALLOW_FILE_TYPE')) {
      $mimeTypes = json_decode(getenv('APP_ALLOW_FILE_TYPE'), true);
      if (json_last_error() === JSON_ERROR_NONE) {
        $this->mimeTypes = array_merge($this->mimeTypes, $mimeTypes);
      }
    }
  }

  public function setUploadPath(string $path): void
  {
    $this->filepath = $path;
  }

  /**
   * @inheritDoc
   */
  protected function repo(): Repository
  {
    return $this->em->getRepository(FilesEntity::class);
  }

  /**
   * @inheritDoc
   */
  protected function meta(): EntityMetadata
  {
    return EntityMetadataFactory::from(FilesEntity::class);
  }

  /**
   *
   * @throws Exception
   */
  public function uploadFile(array $formData, UploadedFile $uploadedFile): array
  {
    //大文件分块上传
    if (isset($formData['total'])) {
      // 获取最大文件大小，默认50MB，最大上传5G
      $maxFileSize = filter_var(
        getenv('APP_UPLOAD_FILE_MAX_SIZE') ?: 50,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 5120]]
      );

      if ($maxFileSize === false) {
        throw new Exception('上传文件大小配置错误，最大5120MB');
      }

      $total = filter_var(
        $formData['total'],
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 2, 'max_range' => (int)ceil($maxFileSize / 2)]]
      );
      if ($total === false) {
        throw new Exception('上传文件大小超过限制');
      }

      // 验证文件MD5
      $fileMd5 = strtolower(trim((string)($formData['md5'] ?? '')));

      if (!preg_match('/\A[a-f0-9]{32}\z/', $fileMd5)) {
        throw new \InvalidArgumentException('文件MD5格式错误');
      }

      // 验证分片索引
      $index = filter_var(
        $formData['index'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 0, 'max_range' => $total - 1]]
      );

      if ($index === false) {
        throw new Exception('分片索引错误');
      }

      // 上传第一片，检查文件是否上传过
      if ($index === 0) {
        $file = $this->repo()->get('id,type,url', ['md5' => $fileMd5]);
        if (isset($file['id'])) {
          $file['host'] = $formData['host'];
          return $file;
        }
      }

      // 分片临时目录
      $tmpPath = $this->filepath . '/tmp/' . $fileMd5;

      // 保存分片
      $this->saveChunk($uploadedFile, $tmpPath, $index);

      $uploaded = $this->isCompleted($tmpPath, $total);
      // 所有分片上传完成
      if ($uploaded === true) {
        $extension = strtolower(pathinfo($uploadedFile->getClientFilename(), PATHINFO_EXTENSION));
        $filename = sprintf('%s.%0.8s', bin2hex(random_bytes(8)), $extension);
        // 将文件合并到临时目录
        $tmpFile = $this->filepath . '/tmp/' . $filename;
        $this->merge($tmpPath, $tmpFile, $total);
        if ($fileMd5 != md5_file($tmpFile)) {
          $this->deleteInvalidFile($tmpFile);
          throw new \InvalidArgumentException('文件MD5验证错误');
        }

        // 检查上传文件
        $file = $this->validateFile($tmpFile);
        $extension = $file['ext'];
        $fileType = $file['type'];
        $filename = sprintf('%s.%0.8s', bin2hex(random_bytes(8)), $extension);
        $filePath = $file['savePath'] . DIRECTORY_SEPARATOR . $filename;
        // 移动重命名上传文件
        rename($tmpFile, $this->filepath . $filePath);

        $data = [
          'name' => pathinfo($uploadedFile->getClientFilename(), PATHINFO_FILENAME),
          'type' => $fileType,
          'md5' => $fileMd5,
          'size' => filesize($this->filepath . $filePath),
          'extension' => $extension,
          'url' => $filePath,
          'openid' => $formData['openid'] ?: 0,
          'uptime' => time()
        ];
      } else {
        return ['next_index' => $uploaded, 'msg' => "继续上传下一块文件！"];
      }
    } else {
      // 文件已上传过
      $fileMd5 = md5_file($uploadedFile->getFilePath());
      $file = $this->repo()->get('id,type,url', ['md5' => $fileMd5]);
      if (isset($file['id'])) {
        $file['host'] = $formData['host'];
        return $file;
      }

      // 检查上传文件
      $file = $this->validateFile($uploadedFile->getFilePath());
      $extension = $file['ext'];
      $fileType = $file['type'];
      $filename = sprintf('%s.%0.8s', bin2hex(random_bytes(8)), $extension);
      $filePath = $file['savePath'] . DIRECTORY_SEPARATOR . $filename;

      $data = [
        'name' => pathinfo($uploadedFile->getClientFilename(), PATHINFO_FILENAME),
        'type' => $fileType,
        'md5' => $fileMd5,
        'size' => $uploadedFile->getSize(),
        'extension' => $extension,
        'url' => $filePath,
        'openid' => $formData['openid'] ?: 0,
        'uptime' => time()
      ];
      $uploadedFile->moveTo($this->filepath . $filePath);
    }
    return ['id' => $this->save($data), 'type' => $data['type'], 'host' => $formData['host'], 'url' => $data['url']];
  }

  /**
   * 校验文件真实类型并返回扩展名
   * @throws Exception
   */
  private function validateFile(string $filePath): array
  {
    // 文件基本检查
    if (!is_file($filePath) || is_link($filePath)) {
      throw new Exception('文件无效');
    }

    $fileSize = filesize($filePath);

    if ($fileSize === false || $fileSize <= 0) {
      $this->deleteInvalidFile($filePath);
      throw new Exception('文件无效');
    }

    // 检测真实 MIME
    $mime = new finfo(FILEINFO_MIME_TYPE)->file($filePath);
    if ($mime === false || !isset($this->mimeTypes[$mime])) {
      $this->deleteInvalidFile($filePath);
      throw new Exception('不支持的文件类型');
    }

    // Office 文件校验
    if (str_starts_with($mime, 'application/vnd.openxmlformats')) {
      $this->validateOfficeFile($filePath, $mime);
    }

    // 图片校验
    if (str_starts_with($mime, 'image/')) {
      $imageInfo = @getimagesize($filePath);

      if ($imageInfo === false) {
        $this->deleteInvalidFile($filePath);
        throw new Exception('非法图片文件');
      }

      [$width, $height] = $imageInfo;

      // 限制图片尺寸
      if (
        $width <= 0 ||
        $height <= 0 ||
        $width > 12000 ||
        $height > 12000 ||
        $width * $height > 40000000
      ) {
        $this->deleteInvalidFile($filePath);
        throw new Exception('图片尺寸超出限制');
      }

      // 检查图片实际类型是否与 MIME 对应
      $imageMime = image_type_to_mime_type($imageInfo[2]);

      if ($imageMime !== $mime) {
        $this->deleteInvalidFile($filePath);
        throw new Exception('图片类型不匹配');
      }
    }

    // 生成保存目录
    $type = explode('/', $mime, 2);

    if ($type[0] === 'application') {
      $savePath = '/' . $this->mimeTypes[$mime] . date('/Ym');
    } else {
      $savePath = '/' . $type[0] . date('/Ym');
    }

    $saveDir = $this->filepath . $savePath;

    if (!is_dir($saveDir)) {
      if (!mkdir($saveDir, 0755, true) && !is_dir($saveDir)) {
        throw new Exception('无法创建文件保存目录');
      }
    }

    if (is_link($saveDir)) {
      throw new Exception('文件保存目录异常');
    }

    return [
      'type' => $mime,
      'ext' => $this->mimeTypes[$mime],
      'savePath' => $savePath
    ];
  }

  /**
   * 校验 Office 文件内部结构
   * @throws Exception
   */
  private function validateOfficeFile(string $filePath, string $mime): void
  {
    $requiredFiles = [
      'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => [
        '[Content_Types].xml',
        'word/document.xml'
      ],
      'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => [
        '[Content_Types].xml',
        'xl/workbook.xml'
      ],
      'application/vnd.openxmlformats-officedocument.presentationml.presentation' => [
        '[Content_Types].xml',
        'ppt/presentation.xml'
      ]
    ];

    if (!isset($requiredFiles[$mime])) {
      $this->deleteInvalidFile($filePath);
      throw new Exception('不支持的 Office 文件格式');
    }

    $zip = new ZipArchive();

    if ($zip->open($filePath) !== true) {
      $this->deleteInvalidFile($filePath);
      throw new Exception('非法 Office 文件');
    }

    try {
      $numFiles = $zip->numFiles;

      // 限制压缩包内部文件数量
      if ($numFiles <= 0 || $numFiles > 2000) {
        throw new Exception('Office 文件内部结构异常');
      }

      $totalSize = 0;

      for ($i = 0; $i < $numFiles; $i++) {
        $stat = $zip->statIndex($i);

        if ($stat === false) {
          throw new Exception('Office 文件结构异常');
        }

        $size = $stat['size'] ?? 0;
        $compressedSize = $stat['comp_size'] ?? 0;

        $totalSize += $size;

        // 限制解压后的总大小，例如200MB
        if ($totalSize > 200 * 1024 * 1024) {
          throw new Exception('Office 文件解压后大小超出限制');
        }

        // 限制单个文件压缩比
        if (
          $size > 1024 * 1024 &&
          ($compressedSize <= 0 || $size / $compressedSize > 1000)
        ) {
          throw new Exception('Office 文件压缩比例异常');
        }
      }

      foreach ($requiredFiles[$mime] as $required) {
        if ($zip->locateName($required) === false) {
          throw new Exception('Office 文件内部结构不完整');
        }
      }
    } catch (Throwable $e) {
      $zip->close();
      $this->deleteInvalidFile($filePath);

      if ($e instanceof Exception) {
        throw $e;
      }

      throw new Exception('Office 文件校验失败', 0, $e);
    }

    $zip->close();
  }

  private function deleteInvalidFile(string $filePath): void
  {
    if (is_file($filePath)) {
      @unlink($filePath);
    }
  }

  /**
   * 保存单个分片
   * @throws Exception
   */
  private function saveChunk(UploadedFile $file, string $uploadTmpPath, int $chunkIndex): void
  {
    // 限制单个分片大为2MB
    if ($file->getSize() > 2 * 1024 * 1024) {
      throw new Exception('分片大小超出限制');
    }

    if (!is_dir($uploadTmpPath)) {
      if (!mkdir($uploadTmpPath, 0700, true) && !is_dir($uploadTmpPath)) {
        throw new Exception('无法创建分片目录');
      }
    }

    $path = $uploadTmpPath . '/' . $chunkIndex . '.part';

    if (file_exists($path)) {
      // 已上传过，支持断点续传
      return;
    }

    $file->moveTo($path);
  }

  /**
   * 是否所有分片都已上传
   */
  private function isCompleted(string $uploadTmpPath, int $totalChunks): bool|int
  {
    for ($i = 0; $i < $totalChunks; $i++) {
      if (!file_exists($uploadTmpPath . '/' . $i . '.part')) {
        return $i;
      }
    }

    return true;
  }

  /**
   * 合并分片
   * @throws Exception
   */
  private function merge(string $uploadTmpPath, string $filePath, int $totalChunks): void
  {
    if (!is_dir($uploadTmpPath)) {
      throw new Exception('分片不存在');
    }

    $out = fopen($filePath, 'ab');
    for ($i = 0; $i < $totalChunks; $i++) {
      $part = $uploadTmpPath . '/' . $i . '.part';

      if (!file_exists($part)) {
        fclose($out);
        throw new Exception("缺少分片 $i");
      }

      fwrite($out, file_get_contents($part));
    }

    fclose($out);

    // 清理分片
    array_map('unlink', glob($uploadTmpPath . '/*.part'));
    rmdir($uploadTmpPath);
  }

  /**
   * @throws Exception
   * @throws GuzzleException
   */
  public function downloadFile(string $url): array
  {
    if (str_starts_with($url, '//')) $url = 'https' . $url;
    $test = parse_url($url);
    $client = new Client([
      'headers' => ['Referer' => $test['host'] ?? '']
    ]);
    $filename = sprintf('%s.%0.8s', bin2hex(random_bytes(8)), 'dat');
    if (!is_dir($this->filepath . '/tmp/')) mkdir($this->filepath . '/tmp/', 0755, true);
    $downloadFile = $this->filepath . '/tmp/' . $filename;
    $client->request('GET', $url, ['sink' => $downloadFile]);

    $fileMD5 = md5_file($downloadFile);
    $file = $this->repo()->get('id,type,url', ['md5' => $fileMD5]);
    if (isset($file['id'])) {
      unlink($downloadFile);
      return $file;
    }
    // 检查下载文件
    try {
      $file = $this->validateFile($downloadFile);
    } catch (Exception $e) {
      return ['errMsg' => $e->getMessage()];
    }

    $extension = $file['ext'];
    $fileType = $file['type'];
    $filename = sprintf('%s.%0.8s', bin2hex(random_bytes(8)), $extension);
    $filePath = $file['savePath'] . DIRECTORY_SEPARATOR . $filename;

    if (rename($downloadFile, $this->filepath . $filePath)) {
      $data = [
        'name' => '来源' . $test['host'],
        'type' => $fileType,
        'md5' => $fileMD5,
        'size' => filesize($this->filepath . $filePath),
        'extension' => $extension,
        'url' => $filePath,
        'openid' => 0,
        'uptime' => time()
      ];
      return ['id' => $this->save($data), 'type' => $data['type'], 'url' => $data['url']];
    }
    return ['errMsg' => '下载失败'];
  }

  /**
   * @throws Exception
   */
  public function setName(int $id, string $name): int
  {
    return $this->repo()->update(['name' => $name], ['id' => $id], false);
  }

  /**
   * @throws Exception
   */
  public function getDownloadFile(int $id): array
  {
    $file = $this->repo()->get('name,type,url', ['id' => $id]);
    if (is_file($this->filepath . $file['url'])) return $file;
    return [];
  }


  /**
   * @throws Exception
   */
  public function delFile(int|string $file, bool $deleteFile = true): int
  {
    if (is_numeric($file)) {
      $id = intval($file);
      if ($deleteFile) $filepath = $this->repo()->get('url', ['id' => $id]);
      $delNum = $this->repo()->delete(['id' => $id]);
      if ($delNum) {
        if (!empty($filepath) && is_file($this->filepath . $filepath)) unlink($this->filepath . $filepath); //删除文件
        return $delNum;
      } else {
        return 0;
      }
    } else {
      $id = $this->repo()->get('id', ['url' => $file]);
      if ($id) {
        $delNum = $this->repo()->delete(['id' => $id]);
        if ($delNum) {
          if ($deleteFile && is_file($this->filepath . $file)) unlink($this->filepath . $file); //删除文件
          return $delNum;
        } else {
          return 0;
        }
      } else {
        return 0;
      }
    }
  }

  /**
   * 允许上传文件类型
   * @param array $mimeTypes
   */
  public function allowFileType(array $mimeTypes): void
  {
    $this->mimeTypes = array_merge($this->mimeTypes, $mimeTypes);
  }

}
