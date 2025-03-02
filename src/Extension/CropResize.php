<?php
/**
 * @version 1.2
 * @package System.cropresize plugin
 * @author Mirosław Majka (mix@proask.pl)
 * @copyright (C) 2024 Mirosław Majka <mix@proask.pl>
 * @license GNU/GPL license: http://www.gnu.org/copyleft/gpl.html
 **/

namespace Joomla\Plugin\System\CropResize\Extension;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Form\FormHelper;
use Joomla\CMS\Image\Image;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\Filesystem\Folder;

\defined('_JEXEC') or die;

final class CropResize extends CMSPlugin
{
    protected $app;
    protected $doc;
    private $category;
    private $menu;
    private $tag;

    private $images = null;

    private $names  = [
        'com_content.article',
        'com_categories.categorycom_content',
        'com_tags.tag',
        'com_tags.tags',
        'com_menus.item'
    ];

    private $views  = [
        'tag',
        'tags'
    ];

    public function onContentPrepareForm(Form $form, $data): bool
    {
        $name = $form->getName();

        if (!\in_array($name, $this->names)) {
            return true;
        }

        if (
            'com_menus.item' == $name
            && (
                !isset($data->params, $data->params['option'], $data->params['view'])
                || 'com_tags' != $data->params['option']
                || !\in_array($data->params['view'], $this->views)
            )
        ) {
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

                case 'com_menus.item':
                    $form->loadFile('tagsmenu_images_params', false);
                    break;

                case 'com_tags.tag':
                    $form->loadFile('tag_images_params', false);
                    break;
            }
        }

