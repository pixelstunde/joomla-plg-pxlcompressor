<?php
/**
 * @Copyright
 * @package        PXLCompressor
 * @author         Christian Friedemann <c.friedemann@pixelstun.de>, Viktor Vogel <admin@kubik-rubik.de>
 * @version        1.2 - 2019-03-11
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
defined('_JEXEC') or die('Restricted access');

require_once(__DIR__ . '/libs/ilovepdf/init.php');

use Ilovepdf\Ilovepdf;

class PlgSystemPxlcompressor extends JPlugin
{
	protected $quality_jpg;
	protected $compression_png;
	protected $scale_method;
	protected $multisize_path;
	protected $enlarge_images;
	protected $allowed_mime_types = array('image/jpeg', 'image/png', 'image/gif');
	protected $compressImages = false;
	protected $compressPDF = false;

	function __construct(&$subject, $config)
	{
		parent::__construct($subject, $config);
		$this->compressImages = (bool) $this->params->get('compressExternal');
		$this->compressPDF    = (bool) $this->params->get('compressPDF');
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
	 * @since 1.0
	 * @throws Exception
	 */
	public function onContentAfterSave($context, $object, $state)
	{
		//load language
		$this->loadLanguage('', JPATH_BASE);

		$triggerOn = ['com_media.file', 'com_jce.file'];

		if (in_array($context, $triggerOn) && $this->checkObject($object) && $state == true)
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
				$multisizes_lines = array();
				$this->createThumbnailFolder($object->filepath);
				$multisizes = array_map('trim', explode("\n", $multisizes));

				foreach ($multisizes as $multisizes_line)
				{
					$multisizes_lines[] = array_map('trim', explode('|', $multisizes_line));
				}

				foreach ($multisizes_lines as $multisize_line)
				{
					// At least one value has to be set and not negative to execute the resizing process
					if ((!empty($multisize_line[0]) && $multisize_line[0] >= 0) || (!empty($multisize_line[1]) && $multisize_line[1] >= 0))
					{
						$multisize_suffix = false;

						if (!empty($multisize_line[2]))
						{
							$multisize_suffix = htmlspecialchars($multisize_line[2]);
						}

						$multisize_scale_method = $this->scale_method;

						if (!empty($multisize_line[3]))
						{
							if (in_array((int) $multisize_line[3], array(1, 2, 3, 4, 5, 6)))
							{
								$multisize_scale_method = (int) $multisize_line[3];
							}
						}

						$image_path = $this->resizeImage($object, $object->filepath, $multisize_line[0], $multisize_line[1], true, $multisize_suffix, $multisize_scale_method);

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

		$triggerOn = ['com_media.file', 'com_jce.file'];

		if (in_array($context, $triggerOn) && $this->checkObject($object) && true == $state)
		{
			$this->setQualityJpg();
			$this->setCompressionPng();
			$this->scale_method   = (int) $this->params->get('scale_method', 2);
			$this->enlarge_images = (int) $this->params->get('enlarge_images ', 0);
			$width                = (int) $this->params->get('width', 0);
			$height               = (int) $this->params->get('height', 0);

			// At least one value has to be set and not negative to execute the resizing process
			if ((!empty($width) && $width >= 0) || (!empty($height) && $height >= 0))
			{
				if (false !== $this->resizeImage($object, $object->tmp_name, $width, $height, false))
				{
					$this->compressionState = true;
				};
			}
		}
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
				$object->type = mime_content_type($object->filepath);

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

		if (in_array($object->type, $this->allowed_mime_types) && $this->compressImages)
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
			$result = $this->compressFileILovePDF($object);
		}
	}

	/**
	 * Compress file using a service provider
	 *
	 * @param object $object
	 *
	 * @return bool success
	 * @since 1.0
	 * @throws Exception
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
			JFactory::getApplication()->enqueueMessage(JText::_('PLG_PXLCOMPRESSOR_FILESIZE_EXCEEDED'), 'error');

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
			JFactory::getApplication()->enqueueMessage(JText::_('PLG_PXLCOMPRESSOR_NOT_SUPPORTED'), 'error');

			return null;
		}

		$endpoint     = 'http://api.resmush.it/ws.php';
		$externalPath = urlencode($this->externalURL($object->filepath));
		$url          = $endpoint . '?img=' . $externalPath . $metadata;

		$httpInterface = new JHttp;
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
	 * @since 1.0
	 * @throws Exception
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
			JFactory::getApplication()->enqueueMessage(JText::_('PLG_PXLCOMPRESSOR_NOT_SUPPORTED'), 'error');

			return null;
		}

		$endpoint = 'https://api.tinify.com/shrink';
		$apiKey   = $this->params->get('tinyPNGApiKey', '');

		$httpInterface = JHttpFactory::getHttp();
		//html basic authorization
		$httpInterface->setOption('headers.Authorization', 'Basic ' . base64_encode('api:' . $apiKey));
		$response = $httpInterface->post($endpoint, file_get_contents($object->filepath));

		if (200 == $response->code || 201 == $response->code)
		{
			$json = json_decode($response->body);
			if (isset ($json->error))
			{
				JFactory::getApplication()->enqueueMessage(JText::_('PLG_PXLCOMPRESSOR_COMPRESSION_ERROR'), 'error');

				return null;
			}
			else
			{
				//reset iface
				$httpInterface = JHttpFactory::getHttp();
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
		$secret = $this->params->get('ilovepdfSecret', '');
		$public = $this->params->get('ilovepdfPublic', '');
		$error  = '';

		try
		{
			$ilovepdf = new Ilovepdf($public, $secret);
			$myTask   = $ilovepdf->newTask('compress');
			$myTask->addFile($object->filepath);
			$myTask->execute();
			$myTask->download(dirname($object->filepath));
		}
		catch (\Ilovepdf\Exceptions\StartException $e)
		{
			$error .= "An error occured on start: " . $e->getMessage() . " ";
			// Authentication errors
		}
		catch (\Ilovepdf\Exceptions\AuthException $e)
		{
			$error .= "An error occured on auth: " . $e->getMessage() . " ";
			$error .= implode(', ', $e->getErrors());
			// Uploading files errors
		}
		catch (\Ilovepdf\Exceptions\UploadException $e)
		{
			$error .= "An error occured on upload: " . $e->getMessage() . " ";
			$error .= implode(', ', $e->getErrors());
			// Processing files errors
		}
		catch (\Ilovepdf\Exceptions\ProcessException $e)
		{
			$error .= "An error occured on process: " . $e->getMessage() . " ";
			$error .= implode(', ', $e->getErrors());
			// Downloading files errors
		}
		catch (\Ilovepdf\Exceptions\DownloadException $e)
		{
			$error .= "An error occured on process: " . $e->getMessage() . " ";
			$error .= implode(', ', $e->getErrors());
			// Other errors (as connexion errors and other)
		}
		catch (\Exception $e)
		{
			$error .= "An error occured: " . $e->getMessage();
		}
		if (!empty($error))
		{
			JFactory::getApplication()->enqueueMessage(JText::_('PLG_PXLCOMPRESSOR_COMPRESSION_ERROR') . $error, 'error');
		}
		else
		{
			$this->successMessage($object->size, filesize($object->filepath));
		}
	}


	/**
	 * Creates thumbnail folder if it does not exist yet
	 *
	 * @param $image_path_original
	 *
	 * @return null
	 * @since 1.0
	 */
	private function createThumbnailFolder($image_path_original)
	{
		$this->multisize_path = dirname($image_path_original);
		$multisize_path       = $this->params->get('multisize_path', '');

		if (!empty($multisize_path))
		{
			$this->multisize_path .= '/' . $multisize_path;
		}

		if (!JFolder::exists($this->multisize_path))
		{
			JFolder::create($this->multisize_path);
		}
	}

	/**
	 * Enqueue error message
	 *
	 * @param string $service compression service used
	 *
	 * @return null
	 * @since 1.0
	 * @throws Exception
	 */

	protected function errorMessage($service)
	{
		JFactory::getApplication()->enqueueMessage(
			JText::_('PLG_PXLCOMPRESSOR_COMPRESSION_SERVICE_FAILED') . ' ' . $service
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
		return JURI::root() . substr(str_replace(JPATH_ROOT, '', $localFile), 1);
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
		$image_information = array('type' => IMAGETYPE_JPEG, 'quality' => $this->quality_jpg);

		if ('image/gif' == $mime_type)
		{
			$image_information = array('type' => IMAGETYPE_GIF, 'quality' => '');
		}
		else if ('image/png' == $mime_type)
		{
			$image_information = array('type' => IMAGETYPE_PNG, 'quality' => $this->compression_png);
		}
		//not supported by JImage (yet)
		//@todo still true?
		//else if ( 'image/bmp' == $mime_type){
		//	$image_information = array( 'type' => IMAGETYPE_BMP, 'quality' => $this->quality_jpg );
		//}
		//else if ( 'image/tiff' == $mime_type ){
		//	//assume intel byte order
		//	$image_information = array( 'type' => IMAGETYPE_TIFF_II, 'quality' => $this->quality_jpg );
		//}
		return $image_information;
	}


	/**
	 * Creates the full path, including the name of the thumbnail
	 *
	 * @param $image_path_original
	 * @param $width
	 * @param $height
	 * @param $multisize_suffix
	 *
	 * @return string
	 * @since 1.0
	 */
	private function getThumbnailPath($image_path_original, $width, $height, $multisize_suffix = false)
	{
		$image_extension     = '.' . JFile::getExt(basename($image_path_original));
		$image_name_original = basename($image_path_original, $image_extension);

		$image_name_suffix = $width . 'w';

		if (!empty($multisize_suffix))
		{
			$image_name_suffix = $multisize_suffix;
		}

		return $this->multisize_path . '/' . $image_name_original . '-' . $image_name_suffix . $image_extension;
	}

	/**
	 * Creates safe image names for the Media Manager
	 *
	 * @since 1.0
	 * @throws Exception
	 */
	private function makeNameSafe()
	{
		$input = JFactory::getApplication()->input;

		if (
		('com_media' == $input->get('option') && 'file.upload' == $input->get('task'))
		)
		{
			$input_files = new JInputFiles();
			$file_data   = $input_files->get('Filedata', array(), 'raw');

			foreach ($file_data as $key => $file)
			{
				if (!empty($file['name']))
				{
					// UTF8 to ASCII
					$file['name'] = JLanguageTransliterate::utf8_latin_to_ascii($file['name']);

					// Make image name safe with core function
					jimport('joomla.filesystem.file');
					$file['name'] = JFile::makeSafe($file['name']);

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
	 * Resizes images using Joomla! core class JImage
	 *
	 * @param object      $object
	 * @param string      $object_path
	 * @param int         $width
	 * @param int         $height
	 * @param bool        $multiresize
	 * @param bool|string $multisize_suffix
	 * @param bool|int    $multisize_scale_method
	 *
	 * @return string|string
	 * @since 1.0
	 */
	private function resizeImage($object, $object_path, $width = 0, $height = 0, $multiresize = true, $multisize_suffix = false, $multisize_scale_method = false)
	{
		if (in_array($object->type, $this->allowed_mime_types))
		{
			$image_object      = new JImage($object_path);
			$scale_method      = $this->scale_method;
			$image_information = $this->getImageInformation($object->type);

			if ((bool) $this->params->get('keepOriginal'))
			{
				$filePath = JFile::stripExt($object->filepath) . '_original.' . JFile::getExt($object->filepath);
				$image_object->toFile($filePath, $image_information['type']);
			}

			if (!empty($multisize_scale_method) AND in_array($multisize_scale_method, array(1, 2, 3, 4, 5, 6)))
			{
				$scale_method = (int) $multisize_scale_method;
			}

			if ($scale_method == 4)
			{
				$image_object->crop($width, $height, null, null, false);
			}
			elseif ($scale_method == 5)
			{
				$image_object->cropResize($width, $height, false);
			}
			else
			{
				$image_object->resize($width, $height, false, $scale_method);
			}

			if (empty($this->enlarge_images))
			{
				$image_properties = $image_object->getImageFileProperties($object_path);

				if ($image_object->getWidth() >= $image_properties->width || $image_object->getHeight() >= $image_properties->height)
				{
					return false;
				}
			}

			$image_save_path = ($multiresize ? $this->getThumbnailPath($object_path, $image_object->getWidth(), $image_object->getHeight(), $multisize_suffix) : $object_path);

			$image_object->toFile($image_save_path, $image_information['type'], array('quality' => $image_information['quality']));

			return $image_save_path;
		}

		return false;
	}


	/**
	 * Set the quality of JPG images - 0 to 100
	 *
	 * @return null
	 * @since 1.0
	 */
	private function setQualityJpg()
	{
		$this->quality_jpg = (int) $this->params->get('quality_jpg', 80);

		// Set default value if entered value is out of range
		if ($this->quality_jpg < 0 || $this->quality_jpg > 100)
		{
			$this->quality_jpg = 80;
		}
	}

	/**
	 * Sets the compression level of PNG images - 0 to 9
	 *
	 * @param null
	 *
	 * @return null
	 * @since 1.0
	 */
	private function setCompressionPng()
	{
		$this->compression_png = (int) $this->params->get('compression_png', 6);

		// Set default value if entered value is out of range
		if (0 > $this->compression_png || 9 < $this->compression_png)
		{
			$this->compression_png = 6;
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
	 * @since 1.0
	 * @throws Exception
	 */
	protected function successMessage($in, $out, $service = '')
	{
		if (0 == $in)
		{
			return;
		}
		$service     = empty ($service) ? '' : '. ' . JText::_('PLG_PXLCOMPRESSOR_COMPRESSION_SERVICE_USED') . ' ' . $service;
		$compression = round((1 - ($out / $in)) * 100, 2);
		JFactory::getApplication()->enqueueMessage(
			JText::_('PLG_PXLCOMPRESSOR_COMPRESSED_PERCENT') . ' ' . $compression
			. '% ( ' . ceil($in / 1024) . ' kiB → ' . ceil($out / 1024) . ' kiB )'
			. $service
		);
	}

}
