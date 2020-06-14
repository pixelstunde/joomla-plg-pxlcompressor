<?php
/**
 * @Copyright
 * @package        PXLCompressor
 * @author         Christian Friedemann <c.friedemann@pixelstun.de>, Viktor Vogel <admin@kubik-rubik.de>
 * @version        1.2.1 - 2019-03-11
 * @link           https://pixelstun.de/blog/pxlcompressor
 * @todo           Add consistency in variable names
 *
 * @license        GNU/GPL
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 * @copyright      pixelstun.de
 */

use Ilovepdf\
{
	Exceptions\AuthException,
	Exceptions\DownloadException,
	Exceptions\ProcessException,
	Exceptions\StartException,
	Exceptions\UploadException,
	Ilovepdf
};
use Joomla\CMS\
{
	Date\Date,
	Factory,
	Filesystem\File,
	Filesystem\Folder,
	Http\Http,
	Http\HttpFactory,
	Language\Text,
	Language\Transliterate,
	Plugin\CMSPlugin,
	Uri\Uri
};
use Joomla\Image\Image;
use Joomla\Input\Files;

defined('_JEXEC') or die('Restricted access');

require_once(__DIR__ . '/libs/ilovepdf/init.php');

class PlgSystemPxlcompressor extends CMSPlugin
{
	protected $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif'];
	protected $addDateTimeToFileName = false;
	protected $compressPDF = false;
	protected $compressImages = false;
	protected $compressionPng = 100;
	protected $compressionState = false;
	protected $enlargeImages = false;
	protected $multisizePath = '.';
	protected $overrideUploadStructure = false;
	protected $qualityJpg = 90;
	protected $scaleMethod = 1;
	protected $triggerOn = ['com_media.file', 'com_jce.file'];

	function __construct(&$subject, $config)
	{
		parent::__construct($subject, $config);
		$this->compressImages          = (bool) $this->params->get('compressExternal');
		$this->compressPDF             = (bool) $this->params->get('compressPDF');
		$this->overrideUploadStructure = (bool) $this->params->get('overrideUploadStructure');
		$this->addDateTimeToFileName   = (bool) $this->params->get('addDateTimeToFileName');
	}

	/**
	 * Optimizes generic image uploads and makes image names safe in the trigger onAfterInitialise()
	 * @since  1.0
	 */
	public function onAfterInitialise()
	{
		if ($this->params->get('safe_names', 1))
		{
			$this->makeNameSafe();
		}
	}

	/**
	 * Plugin uses the trigger onContentAfterSave to manipulate the multisize images
	 *
	 * @param string  $context
	 * @param object  $object
	 * @param boolean $state
	 *
	 * @throws Exception
	 * @since 1.0
	 */
	public function onContentAfterSave($context, $object, $state)
	{
		//load language
		$this->loadLanguage('', JPATH_BASE);


		if ($this->checkContext($context) && $this->checkObject($object) && $state == true)
		{

			if ($this->compressImages || $this->compressPDF)
			{
				$this->compressFile($object);
			}
			else
			{
				if ($this->compressionState)
				{
					$this->successMessage($object->size, filesize($object->filepath));
				}
			}

			$multisizes = $this->params->get('multisizes');

			if (!empty($multisizes))
			{
				$multisizesLines = array();
				$this->createThumbnailFolder($object->filepath);
				$multisizes = array_map('trim', explode("\n", $multisizes));

				foreach ($multisizes as $multisizesLline)
				{
					$multisizesLines[] = array_map('trim', explode('|', $multisizesLline));
				}

				foreach ($multisizesLines as $multisizeLine)
				{
					// At least one value has to be set and not negative to execute the resizing process
					if ((!empty($multisizeLine[0]) && $multisizeLine[0] >= 0) || (!empty($multisizeLine[1]) && $multisizeLine[1] >= 0))
					{
						$multisizeSuffix = false;

						if (!empty($multisizeLine[2]))
						{
							$multisizeSuffix = htmlspecialchars($multisizeLine[2]);
						}

						$multisizeScaleMethod = $this->scaleMethod;

						if (!empty($multisizeLine[3]))
						{
							if (in_array((int) $multisizeLine[3], array(1, 2, 3, 4, 5, 6)))
							{
								$multisizeScaleMethod = (int) $multisizeLine[3];
							}
						}

						$this->resizeImage($object, $object->filepath, $multisizeLine[0], $multisizeLine[1], true, $multisizeSuffix, $multisizeScaleMethod);

						if ($this->compressImages)
						{
							$this->compressFile($object);
						}
					}
				}
			}
		}
	}


