<?php
/**
 *  Adds background fill to Joomla Image resize function
 * @since     1.5
 * @copyright Christian Friedemann
 * @license   GPL v3
 */

use Joomla\Image\Image;

class pxlImage extends Image
{
	/**
	 * Method to resize the current image.
	 *
	 * @param mixed   $width           The width of the resized image in pixels or a percentage.
	 * @param mixed   $height          The height of the resized image in pixels or a percentage.
	 * @param boolean $createNew       If true the current image will be cloned, resized and returned; else
	 *                                 the current image will be resized and returned.
	 * @param integer $scaleMethod     Which method to use for scaling
	 * @param string  $fillColor       Which method to use for scaling
	 *
	 * @return  Image
	 *
	 * @throws  \LogicException
	 * @since   1.4
	 */
	public function resize($width, $height, $createNew = true, $scaleMethod = self::SCALE_INSIDE, string $fillColor = '')
	{
		// Sanitize width.
		$width = $this->sanitizeWidth($width, $height);

		// Sanitize height.
		$height = $this->sanitizeHeight($height, $width);

		// Prepare the dimensions for the resize operation.
		$dimensions = $this->prepareDimensions($width, $height, $scaleMethod);

		// Instantiate offset.
		$offset    = new \stdClass;
		$offset->x = $offset->y = 0;

		// Center image if needed and create the new truecolor image handle.
		if ($scaleMethod == self::SCALE_FIT)
		{
			// Get the offsets
			$offset->x = round(($width - $dimensions->width) / 2);
			$offset->y = round(($height - $dimensions->height) / 2);

			$handle = imagecreatetruecolor($width, $height);

			// Make image transparent, otherwise canvas outside initial image would default to black
			if (!$this->isTransparent())
			{
				$transparency = imagecolorallocatealpha($this->getHandle(), 0, 0, 0, 127);
				imagecolortransparent($this->getHandle(), $transparency);
			}
		}
		else
		{
			$handle = imagecreatetruecolor($dimensions->width, $dimensions->height);
		}

		// Allow transparency for the new image handle.
		imagealphablending($handle, false);
		imagesavealpha($handle, true);
		if (!empty($fillColor))
		{
			$fillColor = self::rgbaToArray($fillColor);
			$color     = imagecolorallocatealpha($handle, (int) $fillColor[0], (int) $fillColor[1], (int) $fillColor[2], 127 - (int) ($fillColor[3] * 127));
			imagecolortransparent($handle, $color);
			imagefilledrectangle($handle, 0, 0, $width, $height, $color);
			imagealphablending($handle, true);
		}
		if ($this->isTransparent())
		{
			// Get the transparent color values for the current image.
			$rgba  = imagecolorsforindex($this->getHandle(), imagecolortransparent($this->getHandle()));
			$color = imagecolorallocatealpha($handle, $rgba['red'], $rgba['green'], $rgba['blue'], $rgba['alpha']);

			// Set the transparent color values for the new image.
			imagecolortransparent($handle, $color);
		}
		if (empty($fillColor))
		{
			imagefill($handle, 0, 0, $color);
		}


		if (!$this->generateBestQuality)
		{
			imagecopyresized(
				$handle,
				$this->getHandle(),
				$offset->x,
				$offset->y,
				0,
				0,
				$dimensions->width,
				$dimensions->height,
				$this->getWidth(),
				$this->getHeight()
			);
		}
		else
		{
			// Use resampling for better quality
			imagecopyresampled(
				$handle,
				$this->getHandle(),
				$offset->x,
				$offset->y,
				0,
				0,
				$dimensions->width,
				$dimensions->height,
				$this->getWidth(),
				$this->getHeight()
			);
		}

		// If we are resizing to a new image, create a new JImage object.
		if ($createNew)
		{
			// @codeCoverageIgnoreStart
			return new static($handle);

			// @codeCoverageIgnoreEnd
		}

		// Swap out the current handle for the new image handle.
		$this->destroy();

		$this->handle = $handle;

		return $this;
	}

	/**
	 * Get rgb color from hex string
	 *
	 * @param string $rgba
	 *
	 * @return int[]
	 * @since 1.4
	 */
	protected static function rgbaToArray(string $rgba)
	{
		return explode(',', substr($rgba, 5, -1));
	}
}
