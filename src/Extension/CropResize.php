<?php
/**
* @version 1.1
* @package System.cropresize plugin
* @author Mirosław Majka (mix@proask.pl)
* @copyright (C) 2024 Mirosław Majka <mix@proask.pl>
* @license GNU/GPL license: http://www.gnu.org/copyleft/gpl.html
**/

namespace Joomla\Plugin\System\CropResize\Extension;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Form\FormHelper;
use Joomla\CMS\Image\Image;
use Joomla\Filesystem\Folder;

\defined('_JEXEC') or die;

final class CropResize extends CMSPlugin
{
    protected $app;
    private $view;
    private $category;
    private $images = null;

    public function onContentPrepareForm(Form $form, $data): bool
    {
        $name = $form->getName();

        if (!\in_array($name, ['com_content.article', 'com_categories.categorycom_content'])) {
            return true;
        }

        $this->loadLanguage();

        FormHelper::addFieldPrefix('Joomla\\Plugin\\System\\CropResize\\Field');
        FormHelper::addFormPath(sprintf('%s/%s/%s/forms', JPATH_PLUGINS, $this->_type, $this->_name));

        if ($this->params->get('crop_images')) {
            switch ($name) {
                case 'com_categories.categorycom_content':
                    $form->loadFile('category_images_params', false);
                    break;

                case 'com_content.article':
                    $form->loadFile('article_images_params', false);
                    break;
            }
        }

        return true;
    }

    public function onContentPrepare($context, &$row, &$params, $page = 0)
    {
        if (!$this->params->get('crop_images')) {
            return true;
        }

        if (!empty($row->images)) {
            $this->images = json_decode($row->images);
        }

        switch ($context) {
            case 'com_content.category':
                if (null == $this->category) {
                    $this->category = $this->app
                        ->bootComponent('com_content')
                        ->getCategory(['published' => true, 'access' => false])
                        ->get($this->app->input->get('id'))
                        ->getParams();

                    $this->view     = $this->app->input->get('view');
                }

                if ((bool) $this->category->get('crop_introimage') && isset($row->id)) {
                    $size = null;

                    if (!empty($this->category->get('crop_introimage_width')) && !empty($this->category->get('crop_introimage_height'))) {
                        $size = sprintf(
                            '%sx%s',
                            $this->category->get('crop_introimage_width'),
                            $this->category->get('crop_introimage_height')
                        );
                    }

                    if (!(bool) $this->category->get('crop_override') && !empty($this->images->crop_introimage) && !empty($this->images->crop_introimage_width) && !empty($this->images->crop_introimage_height)) {
                        $size = sprintf(
                            '%sx%s',
                            $this->images->crop_introimage_width,
                            $this->images->crop_introimage_height
                        );
                    }

                    $this->images->image_intro = $this->prepareImage($row->id, $this->images->image_intro, ['size' => $size]);
                    $row->images               = json_encode($this->images);
                }

                break;

            case 'com_content.article':
                $this->view = $this->app->input->get('view');
                $size       = null;

                if (!empty($this->images->crop_introimage) && isset($row->id)) {
                    if (null != ($width = $this->images->crop_introimage_width) && null != ($height = $this->images->crop_introimage_height)) {
                        $size = sprintf('%sx%s', $width, $height);
                    }

                    $this->images->image_intro = $this->prepareImage($row->id, $this->images->image_intro, ['size' => $size]);
                }

                if (!empty($this->images->crop_fullimage) && isset($row->id)) {
                    if (null != ($width = $this->images->crop_fullimage_width) && null != ($height = $this->images->crop_fullimage_height)) {
                        $size = sprintf('%sx%s', $width, $height);
                    }

                    $this->images->image_fulltext = $this->prepareImage($row->id, $this->images->image_fulltext, ['size' => $size]);
                }

                if (!empty($this->images->crop_introimage) || !empty($this->images->crop_fullimage)) {
                    $row->images = json_encode($this->images);
                }

                break;
        }
    }

    private function prepareImage(int $itemId = null, string $image = null, ?array $params = null)
    {
        if (empty($itemId) || empty($image)) {
            return;
        }

        $cache = sprintf('cache/%s', $this->view);

        Folder::create(sprintf('%s/%s', JPATH_BASE, $cache));

        if (str_contains($image, '#')) {
            $lazy  = explode('#', $image);
            $image = $lazy[0];
        }

        $img            = new Image(sprintf('%s/%s', JPATH_BASE, $image));
        $pathInfo       = pathinfo($img->getPath());
        $filename       = $pathInfo['filename'];
        $fileExtension  = $pathInfo['extension'] ?? '';
        $property       = Image::getImageFileProperties($img->getPath());
        $width          = $property->width;
        $height         = $property->height;
        $size           = sprintf('%sx%s', $width, $height);
        $imageType      = $property->type;
        $creationMethod = Image::SCALE_INSIDE;

        if ($this->params->get('create_webp_format')) {
            $fileExtension = 'webp';
            $imageType     = IMAGETYPE_WEBP;
        }

        $filePath = sprintf('%s/%s/%s_%s.%s', JPATH_BASE, $cache, $itemId, $filename, $fileExtension);
        $image    = sprintf('%s/%s_%s.%s', $cache, $itemId, $filename, $fileExtension);
        $quality  = ($imageType == IMAGETYPE_PNG
            ? ['quality' => round($this->params->get('crop_images_quality') / 10)]
            : ($imageType == IMAGETYPE_GIF
                ? []
                : ['quality' => $this->params->get('crop_images_quality')]
            )
        );

        if (!\file_exists($filePath)) {
            if ($this->params->get('crop_images')) {
                $width          = $this->params->get('crop_images_width');
                $height         = $this->params->get('crop_images_height');
                $size           = (isset($params['size'])) ? $params['size'] : sprintf('%sx%s', $width, $height);
                $creationMethod = Image::CROP_RESIZE;
            }

            if ($thumb = current($img->generateThumbs($size, $creationMethod))) {
                if ($this->params->get('watermark') && !empty($this->params->get('watermark_image'))) {
                    $watermark_image = new Image(JPATH_BASE.'/'.substr($this->params->get('watermark_image'), 0, strpos($this->params->get('watermark_image'), '#')));
                    $thumb->watermark(
                        $watermark_image,
                        $this->params->get('watermark_opacity'),
                        $this->params->get('watermark_mb'),
                        $this->params->get('watermark_me')
                    );
                }

                if ($thumb->toFile($filePath, $imageType, $quality)) {
                    $image = sprintf('%s/%s_%s.%s', $cache, $itemId, $filename, $fileExtension);
                }
            }
        }

        if (isset($params['size'])) {
            list($width, $height) = explode('x', $params['size']);
        }

        if (!empty($lazy[1])) {
            $image = sprintf('%s#joomlaImage://local-%s?width=%s&height=%s', $image, substr($image, 1), $width, $height);
        }

        return $image;
    }
}