	/**
	 * Plugin uses the trigger onContentBeforeSave to manipulate the uploaded images
	 *
	 * @param string  $context
	 * @param object  $object
	 * @param boolean $state
	 *
	 * @since 1.0
	 */
	public function onContentBeforeSave($context, $object, $state)
	{
		if ($this->checkContext($context) && $this->checkObject($object) && true == $state)
		{
			$this->setQualityJpg();
			$this->setCompressionPng();
			$this->scaleMethod   = (int) $this->params->get('scale_method', 2);
			$this->enlargeImages = (int) $this->params->get('enlarge_images ', 0);
			$width               = (int) $this->params->get('width', 0);
			$height              = (int) $this->params->get('height', 0);

			// At least one value has to be set and not negative to execute the resizing process
			if ((!empty($width) && $width >= 0) || (!empty($height) && $height >= 0))
			{
				if ($this->overrideUploadStructure)
				{
					$object->filepath = $this->overrideUploadDirectory() . '/' . $object->name;
				}

				if ($this->addDateTimeToFileName)
				{
					$date             = (new Date())->format('Ymd-Hi_');
					$object->name     = $date . $object->name;
					$object->filepath = pathinfo($object->filepath)['dirname'] . '/' . $object->name;
				}

				if (false !== $this->resizeImage($object, $object->tmp_name, $width, $height, false))
				{
					$this->compressionState = true;
				}
			}
		}
	}

	public function overrideUploadDirectory()
	{
		$date = new Date();

		$year  = $date->format('Y');
		$month = $date->format('m');
		$day   = $date->format('d');

		$path = JPATH_ROOT . '/images/' . $year . '/' . $month . '/' . $day;
		if (!Folder::exists($path))
		{
			Folder::create($path);
		}

		return $path;
	}

	/**
	 * Check if object is empty and fix mime type if not set
	 *
	 * @param $object
	 *
	 * @return bool
	 * @since 1.2
	 */
	private function checkObject(&$object)
	{
		if (!empty($object) && is_object($object))
		{
			if (empty($object->type))
			{
				//jce does not seem give type information as com_media, so we'll guess by extension
				$moved = mime_content_type($object->filepath);

				if (!empty($moved))
				{
					$object->type = $moved;
				}
				else
				{
					$object->type = mime_content_type($object->tmp_name);
				}
			}

			return true;
		}

		return false;
	}

	/**
	 * Checks and triggers file compression
	 *
	 * @param      $object
	 * @param bool $showMessage
	 *
	 * @throws Exception
	 * @since 1.0
	 */
	protected function compressFile($object, $showMessage = true)
	{

		$result = false;

		if (in_array($object->type, $this->allowedMimeTypes) && $this->compressImages)
		{

			if (false == $result && ($this->params->get('tinyPNG', '0') == '1'))
			{
				$result = $this->compressFileTinyPNG($object);
				if ($result !== false && $showMessage)
				{
					$this->successMessage($object->size, $result, 'TinyPNG');
				}
				else
				{
					$this->errorMessage('TinyPNG');
				}
			}

			if (false == $result && $this->params->get('resmush'))
			{
				$result = $this->compressFileResmush($object);
				if (false !== $result && $showMessage)
				{
					$this->successMessage($object->size, $result, 'resmush');
				}
				else
				{
					$this->errorMessage('resmush');
				}
			}
		}
		else if ('application/pdf' == $object->type && $this->compressPDF)
		{
			$this->compressFileILovePDF($object);
		}
	}

