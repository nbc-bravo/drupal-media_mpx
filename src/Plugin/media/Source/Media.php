<?php

namespace Drupal\media_mpx\Plugin\media\Source;

use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\media\MediaInterface;
use Drupal\media\MediaSourceInterface;
use GuzzleHttp\Exception\TransferException;
use Lullabot\Mpx\DataService\Media\Media as MpxMedia;
use Psr\Http\Message\UriInterface;

/**
 * Media source for mpx Media items.
 *
 * @see \Lullabot\Mpx\DataService\Media\Media
 * @see https://docs.theplatform.com/help/media-media-object
 *
 * @MediaSource(
 *   id = "media_mpx_media",
 *   label = @Translation("mpx Media"),
 *   description = @Translation("mpx media data, such as videos."),
 *   allowed_field_types = {"string"},
 *   default_thumbnail_filename = "video.png",
 *   thumbnail_alt_metadata_attribute="thumbnail_alt",
 *   default_thumbnail_filename = "video.png",
 *   media_mpx = {
 *     "service_name" = "Media Data Service",
 *     "object_type" = "Media",
 *     "schema_version" = "1.10",
 *   },
 * )
 */
class Media extends MediaSourceBase implements MediaSourceInterface {

  /**
   * The path to the thumbnails directory.
   *
   * Normally this would be a class constant, but file_prepare_directory()
   * requires the string to be passed by reference.
   *
   * @var string
   */
  private $thumbnailsDirectory = 'public://media_mpx/thumbnails/';

  /**
   * {@inheritdoc}
   */
  public function getMetadataAttributes() {
    $extractor = $this->propertyExtractor();

    $metadata = [];
    foreach ($extractor->getProperties(MpxMedia::class) as $property) {
      $label = sprintf('(%s)', $property);
      if ($shortDescription = $extractor->getShortDescription(MpxMedia::class, $property)) {
        $label = $shortDescription . ' ' . $label;
      }
      $metadata[$property] = $label;
    }

    $service_info = $this->getPluginDefinition()['media_mpx'];
    $fields = $this->customFieldManager->getCustomFields();
    /**
     * @var string $namespace
     * @var \Lullabot\Mpx\DataService\DiscoveredCustomField $discoveredCustomField
     */
    foreach ($fields[$service_info['service_name']][$service_info['object_type']] as $namespace => $discoveredCustomField) {
      $class = $discoveredCustomField->getClass();
      $namespace = $discoveredCustomField->getAnnotation()->namespace;
      foreach ($extractor->getProperties($class) as $property) {
        $label = sprintf('(%s:%s)', $namespace, $property);
        if ($shortDescription = $extractor->getShortDescription($class, $property)) {
          $label = $shortDescription . ' ' . $label;
        }
        $metadata[str_replace('.', '%2E', $namespace) . '/' . $property] = $label;
      }
    }

    return $metadata;
  }

  /**
   * {@inheritdoc}
   */
  public function getMetadata(MediaInterface $media, $attribute_name) {
    // Load the media type.
    /** @var \Drupal\media\MediaTypeInterface $media_type */
    $media_type = $this->entityTypeManager->getStorage('media_type')->load($media->bundle());
    $source_field = $this->getSourceFieldDefinition($media_type);
    if (!$media->get($source_field->getName())->isEmpty()) {

      switch ($attribute_name) {
        case 'thumbnail_uri':
          /** @var \Lullabot\Mpx\DataService\Media\Media $mpx_media */
          $mpx_media = $this->getMpxObject($media);
          return $this->downloadThumbnail($media, $attribute_name, $mpx_media->getDefaultThumbnailUrl());

        case 'thumbnail_alt':
          return $this->thumbnailAlt($media);
      }

      $extractor = $this->propertyExtractor();

      if (in_array($attribute_name, $extractor->getProperties(MpxMedia::class))) {
        /** @var \Lullabot\Mpx\DataService\Media\Media $mpx_media */
        $mpx_media = $this->getMpxObject($media);
        return $this->getReflectedProperty($media, $attribute_name, $mpx_media);
      }

      // Now check for custom fields.
      $service_info = $this->getPluginDefinition()['media_mpx'];
      $fields = $this->customFieldManager->getCustomFields();
      $properties = [];
      /**
       * @var string $namespace
       * @var \Lullabot\Mpx\DataService\DiscoveredCustomField $discoveredCustomField
       */
      foreach ($fields[$service_info['service_name']][$service_info['object_type']] as $namespace => $discoveredCustomField) {
        $class = $discoveredCustomField->getClass();
        $namespace = $discoveredCustomField->getAnnotation()->namespace;
        $properties[$namespace] = $extractor->getProperties($class);
      }
      $decoded = str_replace('%2E', '.', $attribute_name);
      $parts = explode('/', $decoded);
      $field = array_pop($parts);
      $namespace = implode('/', $parts);
      if (in_array($namespace, array_keys($properties))) {
        $mpx_media = $this->getMpxObject($media);
        $method = 'get' . ucfirst($field);
        return $mpx_media->getCustomFields($namespace)->$method();
      }
    };

    return parent::getMetadata($media, $attribute_name);
  }

  /**
   * Download a thumbnail to the local file system.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media entity being accessed.
   * @param string $attribute_name
   *   The metadata attribute being accessed.
   * @param \Psr\Http\Message\UriInterface $uri
   *   The URI of the thumbnail to download.
   *
   * @return string
   *   The existing thumbnail, or the newly downloaded thumbnail.
   */
  private function downloadThumbnail(MediaInterface $media, string $attribute_name, UriInterface $uri) {
    try {
      $local_uri = $this->thumbnailsDirectory . $uri->getHost() . $uri->getPath();
      if (!file_exists($local_uri)) {
        $directory = dirname($local_uri);
        file_prepare_directory($directory, FILE_CREATE_DIRECTORY);
        $thumbnail = $this->httpClient->request('GET', $uri);
        file_unmanaged_save_data((string) $thumbnail->getBody(), $local_uri);
      }

      return $local_uri;
    }
    catch (TransferException $e) {
      /** @var \Lullabot\Mpx\DataService\Media\Media $mpx_media */
      $mpx_media = $this->getMpxObject($media);
      // @todo Can this somehow deeplink to the mpx console?
      $link = Link::fromTextAndUrl($this->t('link to mpx object'), Url::fromUri($mpx_media->getId()))->toString();
      $this->logger->error('An error occurred while downloading the thumbnail for @title: HTTP @code @message', [
        '@title' => $media->label(),
        '@code' => $e->getCode(),
        '@message' => $e->getMessage(),
        'link' => $link,
      ]);
      return parent::getMetadata($media, $attribute_name);
    }
  }

  /**
   * Return the alt tag for a thumbnail.
   *
   * While mpx has support for thumbnail descriptions, in practice they do not
   * look to be filled with useful text. Instead, we default to using the media
   * label, and if that is not available we fall back to the media title.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media entity being processed.
   *
   * @return string
   *   The thumbnail alt text.
   */
  private function thumbnailAlt(MediaInterface $media) {
    /** @var \Lullabot\Mpx\DataService\Media\Media $mpx_media */
    $mpx_media = $this->getMpxObject($media);
    if (!empty($media->label())) {
      return $media->label();
    }
    return $mpx_media->getTitle();
  }

}
