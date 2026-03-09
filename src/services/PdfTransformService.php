<?php
/**
 * PDF Transform plugin for Craft CMS
 *
 * @link      http://bymayo.co.uk
 * @copyright Copyright (c) 2018 ByMayo
 */

namespace bymayo\pdftransform\services;

use bymayo\pdftransform\PdfTransform;

use Craft;
use craft\base\Component;
use craft\helpers\App;
use Yii;
use craft\services\Volumes;
use Spatie\PdfToImage\Pdf;
use craft\elements\Asset;

/**
 * @author    ByMayo
 * @package   PdfTransform
 * @since     1.0.0
 */
class PdfTransformService extends Component
{

  private $settings;

    // Public Methods
    // =========================================================================

    public function __construct() {

      $this->settings = PdfTransform::$plugin->getSettings();
      
    }

    public function getVolumeOptions()
    {

      $volumesArray = array();
      $volumes = new Volumes;

      foreach ($volumes->getAllVolumes() as $volume) {

        $volumeArray = array();
        $volumeArray['label'] = $volume->name;
        $volumeArray['value'] = $volume->id;
        array_push($volumesArray, $volumeArray);

      }

      return $volumesArray;

    }

    public function getImageVolume()
    {
      $imageVolumeId = $this->settings->imageVolume;
      $volume = Craft::$app->getVolumes()->getVolumeById($imageVolumeId);
      return $volume;
   }

   public function getImageFs()
   {
     $volume = $this->getImageVolume();
     $fs = $volume->getFs();
     return $fs;
  }

   public function getFileName($asset)
   {
      // e.g. filename-12345.jpg
      return $asset->filename . '-' . $asset->id . '.' . $this->settings->imageFormat;
   }

   private function getImageSubpath(): string
   {
     $subpath = trim((string)$this->settings->imageSubpath);
     return trim($subpath, "/\\");
   }

   private function getOutputFolder()
   {
     $volume = $this->getImageVolume();
     $subpath = $this->getImageSubpath();

     if ($subpath === '') {
       return Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id);
     }

     return Craft::$app->getAssets()->ensureFolderByFullPathAndVolume($subpath, $volume, false);
   }

   private function getOutputPath(string $filename): string
   {
     $subpath = $this->getImageSubpath();
     if ($subpath === '') {
       return $filename;
     }

     return $subpath . '/' . $filename;
   }

   public function render($asset)
   {

    $volume = $this->getImageVolume();
     $fs = $this->getImageFs();
     $fileName = $this->getFileName($asset);
     $folder = $this->getOutputFolder();
     $outputPath = $this->getOutputPath($fileName);

     if ($fs->fileExists($outputPath)) {
       
       $transformedAsset = Asset::find()
         ->volumeId($volume->id)
         ->folderId($folder->id)
         ->filename($fileName)
         ->one();

       return $transformedAsset;

     }

     $sourceVolumes = $this->settings->sourceVolumes;
     if (!empty($sourceVolumes) && !in_array($asset->volumeId, $sourceVolumes)) {
         return null;
     }

     return $this->pdfToImage(
       $asset
     );

   }

   public function pdfToImageBAK($asset)
   {

     $filename = $this->getFileName($asset);
     $volume = $this->getImageVolume();

     $pathService = Craft::$app->getPath();
     $tempPath = $pathService->getTempPath(true) . '/' . mt_rand(0, 9999999) . '.png';
     file_put_contents($tempPath, file_get_contents($asset->url));

     $tempPathTransform = $pathService->getTempPath(true) . '/' . $filename;

     $folder = $this->getOutputFolder();

     $pdf = new Pdf($tempPath);

     $pdf
       ->setPage($this->settings->page)
       ->setResolution($this->settings->imageResolution)
       ->setCompressionQuality($this->settings->imageQuality)
       ->saveImage($tempPathTransform);

     $assetTransformed = new Asset();
     $assetTransformed->tempFilePath = $tempPathTransform;
     $assetTransformed->filename = $filename;
     $assetTransformed->folderId = $folder->id;
     $assetTransformed->newFolderId = $folder->id;
     $assetTransformed->kind = 'Image';
     $assetTransformed->title = $asset->title;
     $assetTransformed->avoidFilenameConflicts = true;
     $assetTransformed->setVolumeId($volume->id);
     $assetTransformed->setScenario(Asset::SCENARIO_CREATE);

     $assetTransformed->validate();
       
       if (Craft::$app->getElements()->saveElement($assetTransformed, false))
       {
         return $assetTransformed;
       }
   }

    public function pdfToImage($asset)
    {
        $filename = $this->getFileName($asset);
        $volume = $this->getImageVolume();

        $pathService = Craft::$app->getPath();
        $tempPath = $pathService->getTempPath(true) . '/' . mt_rand(0, 9999999) . '.png';
        file_put_contents($tempPath, file_get_contents($asset->url));

        $tempPathTransform = $pathService->getTempPath(true) . '/' . $filename;
        $folder = $this->getOutputFolder();

        $pdf = new Pdf($tempPath);
        $pdf->setPage($this->settings->page)
            ->setResolution($this->settings->imageResolution)
            ->setCompressionQuality($this->settings->imageQuality);

        // 1. Get the Imagick object instead of calling saveImage()
        $imagick = $pdf->getImageData($tempPathTransform);

        // 2. Apply ICC Profiles if the source is CMYK
        if ($imagick->getImageColorspace() == \Imagick::COLORSPACE_CMYK) {
            $srgbProfile = '/usr/share/color/icc/colord/sRGB.icc';
            $cmykProfile = '/usr/share/color/icc/colord/FOGRA39L_coated.icc';

            // Apply input profile (CMYK)
            if (file_exists($cmykProfile)) {
                $imagick->profileImage('icc', file_get_contents($cmykProfile));
            }

            // Apply output profile (sRGB)
            if (file_exists($srgbProfile)) {
                $imagick->profileImage('icc', file_get_contents($srgbProfile));
            }

            // Finalize colorspace
            $imagick->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
        }

        // 3. Manually save the modified image
        $imagick->writeImage($tempPathTransform);

        // Rest of the existing Craft Asset saving logic...
        $assetTransformed = new Asset();
        $assetTransformed->tempFilePath = $tempPathTransform;
        $assetTransformed->filename = $filename;
        $assetTransformed->folderId = $folder->id;
        $assetTransformed->newFolderId = $folder->id;
        $assetTransformed->kind = 'Image';
        $assetTransformed->title = $asset->title;
        $assetTransformed->avoidFilenameConflicts = true;
        $assetTransformed->setVolumeId($volume->id);
        $assetTransformed->setScenario(Asset::SCENARIO_CREATE);

        $assetTransformed->validate();

        if (Craft::$app->getElements()->saveElement($assetTransformed, false))
        {
            return $assetTransformed;
        }
    }

}