	/**
	 * Compress file using a service provider
	 *
	 * @param object $object
	 *
	 * @return bool success
	 * @throws Exception
	 * @since 1.0
	 */
	protected function compressFileResmush($object)
	{
		$metadata = '';

		if ($this->params->get('preserveMetadata', '0'))
		{
			$metadata = '&exif=true';
		}

		//file exceeds 5MiB, filesize needs to be checked again since it could have been modified by resize
		if (5242879 < filesize($object->path))
		{
			Factory::getApplication()->enqueueMessage(Text::_('PLG_PXLCOMPRESSOR_FILESIZE_EXCEEDED'), 'error');

			return null;
		}

		$supportedMimes = array(
			'image/bmp',
			'image/gif',
			'image/jpeg',
			'image/png',
			'image/tiff',
		);
		if (!in_array($object->type, $supportedMimes))
		{
			Factory::getApplication()->enqueueMessage(Text::_('PLG_PXLCOMPRESSOR_NOT_SUPPORTED'), 'error');

			return null;
		}

		$endpoint     = 'http://api.resmush.it/ws.php';
		$externalPath = urlencode($this->externalURL($object->filepath));
		$url          = $endpoint . '?img=' . $externalPath . $metadata;

		$httpInterface = new Http;
		$response      = $httpInterface->get($url);

		if (200 == $response->code)
		{
			$json = json_decode($response->body);
			if (empty ($json->error))
			{
				$image = $httpInterface->get($json->dest)->body;
				file_put_contents($object->filepath, $image);

				return $json->dest_size;
			}
		}

		return false;
	}

	/**
	 * Compress file using a service provider
	 *
	 * @param Object $object
	 *
	 * @return bool success
	 * @throws Exception
	 * @since 1.0
	 */
	protected function compressFileTinyPNG($object)
	{
		$supportedMimes = array(
			'image/gif',
			'image/jpeg',
			'image/png',
		);
		if (!in_array($object->type, $supportedMimes))
		{
			Factory::getApplication()->enqueueMessage(Text::_('PLG_PXLCOMPRESSOR_NOT_SUPPORTED'), 'error');

			return null;
		}

		$endpoint = 'https://api.tinify.com/shrink';
		$apiKey   = $this->params->get('tinyPNGApiKey', '');

		$httpInterface = HttpFactory::getHttp();
		//html basic authorization
		$httpInterface->setOption('headers.Authorization', 'Basic ' . base64_encode('api:' . $apiKey));
		$response = $httpInterface->post($endpoint, file_get_contents($object->filepath));

		if (200 == $response->code || 201 == $response->code)
		{
			$json = json_decode($response->body);
			if (isset ($json->error))
			{
				Factory::getApplication()->enqueueMessage(Text::_('PLG_PXLCOMPRESSOR_COMPRESSION_ERROR'), 'error');

				return null;
			}
			else
			{
				//reset iface
				$httpInterface = HttpFactory::getHttp();
				$image         = $httpInterface->get($json->output->url)->body;
				file_put_contents($object->filepath, $image);

				return $json->output->size;
			}
		}

		return false;
	}

	/**
	 * Compresses a pdf file
	 *
	 * @param $object File object to compress
	 *
	 * @throws Exception
	 * @since 1.1
	 */
	private function compressFileILovePDF($object)
	{
		$error = '';

		try
		{
			$ilovepdf = new Ilovepdf($this->params->get('ilovepdfPublic', ''), $this->params->get('ilovepdfSecret', ''));
			$myTask   = $ilovepdf->newTask('compress');
			$myTask->addFile($object->filepath);
			$myTask->execute();
			$myTask->download(dirname($object->filepath));
		}
		catch (StartException $e)
		{
			$error .= "An error occured on start: " . $e->getMessage() . " ";
			// Authentication errors
		}
		catch (AuthException $e)
		{
			$error .= "An error occured on auth: " . $e->getMessage() . " ";
			$error .= implode(', ', $e->getErrors());
			// Uploading files errors
		}
		catch (UploadException $e)
		{
			$error .= "An error occured on upload: " . $e->getMessage() . " ";
			$error .= implode(', ', $e->getErrors());
			// Processing files errors
		}
		catch (ProcessException $e)
		{
			$error .= "An error occured on process: " . $e->getMessage() . " ";
			$error .= implode(', ', $e->getErrors());
			// Downloading files errors
		}
		catch (DownloadException $e)
		{
			$error .= "An error occured on process: " . $e->getMessage() . " ";
			$error .= implode(', ', $e->getErrors());
			// Other errors (as connexion errors and other)
		}
		catch (Exception $e)
		{
			$error .= "An error occured: " . $e->getMessage();
		}
		if (!empty($error))
		{
			Factory::getApplication()->enqueueMessage(Text::_('PLG_PXLCOMPRESSOR_COMPRESSION_ERROR') . $error, 'error');
		}
		else
		{
			$this->successMessage($object->size, filesize($object->filepath));
		}
	}