        return true;
    }

    public function onBeforeRender()
    {
        if (
            !$this->app->isClient('site')
            || 'com_tags' != $this->app->input->get('option')
            || 'tags' != $this->app->input->get('view')
            || !$this->params->get('crop_images')
        ) {
            return true;
        }

        $model = $this->app->bootComponent('com_tags')->getMVCFactory()->createModel('Tags', 'Site');
        $items = $model->getItems();

        if (empty($items)) {
            return true;
        }

        $search  = [];
        $replace = [];

        foreach ($items as $item) {
            if ((empty($item->images) || $item->images === '{}') && (empty($item->core_images) || $item->core_images === '{}')) {
                continue;
            }

            $this->images = json_decode($item->images ?? $item->core_images);

            if (empty($this->images->image_intro) && empty($this->images->image_fulltext)) {
                continue;
            }

            if (null == $this->menu) {
                $this->menu = $this->app->getMenu()->getActive()->getParams();
            }

            if (!empty($this->menu->get('crop_introimage'))) {
                $size = $this->setSize($this->menu->get('crop_introimage_width'), $this->menu->get('crop_introimage_height'));
            } elseif (!empty($this->images->crop_introimage)) {
                $size = $this->setSize($this->images->crop_introimage_width, $this->images->crop_introimage_height);
            } else {
                $size = null;
            }

            if (!empty($this->menu->get('crop_introimage') || !empty($this->images->crop_introimage))) {
                $search[]  = htmlentities($this->images->image_intro);
                $replace[] = htmlentities($this->prepareImage($item->id, $this->images->image_intro, ['size' => $size]));
            }

            if (!empty($this->menu->get('crop_fullimage'))) {
                $size = $this->setSize($this->menu->get('crop_introimage_width'), $this->menu->get('crop_introimage_height'));
            } elseif (!empty($this->images->crop_fullimage)) {
                $size = $this->setSize($this->images->crop_fullimage_width, $this->images->crop_fullimage_height);
            } else {
                $size = null;
            }

            if (!empty($this->menu->get('crop_fullimage') || !empty($this->images->crop_fullimage))) {
                $search[]  = htmlentities($this->images->image_intro);
                $replace[] = htmlentities($this->prepareImage($item->id, $this->images->image_fulltext, ['size' => $size]));
            }
        }

        if (empty($search) || empty($replace)) {
            return true;
        }

        $document = Factory::getDocument();
        $output   = $document->getBuffer('component');
        $output   = str_replace($search, $replace, $output);

        $document->setBuffer($output, 'component');

        return true;
    }

    public function onContentPrepare($context, &$row, &$params, $page = 0): bool
    {
        if (!$this->params->get('crop_images')) {
            return true;
        }

        if (!empty($row->images) || !empty($row->core_images)) {
            $this->images = json_decode($row->images ?? $row->core_images);
        }

        switch ($context) {
            case 'com_content.category':
                if (null == $this->category) {
                    $this->category = $this->app->bootComponent('com_content')->getCategory(['published' => true, 'access' => false])->get($this->app->input->get('id'))->getParams();
                }

                if ((bool) $this->category->get('crop_introimage') && isset($row->id)) {
                    $size = $this->setSize($this->category->get('crop_introimage_width'), $this->category->get('crop_introimage_height'));

                    if (!(bool) $this->category->get('crop_override')) {
                        $size = $this->setSize($this->images->crop_introimage_width, $this->images->crop_introimage_height);
                    }

                    $this->images->image_intro = $this->prepareImage($row->id, $this->images->image_intro, ['size' => $size]);
                    $row->images               = json_encode($this->images);
                }

                break;

            case 'com_tags.tag':
                if (null == $this->menu) {
                    $this->menu = $this->app->getMenu()->getActive()->getParams();
                }

                if (isset($row->content_item_id)) {
                    $this->images->image_intro = $this->prepareImage(
                        $row->content_item_id,
                        $this->images->image_intro,
                        (bool) $this->menu->get('crop_introimage')
                            ? ['size' => $this->setSize($this->menu->get('crop_introimage_width'), $this->menu->get('crop_introimage_height'))]
                            : null
                    );

                    if (!empty($this->menu->get('crop_fullimage'))) {
                        $this->images->image_fulltext = $this->prepareImage(
                            $row->content_item_id,
                            $this->images->image_fulltext,
                            [
                                'size' => $this->setSize($this->menu->get('crop_fullimage_width'), $this->menu->get('crop_fullimage_width'))
                            ]
                        );
                    }
                }

                if (!empty($this->menu->get('crop_introimage')) || !empty($this->menu->get('crop_fullimage'))) {
                    $row->core_images = json_encode($this->images);
                }


                break;

            case 'com_content.article':
                if (isset($row->id)) {
                    if (!empty($this->images->crop_introimage)) {
                        $this->images->image_intro = $this->prepareImage(
                            $row->id,
                            $this->images->image_intro,
                            [
                                'size' => $this->setSize($this->images->crop_introimage_width, $this->images->crop_introimage_height)
                            ]
                        );
                    }

                    if (!empty($this->images->crop_fullimage)) {
                        $this->images->image_fulltext = $this->prepareImage(
                            $row->id,
                            $this->images->image_fulltext,
                            [
                                'size' => $this->setSize($this->images->crop_fullimage_width, $this->images->crop_fullimage_height)
                            ]
                        );
                    }
                }

                if (!empty($this->images->crop_introimage) || !empty($this->images->crop_fullimage)) {
                    $row->images = json_encode($this->images);
                }

                break;
        }

        return true;
    }


    private function setSize(?int $width, ?int $height): ?string
    {
        if (null == ($width) || null == $height) {
            return null;
        }

        return sprintf('%sx%s', $width, $height);
    }

    private function prepareImage(int $itemId = null, string $image = null, ?array $params = null)
    {
        if (empty($itemId) || empty($image)) {
            return;
        }

        $cache = sprintf('cache/%s', $this->app->input->get('view'));

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
                $width  = $this->params->get('crop_images_width');
                $height = $this->params->get('crop_images_height');

                if (!empty($params['size'])) {
                    $size = $params['size'];
                    list($width, $height) = explode('x', $params['size']);
                } else {
                    $size = sprintf('%sx%s', $width, $height);
                }

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