	/**
	 * Creates thumbnail folder if it does not exist yet
	 *
	 * @param $imagePathOriginal
	 *
	 * @since 1.0
	 */
	private function createThumbnailFolder($imagePathOriginal)
	{
		$this->multisizePath = dirname($imagePathOriginal);
		$multisizePath       = $this->params->get('multisize_path', '');

		if (!empty($multisizePath))
		{
			$this->multisizePath .= '/' . $multisizePath;
		}

		if (!Folder::exists($this->multisizePath))
		{
			Folder::create($this->multisizePath);
		}
	}

	/**
	 * Enqueue error message
	 *
	 * @param string $service compression service used
	 *
	 * @throws Exception
	 * @since 1.0
	 */

	protected function errorMessage($service)
	{
		Factory::getApplication()->enqueueMessage(
			Text::_('PLG_PXLCOMPRESSOR_COMPRESSION_SERVICE_FAILED') . ' ' . $service
			, 'error');
	}

	/**
	 * Get external url of a given file
	 *
	 * @param string $localFile path to local file
	 *
	 * @return string external url
	 * @since 1.0
	 */
	protected function externalURL($localFile)
	{
		return Uri::root() . substr(str_replace(JPATH_ROOT, '', $localFile), 1);
	}


	/**
	 * Gets needed information for the image manipulating process
	 *
	 * @param string $mime_type
	 *
	 * @return array
	 * @since 1.0
	 */
	private function getImageInformation($mime_type)
	{
		$imageInformation = array('type' => IMAGETYPE_JPEG, 'quality' => $this->qualityJpg);

		if ('image/gif' == $mime_type)
		{
			$imageInformation = array('type' => IMAGETYPE_GIF, 'quality' => '');
		}
		else if ('image/png' == $mime_type)
		{
			$imageInformation = array('type' => IMAGETYPE_PNG, 'quality' => $this->compressionPng);
		}

		return $imageInformation;
	}


	/**
	 * Creates the full path, including the name of the thumbnail
	 *
	 * @param $imagePathOriginal
	 * @param $width
	 * @param $height
	 * @param $multisizeSuffix
	 *
	 * @return string
	 * @since 1.0
	 */
	private function getThumbnailPath($imagePathOriginal, $width, $height, $multisizeSuffix = false)
	{
		$image_extension     = '.' . File::getExt(basename($imagePathOriginal));
		$image_name_original = basename($imagePathOriginal, $image_extension);

		$image_name_suffix = $width . 'w';

		if (!empty($multisizeSuffix))
		{
			$image_name_suffix = $multisizeSuffix;
		}

		return $this->multisizePath . '/' . $image_name_original . '-' . $image_name_suffix . $image_extension;
	}

	/**
	 * Creates safe image names for the Media Manager
	 *
	 * @throws Exception
	 * @since 1.0
	 */
	private function makeNameSafe()
	{
		$input   = Factory::getApplication()->input;
		$context = $input->get('option');

		if (($this->checkContext($context) && 'file.upload' == $input->get('task')))
		{
			$input_files = new Files();
			$file_data   = $input_files->get('Filedata', array(), 'raw');

			foreach ($file_data as $key => $file)
			{
				if (!empty($file['name']))
				{
					// UTF8 to ASCII
					$file['name'] = Transliterate::utf8_latin_to_ascii($file['name']);

					// Make image name safe with core function
					$file['name'] = File::makeSafe($file['name']);

					// Replace whitespaces with underscores
					$file['name'] = preg_replace('@\s+@', '-', $file['name']);

					// Make a string lowercase
					$file['name'] = strtolower($file['name']);

					// Set the name back directly to the global FILES variable
					$_FILES['Filedata']['name'][$key] = $file['name'];
				}
			}
		}
	}


	/**
	 * Resizes images using Joomla! core class Image
	 *
	 * @param object      $object
	 * @param string      $objectPath
	 * @param int         $width
	 * @param int         $height
	 * @param bool        $multiresize
	 * @param bool|string $multisizeSuffix
	 * @param bool|int    $multisizeScaleMethod
	 *
	 * @return string|string
	 * @since 1.0
	 */
	private function resizeImage($object, $objectPath, $width = 0, $height = 0, $multiresize = true, $multisizeSuffix = false, $multisizeScaleMethod = false)
	{
		if (in_array($object->type, $this->allowedMimeTypes))
		{

			$imageObject = new Image($objectPath);
			$scaleMethod = $this->scaleMethod;

			$imageInformation = $this->getImageInformation($object->type);

			if ((bool) $this->params->get('keepOriginal'))
			{
				$filePath = File::stripExt($object->filepath) . '_original.' . File::getExt($object->filepath);
				$imageObject->toFile($filePath, $imageInformation['type']);
			}

			if (!empty($multisizeScaleMethod) && in_array($multisizeScaleMethod, [1, 2, 3, 4, 5, 6]))
			{
				$scaleMethod = (int) $multisizeScaleMethod;
			}

			if ($scaleMethod == 4)
			{
				$imageObject->crop($width, $height, null, null, false);
			}
			elseif ($scaleMethod == 5)
			{
				$imageObject->cropResize($width, $height, false);
			}
			else
			{
				$imageObject->resize($width, $height, false, $scaleMethod);
			}

			if (empty($this->enlargeImages))
			{
				$imageProperties = $imageObject->getImageFileProperties($objectPath);

				if ($imageObject->getWidth() >= $imageProperties->width || $imageObject->getHeight() >= $imageProperties->height)
				{
					return false;
				}
			}

			$image_save_path = ($multiresize ? $this->getThumbnailPath($objectPath, $imageObject->getWidth(), $imageObject->getHeight(), $multisizeSuffix) : $objectPath);

			$imageObject->toFile($image_save_path, $imageInformation['type'], array('quality' => $imageInformation['quality']));

			return $image_save_path;
		}

		return false;
	}


	/**
	 * Set the quality of JPG images - 0 to 100
	 *
	 * @since 1.0
	 */
	private function setQualityJpg()
	{
		$this->qualityJpg = (int) $this->params->get('quality_jpg', 80);

		// Set default value if entered value is out of range
		if ($this->qualityJpg < 0 || $this->qualityJpg > 100)
		{
			$this->qualityJpg = 80;
		}
	}

	/**
	 * Sets the compression level of PNG images - 0 to 9
	 *
	 * @param null
	 *
	 * @since 1.0
	 */
	private function setCompressionPng()
	{
		$this->compressionPng = (int) $this->params->get('compression_png', 6);

		// Set default value if entered value is out of range
		if (0 > $this->compressionPng || 9 < $this->compressionPng)
		{
			$this->compressionPng = 6;
		}
	}

	/**
	 * Enqueue success message
	 *
	 * @param int    $in      size of input file (byte)
	 * @param int    $out     size of output
	 * @param string $service compression service used
	 *
	 * @return null
	 * @throws Exception
	 * @since 1.0
	 */
	protected function successMessage($in, $out, $service = '')
	{
		if (0 == $in)
		{
			return;
		}
		$service     = empty ($service) ? '' : '. ' . Text::_('PLG_PXLCOMPRESSOR_COMPRESSION_SERVICE_USED') . ' ' . $service;
		$compression = round((1 - ($out / $in)) * 100, 2);
		Factory::getApplication()->enqueueMessage(
			Text::_('PLG_PXLCOMPRESSOR_COMPRESSED_PERCENT') . ' ' . $compression
			. '% ( ' . ceil($in / 1024) . ' kiB → ' . ceil($out / 1024) . ' kiB )'
			. $service
		);
	}

	/**
	 * Check context of current component
	 *
	 * @param String $context
	 *
	 * @return bool
	 * @since 1.4
	 */
	private function checkContext($context)
	{
		return in_array($context, $this->triggerOn);
	}
}
